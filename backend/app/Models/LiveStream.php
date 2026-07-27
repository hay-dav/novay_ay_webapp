<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LiveStream extends Model
{
    protected $fillable = ['host_id', 'recording_workout_id', 'room_name', 'status', 'started_at', 'ended_at', 'host_heartbeat_at'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'ended_at' => 'datetime', 'host_heartbeat_at' => 'datetime'];
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_id');
    }

    public function viewers(): HasMany
    {
        return $this->hasMany(LiveStreamViewer::class);
    }

    public function recordingWorkout(): BelongsTo
    {
        return $this->belongsTo(Workout::class, 'recording_workout_id');
    }
}
