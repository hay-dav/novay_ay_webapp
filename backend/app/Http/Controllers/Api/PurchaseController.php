<?php

namespace App\Http\Controllers\Api;

use App\Models\Course;
use App\Models\Purchase;
use App\Services\PurchaseService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class PurchaseController extends Controller
{
    public function __construct(private readonly PurchaseService $purchaseService)
    {
    }

    public function index(Request $request)
    {
        return response()->json([
            'data' => Purchase::query()
                ->where('user_id', $request->user()->id)
                ->latest()
                ->get(),
        ]);
    }

    public function store(Request $request, Course $course)
    {
        $result = $this->purchaseService->createPurchase($request->user(), $course);

        return response()->json($result, 201);
    }

}
