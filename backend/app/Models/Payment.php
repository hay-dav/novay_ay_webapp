<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $fillable = ['purchase_id', 'provider', 'provider_payment_id', 'status', 'amount_cents', 'currency', 'metadata'];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }
}

