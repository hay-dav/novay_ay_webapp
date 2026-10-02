<?php

namespace App\Http\Controllers\Api;

use App\Models\ArticleLesson;
use App\Models\ArticleLessonBlock;
use App\Services\MediaStorage;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class ArticleLessonController extends Controller
{
    public function index(Request $request, MediaStorage $media)
    {
        $section = $this->section($request);
        $isStaff = in_array($request->user()->role->value, ['admin', 'curator'], true);
        $isPaid = $isStaff || $request->user()->access_status === 'paid';

        $lessons = ArticleLesson::query()
            ->where('section', $section)
            ->whereNotNull('published_at')
            ->when($section !== 'news' && ! $isPaid, fn ($query) => $query->where('access_level', 'free'))
            ->with('blocks')
            ->when($section !== 'news', fn ($query) => $query->orderBy('sort_order'))
            ->latest('published_at')
            ->orderByDesc('id')
            ->when($section === 'news' && $request->integer('limit') > 0, fn ($query) => $query->limit(min(100, $request->integer('limit'))))
            ->get();

        $lessons->each(function (ArticleLesson $lesson) use ($media): void {
            $lesson->blocks->each(function (ArticleLessonBlock $block) use ($media): void {
                if ($block->image_path) {
                    $block->setAttribute('image_path', $media->publicUrl($block->image_path));
                }
                if ($block->video_path) {
                    $block->setAttribute('video_path', $media->secureCdnUrl($block->video_path, 21_600));
                }
            });
            $coverUrl = $lesson->image_path ? $media->publicUrl($lesson->image_path) : null;
            $lesson->setAttribute('cover_image_path', $coverUrl);
            $lesson->setAttribute('preview_image_path', $coverUrl ?? $lesson->blocks->firstWhere('type', 'image')?->image_path);
        });

        return response()->json(['data' => $lessons]);
    }

    public function store(Request $request, MediaStorage $media)
    {
        $this->authorizeEditor($request);
        $validated = $this->validateLesson($request);
        $blocks = $this->decodeBlocks($validated['blocks'], $validated['section']);
        $coverPath = $request->hasFile('cover')
            ? $media->storeOptimized($request->file('cover'), 'article-lessons/covers', 'image', true)
            : null;

        $lesson = ArticleLesson::query()->create([
            'title' => $validated['title'],
            'excerpt' => $validated['excerpt'] ?: null,
            'body' => '',
            'image_path' => $coverPath,
            'access_level' => $validated['section'] === 'news' ? 'free' : $validated['access_level'],
            'author_id' => $request->user()->id,
            'published_at' => now(),
            'sort_order' => (ArticleLesson::query()->where('section', $validated['section'])->min('sort_order') ?? 0) - 1,
            'section' => $validated['section'],
        ]);

        $this->createBlocks($request, $media, $lesson, $blocks);

        return response()->json(['data' => $this->presentLesson($lesson->fresh()->load('blocks'), $media)], 201);
    }

    public function update(Request $request, ArticleLesson $lesson, MediaStorage $media)
    {
        $this->authorizeEditor($request);
        $validated = $this->validateLesson($request);
        abort_unless($lesson->section === $validated['section'], 422, 'Material section cannot be changed.');
        $blocks = $this->decodeBlocks($validated['blocks'], $validated['section']);
        $existingBlocks = $lesson->blocks()->get()->keyBy('id');
        $previousPaths = $existingBlocks->flatMap(fn (ArticleLessonBlock $block) => [$block->image_path, $block->video_path])->filter();
        $previousCoverPath = $lesson->image_path;
        $coverPath = $request->hasFile('cover')
            ? $media->storeOptimized($request->file('cover'), 'article-lessons/covers', 'image', true)
            : $previousCoverPath;

        $lesson->update([
            'title' => $validated['title'],
            'excerpt' => $validated['excerpt'] ?: null,
            'access_level' => $validated['section'] === 'news' ? 'free' : $validated['access_level'],
            'image_path' => $coverPath,
        ]);

        if ($coverPath !== $previousCoverPath) {
            $media->delete($previousCoverPath);
        }

        $newBlocks = $this->buildBlocks($request, $media, $blocks, $existingBlocks);
        $lesson->blocks()->delete();
        $lesson->blocks()->createMany($newBlocks);

        $usedPaths = collect($newBlocks)->flatMap(fn (array $block) => [$block['image_path'], $block['video_path']])->filter();
        $previousPaths->reject(fn (string $path) => $usedPaths->contains($path))->each(fn (string $path) => $media->delete($path));

        return response()->json(['data' => $this->presentLesson($lesson->fresh()->load('blocks'), $media)]);
    }

    public function reorder(Request $request)
    {
        $this->authorizeEditor($request);
        $validated = $request->validate([
            'section' => ['required', 'in:lessons,recipes,knowledge,news'],
            'lesson_ids' => ['required', 'array', 'min:1'],
            'lesson_ids.*' => ['required', 'integer', 'distinct', 'exists:article_lessons,id'],
        ]);

        $sectionIds = ArticleLesson::query()->where('section', $validated['section'])->pluck('id');
        abort_unless($sectionIds->count() === count($validated['lesson_ids'])
            && $sectionIds->diff($validated['lesson_ids'])->isEmpty(), 422, 'Invalid material order.');

        DB::transaction(function () use ($validated): void {
            foreach ($validated['lesson_ids'] as $sortOrder => $lessonId) {
                ArticleLesson::query()->whereKey($lessonId)->update(['sort_order' => $sortOrder]);
            }
        });

        return response()->noContent();
    }

    public function destroy(Request $request, ArticleLesson $lesson, MediaStorage $media)
    {
        $this->authorizeEditor($request);
        $lesson->blocks()->get()->each(function (ArticleLessonBlock $block) use ($media): void {
            $media->delete($block->image_path);
            $media->delete($block->video_path);
        });
        $media->delete($lesson->image_path);
        $lesson->delete();

        return response()->noContent();
    }

    private function validateLesson(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'section' => ['required', 'in:lessons,recipes,knowledge,news'],
            'excerpt' => ['nullable', 'string', 'max:500'],
            'access_level' => ['required', 'in:free,paid'],
            'blocks' => ['required', 'json'],
            'cover' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'images' => ['nullable', 'array'],
            'images.*' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'videos' => ['nullable', 'array'],
            'videos.*' => ['nullable', 'file', 'mimetypes:video/mp4,video/webm,video/quicktime,video/x-m4v', 'max:2097152'],
        ]);
    }

    private function decodeBlocks(string $blocks, string $section): array
    {
        $decoded = json_decode($blocks, true, 512, JSON_THROW_ON_ERROR);
        abort_unless(is_array($decoded) && count($decoded) > 0 && count($decoded) <= 100, 422, 'Add at least one lesson block.');
        if ($section !== 'lessons') {
            abort_if(collect($decoded)->contains(fn (array $block) => ($block['type'] ?? null) === 'video'), 422, 'Video blocks are not available in this section.');
        }

        return $decoded;
    }

    private function createBlocks(Request $request, MediaStorage $media, ArticleLesson $lesson, array $blocks): void
    {
        $lesson->blocks()->createMany($this->buildBlocks($request, $media, $blocks, collect()));
    }

    private function buildBlocks(Request $request, MediaStorage $media, array $blocks, $existingBlocks): array
    {
        return collect($blocks)->map(function (array $block, int $index) use ($request, $media, $existingBlocks): array {
            $type = $block['type'] ?? null;
            abort_unless(in_array($type, ['text', 'image', 'video'], true), 422, 'Unsupported lesson block type.');
            $content = trim((string) ($block['content'] ?? ''));
            $imagePath = null;
            $videoPath = null;

            if ($type === 'text') {
                abort_unless($content !== '' && mb_strlen($content) <= 30000, 422, 'Fill in the text block.');
            } elseif ($type === 'image') {
                $existing = $existingBlocks->get((int) ($block['id'] ?? 0));
                if ($existing?->type === 'image' && ! ($block['replace_image'] ?? false)) {
                    $imagePath = $existing->image_path;
                } else {
                    $image = $request->file('images.'.$index);
                    abort_unless($image, 422, 'Add an image to the image block.');
                    $imagePath = $media->storeOptimized($image, 'article-lessons', 'image', true);
                }
            } else {
                $existing = $existingBlocks->get((int) ($block['id'] ?? 0));
                if ($existing?->type === 'video' && ! ($block['replace_video'] ?? false)) {
                    $videoPath = $existing->video_path;
                } else {
                    $video = $request->file('videos.'.$index);
                    abort_unless($video, 422, 'Add a video to the video block.');
                    $videoPath = $media->storeOptimized($video, 'article-lessons/videos', 'video');
                }
            }

            return [
                'type' => $type,
                'content' => $type === 'text' ? $content : null,
                'image_path' => $imagePath,
                'video_path' => $videoPath,
                'sort_order' => $index,
            ];
        })->all();
    }

    private function presentLesson(ArticleLesson $lesson, MediaStorage $media): ArticleLesson
    {
        $lesson->blocks->each(function (ArticleLessonBlock $block) use ($media): void {
            if ($block->image_path) {
                $block->setAttribute('image_path', $media->publicUrl($block->image_path));
            }
            if ($block->video_path) {
                $block->setAttribute('video_path', $media->secureCdnUrl($block->video_path, 21_600));
            }
        });
        $coverUrl = $lesson->image_path ? $media->publicUrl($lesson->image_path) : null;
        $lesson->setAttribute('cover_image_path', $coverUrl);
        $lesson->setAttribute('preview_image_path', $coverUrl ?? $lesson->blocks->firstWhere('type', 'image')?->image_path);

        return $lesson;
    }

    private function authorizeEditor(Request $request): void
    {
        abort_unless(in_array($request->user()->role->value, ['admin', 'curator'], true), 403);
    }

    private function section(Request $request): string
    {
        return $request->validate(['section' => ['nullable', 'in:lessons,recipes,knowledge,news']])['section'] ?? 'lessons';
    }
}
