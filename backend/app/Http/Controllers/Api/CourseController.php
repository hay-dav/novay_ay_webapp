<?php

namespace App\Http\Controllers\Api;

use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Lesson;
use App\Models\Notification;
use App\Models\User;
use App\Services\MediaStorage;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

class CourseController extends Controller
{
    public function materials(Request $request, MediaStorage $media)
    {
        $isPaid = $request->user()->access_status === 'paid';

        $materials = Lesson::query()
            ->whereHas('module.course', function ($query) use ($isPaid): void {
                $query->where('status', 'published');
                if (! $isPaid) {
                    $query->where('access_level', 'free');
                }
            })
            ->whereNotNull('published_at')
            ->with('module.course:id,title,slug')
            ->latest()
            ->limit(30)
            ->get()
            ->map(fn (Lesson $lesson) => [
                'id' => $lesson->id,
                'course_id' => $lesson->module?->course?->id,
                'course_title' => $lesson->module?->course?->title,
                'course_slug' => $lesson->module?->course?->slug,
                'title' => $lesson->title,
                'description' => $lesson->description,
                'type' => $lesson->type,
                'video_path' => $lesson->video_path ? $media->secureCdnUrl($lesson->video_path) : null,
                'duration_seconds' => $lesson->duration_seconds,
                'is_preview' => $lesson->is_preview,
                'created_at' => $lesson->created_at,
            ]);

        return response()->json(['data' => $materials]);
    }

    public function index(Request $request)
    {
        $isPaid = $request->user()->access_status === 'paid';

        return response()->json([
            'data' => Course::query()
                ->where('status', 'published')
                ->when(! $isPaid, fn ($query) => $query->where('access_level', 'free'))
                ->latest()
                ->get(),
        ]);
    }

    public function show(Request $request, string $slug, MediaStorage $media)
    {
        $course = Course::query()->with('modules.lessons')->where('slug', $slug)->firstOrFail();
        abort_if($course->access_level === 'paid' && $request->user()->access_status !== 'paid', 403);

        $lessons = $course->modules->flatMap->lessons->values();
        $lessons->each(function (Lesson $lesson) use ($media): void {
            if ($lesson->video_path) {
                $lesson->setAttribute('video_path', $media->secureCdnUrl($lesson->video_path));
            }
        });

        return response()->json(['course' => $course, 'lessons' => $lessons]);
    }

    public function store(Request $request)
    {
        abort_unless(in_array($request->user()->role->value, ['curator', 'trainer', 'admin'], true), 403);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'price_cents' => ['required', 'integer', 'min:0'],
            'level' => ['required', 'string', 'max:64'],
            'status' => ['required', 'in:draft,published,archived'],
            'access_level' => ['nullable', 'in:free,paid'],
            'sequential_access' => ['nullable', 'boolean'],
        ]);

        $course = Course::query()->create([
            ...$validated,
            'trainer_id' => $request->user()->id,
            'slug' => Str::slug($validated['title']).'-'.Str::lower(Str::random(6)),
            'currency' => 'RUB',
        ]);

        return response()->json(['data' => $course], 201);
    }

    public function storeMaterial(Request $request, MediaStorage $media)
    {
        abort_unless(in_array($request->user()->role->value, ['curator', 'trainer', 'admin'], true), 403);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'file' => ['nullable', 'file', 'max:512000'],
        ]);

        $course = Course::query()
            ->where('status', 'published')
            ->where('access_level', 'paid')
            ->latest()
            ->first()
            ?? Course::query()->where('status', 'published')->latest()->firstOrFail();

        $module = CourseModule::query()->firstOrCreate(
            ['course_id' => $course->id, 'title' => 'Материалы'],
            ['sort_order' => (int) $course->modules()->max('sort_order') + 1],
        );

        $uploadedFile = $request->file('file');
        $filePath = null;
        $fileMime = $uploadedFile?->getMimeType();
        if ($uploadedFile) {
            $filePath = $media->store($uploadedFile, 'course-materials');
        }

        $type = str_starts_with((string) $fileMime, 'video/') ? 'video' : 'text';

        $lesson = Lesson::query()->create([
            'module_id' => $module->id,
            'title' => $validated['title'],
            'description' => $validated['description'],
            'type' => $type,
            'video_path' => $type === 'video' ? $filePath : null,
            'duration_seconds' => 0,
            'sort_order' => (int) $module->lessons()->max('sort_order') + 1,
            'published_at' => now(),
        ]);

        User::query()
            ->where('role', 'client')
            ->where('access_status', 'paid')
            ->each(fn (User $user) => Notification::query()->create([
                'user_id' => $user->id,
                'type' => 'lesson',
                'title' => 'Добавлен новый материал',
                'body' => $lesson->title.' доступен в курсе «'.$course->title.'».',
                'data' => ['course_id' => $course->id, 'lesson_id' => $lesson->id],
            ]));

        return response()->json(['data' => $lesson->load('module')], 201);
    }
}
