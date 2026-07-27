<?php

namespace App\Http\Controllers\Api;

use App\Models\Lesson;
use App\Models\LessonProgress;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class LessonProgressController extends Controller
{
    public function update(Request $request, Lesson $lesson)
    {
        $validated = $request->validate([
            'status' => ['required', 'in:not_started,in_progress,completed'],
            'progress_percent' => ['required', 'integer', 'min:0', 'max:100'],
        ]);

        $progress = LessonProgress::query()->updateOrCreate(
            ['user_id' => $request->user()->id, 'lesson_id' => $lesson->id],
            [
                ...$validated,
                'completed_at' => $validated['status'] === 'completed' ? now() : null,
            ],
        );

        return response()->json(['data' => $progress]);
    }
}

