<?php

namespace App\Http\Controllers\Api;

use App\Jobs\OptimizeStoredMedia;
use App\Models\ArticleLesson;
use App\Models\ArticleLessonBlock;
use App\Services\MediaStorage;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ArticleLessonController extends Controller
{
    public function index(Request $request, MediaStorage $media)
    {
        $isStaff = in_array($request->user()->role->value, ['admin', 'curator', 'trainer'], true);
        $isPaid = $isStaff || $request->user()->access_status === 'paid';

        $lessons = ArticleLesson::query()
            ->whereNotNull('published_at')
            ->when(! $isPaid, fn ($query) => $query->where('access_level', 'free'))
            ->with('blocks')
            ->latest('published_at')
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
            $lesson->setAttribute('preview_image_path', $lesson->blocks->firstWhere('type', 'image')?->image_path ?? $lesson->image_path);
        });

        return response()->json(['data' => $lessons]);
    }

    public function store(Request $request, MediaStorage $media)
    {
        $this->authorizeEditor($request);
        $validated = $this->validateLesson($request);
        $blocks = $this->decodeBlocks($validated['blocks']);

        $lesson = ArticleLesson::query()->create([
            'title' => $validated['title'],
            'excerpt' => $validated['excerpt'] ?: null,
            'body' => '',
            'image_path' => null,
            'access_level' => $validated['access_level'],
            'author_id' => $request->user()->id,
            'published_at' => now(),
        ]);

        $this->createBlocks($request, $media, $lesson, $blocks);

        return response()->json(['data' => $this->presentLesson($lesson->fresh()->load('blocks'), $media)], 201);
    }

    public function update(Request $request, ArticleLesson $lesson, MediaStorage $media)
    {
        $this->authorizeEditor($request);
        $validated = $this->validateLesson($request);
        $blocks = $this->decodeBlocks($validated['blocks']);
        $existingBlocks = $lesson->blocks()->get()->keyBy('id');
        $previousPaths = $existingBlocks->flatMap(fn (ArticleLessonBlock $block) => [$block->image_path, $block->video_path])->filter();

        $lesson->update([
            'title' => $validated['title'],
            'excerpt' => $validated['excerpt'] ?: null,
            'access_level' => $validated['access_level'],
        ]);

        $newBlocks = $this->buildBlocks($request, $media, $blocks, $existingBlocks);
        $lesson->blocks()->delete();
        $createdBlocks = $lesson->blocks()->createMany($newBlocks);
        $this->dispatchOptimizers($createdBlocks, $previousPaths);

        $usedPaths = collect($newBlocks)->flatMap(fn (array $block) => [$block['image_path'], $block['video_path']])->filter();
        $previousPaths->reject(fn (string $path) => $usedPaths->contains($path))->each(fn (string $path) => $media->delete($path));

        return response()->json(['data' => $this->presentLesson($lesson->fresh()->load('blocks'), $media)]);
    }

    public function destroy(Request $request, ArticleLesson $lesson, MediaStorage $media)
    {
        $this->authorizeEditor($request);
        $lesson->blocks()->get()->each(function (ArticleLessonBlock $block) use ($media): void {
            $media->delete($block->image_path);
            $media->delete($block->video_path);
        });
        $lesson->delete();

        return response()->noContent();
    }

    private function validateLesson(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'excerpt' => ['nullable', 'string', 'max:500'],
            'access_level' => ['required', 'in:free,paid'],
            'blocks' => ['required', 'json'],
            'images' => ['nullable', 'array'],
            'images.*' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'videos' => ['nullable', 'array'],
            'videos.*' => ['nullable', 'file', 'mimetypes:video/mp4,video/webm,video/quicktime,video/x-m4v', 'max:2097152'],
        ]);
    }

    private function decodeBlocks(string $blocks): array
    {
        $decoded = json_decode($blocks, true, 512, JSON_THROW_ON_ERROR);
        abort_unless(is_array($decoded) && count($decoded) > 0 && count($decoded) <= 100, 422, 'Add at least one lesson block.');

        return $decoded;
    }

    private function createBlocks(Request $request, MediaStorage $media, ArticleLesson $lesson, array $blocks): void
    {
        $createdBlocks = $lesson->blocks()->createMany($this->buildBlocks($request, $media, $blocks, collect()));
        $this->dispatchOptimizers($createdBlocks, collect());
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
                    $imagePath = $media->store($image, 'article-lessons', true);
                }
            } else {
                $existing = $existingBlocks->get((int) ($block['id'] ?? 0));
                if ($existing?->type === 'video' && ! ($block['replace_video'] ?? false)) {
                    $videoPath = $existing->video_path;
                } else {
                    $video = $request->file('videos.'.$index);
                    abort_unless($video, 422, 'Add a video to the video block.');
                    $videoPath = $media->store($video, 'article-lessons/videos');
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

    private function dispatchOptimizers($blocks, $existingPaths): void
    {
        $blocks->each(function (ArticleLessonBlock $block) use ($existingPaths): void {
            if ($block->image_path && ! $existingPaths->contains($block->image_path)) {
                OptimizeStoredMedia::dispatch(ArticleLessonBlock::class, $block->id, 'image_path', $block->image_path, 'image', true);
            }
            if ($block->video_path && ! $existingPaths->contains($block->video_path)) {
                OptimizeStoredMedia::dispatch(ArticleLessonBlock::class, $block->id, 'video_path', $block->video_path, 'video');
            }
        });
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
        $lesson->setAttribute('preview_image_path', $lesson->blocks->firstWhere('type', 'image')?->image_path ?? $lesson->image_path);

        return $lesson;
    }

    private function authorizeEditor(Request $request): void
    {
        abort_unless(in_array($request->user()->role->value, ['admin', 'curator'], true), 403);
    }
}
