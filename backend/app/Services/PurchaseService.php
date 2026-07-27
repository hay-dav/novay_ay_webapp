<?php

namespace App\Services;

use App\Models\Course;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\User;
use Illuminate\Support\Str;

class PurchaseService
{
    public function createPurchase(User $user, Course $course): array
    {
        $purchase = Purchase::query()->firstOrCreate(
            ['user_id' => $user->id, 'course_id' => $course->id],
            [
                'status' => 'pending',
                'amount_cents' => $course->price_cents,
                'currency' => $course->currency,
            ],
        );

        $payment = Payment::query()->create([
            'purchase_id' => $purchase->id,
            'provider' => config('services.payment.provider', 'mock'),
            'provider_payment_id' => 'pay_'.Str::ulid(),
            'status' => 'pending',
            'amount_cents' => $purchase->amount_cents,
            'currency' => $purchase->currency,
            'metadata' => ['checkout_url' => url("/mock-checkout/{$purchase->id}")],
        ]);

        return ['purchase' => $purchase, 'payment' => $payment];
    }
}

