<?php

namespace App\Http\Controllers\Api;

use App\Models\AccessRequest;
use App\Models\Course;
use App\Models\ClientComment;
use App\Models\FoodEntry;
use App\Models\Notification;
use App\Models\NotificationTemplate;
use App\Models\ProgressEntry;
use App\Models\User;
use App\Models\WorkoutCompletion;
use App\Services\MediaStorage;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;

class AdminDashboardController extends Controller
{
    public function index(Request $request, MediaStorage $media)
    {
        abort_unless(in_array($request->user()->role->value, ['curator', 'trainer', 'admin'], true), 403);

        $clientsQuery = User::query()
            ->where('role', 'client')
            ->when(! in_array($request->user()->role->value, ['admin', 'curator'], true), fn ($query) => $query->whereHas(
                'clientProfile',
                fn ($profile) => $profile->where('trainer_id', $request->user()->id),
            ));

        $clientsCount = (clone $clientsQuery)->count();
        $allClientIds = (clone $clientsQuery)->pluck('id');

        $clients = $clientsQuery
            ->with('clientProfile:id,user_id,goal')
            ->latest()
            ->when(! $request->boolean('all_clients'), fn ($query) => $query->limit(50))
            ->get(['id', 'name', 'email', 'phone', 'avatar_path', 'access_status', 'group_name', 'tags', 'access_ends_at', 'created_at']);

        $progressByUser = \App\Models\LessonProgress::query()
            ->selectRaw('user_id, ROUND(AVG(progress_percent)) as progress_percent')
            ->whereIn('user_id', $clients->pluck('id'))
            ->groupBy('user_id')
            ->pluck('progress_percent', 'user_id');

        $clients->each(fn (User $client) => $client->setAttribute(
            'progress_percent',
            (int) ($progressByUser[$client->id] ?? 0),
        ));

        $clients->each(function (User $client) use ($media): void {
            if ($client->avatar_path) {
                $client->setAttribute('avatar_path', $media->secureCdnUrl($client->avatar_path, 3600));
            }
        });

        $completedWorkouts = WorkoutCompletion::query()
            ->selectRaw('user_id, COUNT(*) as completed_workouts_count')
            ->whereIn('user_id', $clients->pluck('id'))
            ->groupBy('user_id')
            ->pluck('completed_workouts_count', 'user_id');

        $clients->each(fn (User $client) => $client->setAttribute(
            'completed_workouts_count',
            (int) ($completedWorkouts[$client->id] ?? 0),
        ));

        $latestMeasurements = ProgressEntry::query()
            ->whereIn('user_id', $clients->pluck('id'))
            ->orderByDesc('measured_on')
            ->orderByDesc('id')
            ->get(['id', 'user_id', 'weight_kg', 'waist_cm', 'hips_cm', 'chest_cm', 'mood', 'comment', 'measured_on'])
            ->unique('user_id')
            ->keyBy('user_id');

        $clients->each(function (User $client) use ($latestMeasurements): void {
            $measurement = $latestMeasurements->get($client->id);
            $client->setAttribute('latest_measurement', $measurement?->only([
                'weight_kg', 'waist_cm', 'hips_cm', 'chest_cm', 'measured_on',
            ]));
        });

        $queueMeasurements = ProgressEntry::query()
            ->whereIn('user_id', $allClientIds)
            ->orderByDesc('measured_on')
            ->orderByDesc('id')
            ->get(['id', 'user_id', 'weight_kg', 'waist_cm', 'mood', 'comment', 'measured_on'])
            ->unique('user_id')
            ->keyBy('user_id');
        $reportClientsById = User::query()
            ->whereIn('id', $queueMeasurements->keys())
            ->get(['id', 'name'])
            ->keyBy('id');
        $reportQueue = $queueMeasurements
            ->sortByDesc('measured_on')
            ->map(function (ProgressEntry $measurement, int $userId) use ($reportClientsById): array {
                return [
                    'client_id' => $userId,
                    'client_name' => $reportClientsById->get($userId)?->name ?? 'Участница',
                    'measured_on' => $measurement->measured_on,
                    'weight_kg' => $measurement->weight_kg,
                    'waist_cm' => $measurement->waist_cm,
                    'mood' => $measurement->mood,
                    'comment' => $measurement->comment,
                ];
            })
            ->values()
            ->take(10);

        return response()->json([
            'data' => [
                'users' => User::query()->count(),
                'paid_users' => User::query()->where('access_status', 'paid')->count(),
                'pending_access' => AccessRequest::query()->where('status', 'pending')->count(),
                'pending_reviews' => $reportQueue->count(),
                'courses' => Course::query()->count(),
                'clients_count' => $clientsCount,
                'clients' => $clients,
                'report_queue' => $reportQueue,
            ],
        ]);
    }

