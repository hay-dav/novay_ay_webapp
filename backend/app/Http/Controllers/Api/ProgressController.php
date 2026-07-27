<?php

namespace App\Http\Controllers\Api;

use App\Models\ProgressEntry;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ProgressController extends Controller
{
    public function index(Request $request)
    {
        return response()->json([
            'data' => ProgressEntry::query()
                ->where('user_id', $request->user()->id)
                ->orderBy('measured_on')
                ->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'weight_kg' => ['required', 'numeric', 'min:25', 'max:300'],
            'waist_cm' => ['nullable', 'numeric', 'min:20', 'max:250'],
            'hips_cm' => ['nullable', 'numeric', 'min:20', 'max:250'],
            'chest_cm' => ['nullable', 'numeric', 'min:20', 'max:250'],
            'mood' => ['nullable', 'string', 'max:120'],
            'comment' => ['nullable', 'string'],
            'measured_on' => ['required', 'date'],
        ]);

        $entry = ProgressEntry::query()->create([
            ...$validated,
            'user_id' => $request->user()->id,
        ]);

        return response()->json(['data' => $entry], 201);
    }
}

