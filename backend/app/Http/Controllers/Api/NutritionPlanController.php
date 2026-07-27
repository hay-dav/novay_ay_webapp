<?php

namespace App\Http\Controllers\Api;

use App\Models\NutritionPlan;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class NutritionPlanController extends Controller
{
    public function current(Request $request)
    {
        $plan = NutritionPlan::query()
            ->with('meals')
            ->where('client_id', $request->user()->id)
            ->latest('starts_on')
            ->first();

        return response()->json(['data' => $plan]);
    }

    public function storeForClient(Request $request, int $client)
    {
        abort_unless(in_array($request->user()->role->value, ['trainer', 'admin'], true), 403);
        $clientUser = User::query()->whereKey($client)->where('role', 'client')->firstOrFail();
        if ($request->user()->role->value !== 'admin') {
            abort_unless($clientUser->clientProfile?->trainer_id === $request->user()->id, 403);
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'notes' => ['nullable', 'string'],
        ]);

        $plan = NutritionPlan::query()->create([
            ...$validated,
            'client_id' => $client,
            'trainer_id' => $request->user()->id,
        ]);

        return response()->json(['data' => $plan], 201);
    }
}
