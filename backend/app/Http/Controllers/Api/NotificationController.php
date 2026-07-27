<?php

namespace App\Http\Controllers\Api;

use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        return response()->json([
            'data' => Notification::query()
                ->where('user_id', $request->user()->id)
                ->latest()
                ->limit(20)
                ->get(),
        ]);
    }
}

