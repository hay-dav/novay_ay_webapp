<?php

namespace App\Http\Controllers\Api;

use App\Models\AccessRequest;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class AccessRequestController extends Controller
{
    public function index(Request $request)
    {
        abort_unless(in_array($request->user()->role->value, ['admin', 'trainer'], true), 403);

        return response()->json([
            'data' => AccessRequest::query()->with('user:id,name,email,phone,access_status')->latest()->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'questionnaire' => ['required', 'string'],
            'photo_path' => ['nullable', 'string', 'max:255'],
        ]);

        $accessRequest = AccessRequest::query()->create([
            ...$validated,
            'user_id' => $request->user()->id,
            'status' => 'pending',
        ]);

        return response()->json(['data' => $accessRequest], 201);
    }

    public function approve(Request $request, AccessRequest $accessRequest)
    {
        abort_unless(in_array($request->user()->role->value, ['admin', 'trainer'], true), 403);

        $validated = $request->validate([
            'status' => ['required', 'in:approved,rejected'],
            'admin_comment' => ['nullable', 'string'],
        ]);

        $accessRequest->update([
            ...$validated,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        if ($validated['status'] === 'approved') {
            $accessRequest->user()->update([
                'access_status' => 'paid',
                'access_ends_at' => now()->addMonths(2),
            ]);
        }

        return response()->json(['data' => $accessRequest->load('user')]);
    }
}