    public function updateUser(Request $request, User $user)
    {
        abort_unless(in_array($request->user()->role->value, ['curator', 'trainer', 'admin'], true), 403);
        $this->assertCanAccessClient($request, $user);

        $user->update($request->validate([
            'access_status' => ['nullable', 'in:free,paid'],
            'group_name' => ['nullable', 'string', 'max:255'],
            'tags' => ['nullable', 'array'],
            'access_ends_at' => ['nullable', 'date'],
            'blocked_at' => ['nullable', 'date'],
            'archived_at' => ['nullable', 'date'],
        ]));

        return response()->json(['data' => $user]);
    }

    public function sendNotification(Request $request)
    {
        abort_unless(in_array($request->user()->role->value, ['trainer', 'admin'], true), 403);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'recipient' => ['required', 'in:all,paid,free'],
            'link_url' => ['nullable', 'string', 'max:255'],
            'save_template' => ['nullable', 'boolean'],
        ]);

        $users = User::query()
            ->where('role', 'client')
            ->when($validated['recipient'] !== 'all', fn ($query) => $query->where('access_status', $validated['recipient']))
            ->get();

        foreach ($users as $user) {
            Notification::query()->create([
                'user_id' => $user->id,
                'type' => 'admin',
                'title' => $validated['title'],
                'body' => $validated['body'],
                'data' => ['link_url' => $validated['link_url'] ?? null],
            ]);
        }

        if (! empty($validated['save_template'])) {
            NotificationTemplate::query()->create([
                'created_by' => $request->user()->id,
                'title' => $validated['title'],
                'body' => $validated['body'],
                'link_url' => $validated['link_url'] ?? null,
            ]);
        }

        return response()->json(['sent' => $users->count()]);
    }

    public function clientDetails(Request $request, User $user)
    {
        abort_unless(in_array($request->user()->role->value, ['curator', 'trainer', 'admin'], true), 403);
        abort_unless($user->role->value === 'client', 404);
        $this->assertCanAccessClient($request, $user);

        $from = Carbon::parse($request->query('from', now()->subDays(13)->toDateString()));
        $to = Carbon::parse($request->query('to', now()->toDateString()));
        $entries = FoodEntry::query()
            ->where('user_id', $user->id)
            ->whereBetween('eaten_on', [$from->toDateString(), $to->toDateString()])
            ->orderByDesc('eaten_on')
            ->orderBy('created_at')
            ->get();
        $measurements = ProgressEntry::query()
            ->where('user_id', $user->id)
            ->whereBetween('measured_on', [$from->toDateString(), $to->toDateString()])
            ->latest('measured_on')
            ->latest('id')
            ->get(['id', 'weight_kg', 'waist_cm', 'hips_cm', 'chest_cm', 'mood', 'comment', 'photo_path', 'measured_on']);

        return response()->json(['data' => [
            'completed_workouts_count' => WorkoutCompletion::query()->where('user_id', $user->id)->count(),
            'food_entries' => $entries,
            'measurements' => $measurements,
            'food_summary' => [
                'calories' => (int) $entries->sum('calories'),
                'protein_g' => (float) $entries->sum('protein_g'),
                'fat_g' => (float) $entries->sum('fat_g'),
                'carbs_g' => (float) $entries->sum('carbs_g'),
            ],
            'comments' => ClientComment::query()
                ->where('client_id', $user->id)
                ->with('author:id,name')
                ->latest()
                ->get(),
        ]]);
    }

    public function storeClientComment(Request $request, User $user)
    {
        abort_unless(in_array($request->user()->role->value, ['curator', 'trainer', 'admin'], true), 403);
        abort_unless($user->role->value === 'client', 404);
        $this->assertCanAccessClient($request, $user);
        $validated = $request->validate(['body' => ['required', 'string', 'max:3000']]);

        $comment = ClientComment::query()->create([
            'client_id' => $user->id,
            'author_id' => $request->user()->id,
            'body' => $validated['body'],
        ]);

        Notification::query()->create([
            'user_id' => $user->id,
            'type' => 'comment',
            'title' => 'Комментарий от '.$request->user()->name,
            'body' => $comment->body,
            'data' => ['comment_id' => $comment->id],
        ]);

        return response()->json(['data' => $comment->load('author:id,name')], 201);
    }

    private function assertCanAccessClient(Request $request, User $client): void
    {
        if (in_array($request->user()->role->value, ['admin', 'curator'], true)) {
            return;
        }

        abort_unless($client->role->value === 'client', 403);
        abort_unless($client->clientProfile?->trainer_id === $request->user()->id, 403);
    }
}
