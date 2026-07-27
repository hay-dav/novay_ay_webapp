<?php

namespace App\Http\Controllers\Api;

use App\Models\FoodEntry;
use App\Models\ClientComment;
use App\Models\Recipe;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class FoodDiaryController extends Controller
{
    public function index(Request $request)
    {
        $date = $request->query('date', now()->toDateString());
        $entries = FoodEntry::query()
            ->where('user_id', $request->user()->id)
            ->whereDate('eaten_on', $date)
            ->latest()
            ->get();

        $profile = $request->user()->clientProfile;
        $summary = [
            'calories' => (int) $entries->sum('calories'),
            'protein_g' => (float) $entries->sum('protein_g'),
            'fat_g' => (float) $entries->sum('fat_g'),
            'carbs_g' => (float) $entries->sum('carbs_g'),
            'goals' => [
                'calories' => $profile?->calorie_goal ?? 1450,
                'protein_g' => $profile?->protein_goal_g ?? 100,
                'fat_g' => $profile?->fat_goal_g ?? 50,
                'carbs_g' => $profile?->carbs_goal_g ?? 150,
            ],
        ];

        return response()->json(['data' => $entries, 'summary' => $summary]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'recipe_id' => ['nullable', 'exists:recipes,id'],
            'meal_type' => ['required', 'string', 'max:64'],
            'title' => ['required_without:recipe_id', 'nullable', 'string', 'max:255'],
            'calories' => ['nullable', 'integer', 'min:0'],
            'protein_g' => ['nullable', 'numeric', 'min:0'],
            'fat_g' => ['nullable', 'numeric', 'min:0'],
            'carbs_g' => ['nullable', 'numeric', 'min:0'],
            'eaten_on' => ['nullable', 'date'],
            'is_favorite' => ['nullable', 'boolean'],
        ]);

        if (! empty($validated['recipe_id'])) {
            $recipe = Recipe::query()->findOrFail($validated['recipe_id']);
            $validated = [
                ...$validated,
                'title' => $validated['title'] ?? $recipe->title,
                'calories' => $validated['calories'] ?? $recipe->calories,
                'protein_g' => $validated['protein_g'] ?? $recipe->protein_g,
                'fat_g' => $validated['fat_g'] ?? $recipe->fat_g,
                'carbs_g' => $validated['carbs_g'] ?? $recipe->carbs_g,
            ];
        }

        $entry = FoodEntry::query()->create([
            ...$validated,
            'calories' => $validated['calories'] ?? 0,
            'protein_g' => $validated['protein_g'] ?? 0,
            'fat_g' => $validated['fat_g'] ?? 0,
            'carbs_g' => $validated['carbs_g'] ?? 0,
            'user_id' => $request->user()->id,
            'eaten_on' => $validated['eaten_on'] ?? now()->toDateString(),
        ]);

        return response()->json(['data' => $entry], 201);
    }

    public function updateGoals(Request $request)
    {
        abort_unless($request->user()->role->value === 'client', 403);

        $validated = $request->validate([
            'calorie_goal' => ['required', 'integer', 'min:1', 'max:10000'],
        ]);

        $profile = $request->user()->clientProfile()->firstOrCreate([
            'user_id' => $request->user()->id,
        ]);
        $profile->update($validated);

        return response()->json(['data' => [
            'calorie_goal' => $profile->calorie_goal,
        ]]);
    }

    public function comments(Request $request)
    {
        abort_unless($request->user()->role->value === 'client', 403);

        $comments = ClientComment::query()
            ->where('client_id', $request->user()->id)
            ->with('author:id,name')
            ->latest()
            ->get();

        return response()->json(['data' => $comments]);
    }
}
