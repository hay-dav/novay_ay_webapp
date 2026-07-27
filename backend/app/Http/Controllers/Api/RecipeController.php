<?php

namespace App\Http\Controllers\Api;

use App\Models\Recipe;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class RecipeController extends Controller
{
    public function index(Request $request)
    {
        $query = Recipe::query()->latest();

        if ($request->filled('category')) {
            $query->where('category', $request->query('category'));
        }

        return response()->json(['data' => $query->get()]);
    }

    public function store(Request $request)
    {
        abort_unless(in_array($request->user()->role->value, ['admin', 'trainer'], true), 403);

        $recipe = Recipe::query()->create($request->validate([
            'title' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:64'],
            'photo_path' => ['nullable', 'string', 'max:255'],
            'ingredients' => ['required', 'string'],
            'steps' => ['required', 'string'],
            'calories' => ['required', 'integer', 'min:0'],
            'protein_g' => ['required', 'numeric', 'min:0'],
            'fat_g' => ['required', 'numeric', 'min:0'],
            'carbs_g' => ['required', 'numeric', 'min:0'],
            'is_free' => ['boolean'],
        ]));

        return response()->json(['data' => $recipe], 201);
    }
}

