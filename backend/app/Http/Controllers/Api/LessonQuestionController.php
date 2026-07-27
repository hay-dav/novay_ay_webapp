<?php

namespace App\Http\Controllers\Api;

use App\Models\Lesson;
use App\Models\LessonQuestion;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class LessonQuestionController extends Controller
{
    public function store(Request $request, Lesson $lesson)
    {
        $question = LessonQuestion::query()->create([
            'lesson_id' => $lesson->id,
            'user_id' => $request->user()->id,
            'question' => $request->validate(['question' => ['required', 'string', 'max:2000']])['question'],
        ]);

        return response()->json(['data' => $question], 201);
    }

    public function answer(Request $request, LessonQuestion $question)
    {
        abort_unless(in_array($request->user()->role->value, ['curator', 'trainer', 'admin'], true), 403);
        if ($request->user()->role->value !== 'admin') {
            abort_unless($question->user?->clientProfile?->trainer_id === $request->user()->id, 403);
        }

        $question->update([
            'answer' => $request->validate(['answer' => ['required', 'string', 'max:2000']])['answer'],
            'answered_by' => $request->user()->id,
            'answered_at' => now(),
        ]);

        return response()->json(['data' => $question]);
    }
}
