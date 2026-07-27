<?php

namespace App\Http\Controllers\Api;

use App\Models\ClientProfile;
use App\Models\Course;
use App\Models\Purchase;
use App\Models\ProgressEntry;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class TrainerDashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        abort_unless(in_array($request->user()->role->value, ['curator', 'trainer', 'admin'], true), 403);

        $clientProfiles = ClientProfile::query()
            ->with('user')
            ->when($request->user()->role->value !== 'admin', fn ($query) => $query->where('trainer_id', $request->user()->id))
            ->get();

        $courseIds = Course::query()
            ->when($request->user()->role->value !== 'admin', fn ($query) => $query->where('trainer_id', $request->user()->id))
            ->pluck('id');

        $latestMeasurements = ProgressEntry::query()
            ->whereIn('user_id', $clientProfiles->pluck('user_id'))
            ->orderByDesc('measured_on')
            ->orderByDesc('id')
            ->get(['id', 'user_id', 'weight_kg', 'waist_cm', 'hips_cm', 'chest_cm', 'mood', 'comment', 'measured_on'])
            ->unique('user_id')
            ->keyBy('user_id');

        $reportQueue = $clientProfiles
            ->map(function (ClientProfile $profile) use ($latestMeasurements): ?array {
                $measurement = $latestMeasurements->get($profile->user_id);
                if (! $measurement) {
                    return null;
                }

                return [
                    'client_id' => $profile->user_id,
                    'client_name' => $profile->user?->name,
                    'measured_on' => $measurement->measured_on,
                    'weight_kg' => $measurement->weight_kg,
                    'waist_cm' => $measurement->waist_cm,
                    'mood' => $measurement->mood,
                    'comment' => $measurement->comment,
                ];
            })
            ->filter()
            ->sortByDesc('measured_on')
            ->values()
            ->take(10);

        return response()->json([
            'data' => [
                'clients' => $clientProfiles->count(),
                'revenue_cents' => Purchase::query()->whereIn('course_id', $courseIds)->where('status', 'paid')->sum('amount_cents'),
                'active_courses' => $courseIds->count(),
                'pending_reviews' => $reportQueue->count(),
                'clients_list' => $clientProfiles->map(fn (ClientProfile $profile) => [
                    'id' => $profile->user_id,
                    'name' => $profile->user?->name,
                    'goal' => $profile->goal,
                ])->values(),
                'report_queue' => $reportQueue,
            ],
        ]);
    }
}
