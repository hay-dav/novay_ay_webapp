<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccessPeriod extends Model
{
    protected $fillable = [
        'user_id',
        'granted_by',
        'starts_on',
        'ends_on',
        'months',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'months' => 'integer',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function grantedBy()
    {
        return $this->belongsTo(User::class, 'granted_by');
    }
}
