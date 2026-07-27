<?php

namespace App\Http\Controllers\Api;

use App\Models\ArticleLesson;
use App\Models\ArticleLessonBlock;
use App\Services\MediaStorage;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ArticleLessonController extends Controller
{
    public function index(Request $request)
    {
        $isStaff = in_array($request->user()->role->value, ['admin', 'curator', 'trainer'], true);
        $isPaid = $isStaff || $request->user()->access_status === 'paid';

        return response()->json([
            'data' => ArticleLesson::query()
                ->whereNotNull('published_at')
                ->when(! $isPaid, fn ($query) => $query->where('access_level', 'free'))
                ->with('blocks')
                ->latest('published_at')
                ->get()
                ->each(function (ArticleLesson $lesson): void {
                    $media = app(MediaStorage::class);
                    $lesson->blocks->each(function (ArticleLessonBlock $block) use ($media): void {
                        if ($block->image_path) {
                            $block->setAttribute('image_path', $media->publicUrl($block->image_path));
                        }
                    });
                    $lesson->setAttribute('preview_image_path', $lesson->blocks->firstWhere('type', 'image')?->image_path ?? $lesson->image_path);
                }),
        ]);
    }

    public function store(Request $request, MediaStorage $media)
    {
        abort_unless(in_array($request->user()->role->value, ['admin', 'curator'], true), 403);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'excerpt' => ['required', 'string', 'max:500'],
            'access_level' => ['required', 'in:free,paid'],
            'blocks' => ['required', 'json'],
            'images' => ['nullable', 'array'],
            'images.*' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ]);
        $blocks = json_decode($validated['blocks'], true, 512, JSON_THROW_ON_ERROR);
        abort_unless(is_array($blocks) && count($blocks) > 0 && count($blocks) <= 100, 422, 'Добавьте хотя бы один блок урока.');

        $lesson = ArticleLesson::query()->create([
            'title' => $validated['title'],
            'excerpt' => $validated['excerpt'],
            'body' => '',
            'image_path' => null,
            'access_level' => $validated['access_level'],
            'author_id' => $request->user()->id,
            'published_at' => now(),
        ]);

        foreach ($blocks as $index => $block) {
            abort_unless(in_array($block['type'] ?? null, ['text', 'image'], true), 422, 'Недопустимый тип блока.');
            $content = trim((string) ($block['content'] ?? ''));
            $imagePath = null;
            if ($block['type'] === 'text') {
                abort_unless($content !== '' && mb_strlen($content) <= 30000, 422, 'Заполните текстовый блок урока.');
            } else {
                $image = $request->file('images.'.$index);
                abort_unless($image, 422, 'Добавьте фото в блок изображения.');
                $imagePath = $media->store($image, 'article-lessons', true);
            }
            ArticleLessonBlock::query()->create([
                'article_lesson_id' => $lesson->id,
                'type' => $block['type'],
                'content' => $block['type'] === 'text' ? $content : null,
                'image_path' => $imagePath,
                'sort_order' => $index,
            ]);
        }

        $lesson->load('blocks');
        $lesson->setAttribute('preview_image_path', $lesson->blocks->firstWhere('type', 'image')?->image_path);

        return response()->json(['data' => $lesson], 201);
    }

    public function update(Request $request, ArticleLesson $lesson, MediaStorage $media)
    {
        abort_unless(in_array($request->user()->role->value, ['admin', 'curator'], true), 403);
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'excerpt' => ['required', 'string', 'max:500'],
            'access_level' => ['required', 'in:free,paid'],
            'blocks' => ['required', 'json'],
            'images' => ['nullable', 'array'],
            'images.*' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ]);
        $blocks = json_decode($validated['blocks'], true, 512, JSON_THROW_ON_ERROR);
        abort_unless(is_array($blocks) && count($blocks) > 0 && count($blocks) <= 100, 422, 'Добавьте хотя бы один блок урока.');

        $existingBlocks = $lesson->blocks()->get()->keyBy('id');
        $previousImagePaths = $existingBlocks->where('type', 'image')->pluck('image_path')->filter();
        $lesson->update(['title' => $validated['title'], 'excerpt' => $validated['excerpt'], 'access_level' => $validated['access_level']]);
        $newBlocks = [];
        foreach ($blocks as $index => $block) {
            abort_unless(in_array($block['type'] ?? null, ['text', 'image'], true), 422, 'Недопустимый тип блока.');
            $content = trim((string) ($block['content'] ?? ''));
            $imagePath = null;
            if ($block['type'] === 'text') {
                abort_unless($content !== '' && mb_strlen($content) <= 30000, 422, 'Заполните текстовый блок урока.');
            } else {
                $existing = $existingBlocks->get((int) ($block['id'] ?? 0));
                if ($existing?->type === 'image' && ! ($block['replace_image'] ?? false)) {
                    $imagePath = $existing->image_path;
                } else {
                    $image = $request->file('images.'.$index);
                    abort_unless($image, 422, 'Добавьте фото в блок изображения.');
                    $imagePath = $media->store($image, 'article-lessons', true);
                }
            }
            $newBlocks[] = ['type' => $block['type'], 'content' => $block['type'] === 'text' ? $content : null, 'image_path' => $imagePath, 'sort_order' => $index];
        }
        $lesson->blocks()->delete();
        $lesson->blocks()->createMany($newBlocks);
        $usedImagePaths = collect($newBlocks)->pluck('image_path')->filter();
        $previousImagePaths
            ->reject(fn (string $path) => $usedImagePaths->contains($path))
            ->each(fn (string $path) => $media->delete($path));

        $freshLesson = $lesson->fresh()->load('blocks');
        $freshLesson->setAttribute('preview_image_path', $freshLesson->blocks->firstWhere('type', 'image')?->image_path ?? $freshLesson->image_path);

        return response()->json(['data' => $freshLesson]);
    }

    public function destroy(Request $request, ArticleLesson $lesson, MediaStorage $media)
    {
        abort_unless(in_array($request->user()->role->value, ['admin', 'curator'], true), 403);
        $lesson->blocks()->whereNotNull('image_path')->get()
            ->each(fn (ArticleLessonBlock $block) => $media->delete($block->image_path));
        $lesson->delete();

        return response()->noContent();
    }
}
