<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LiveStream extends Model
{
    protected $fillable = ['host_id', 'recording_workout_id', 'recording_title', 'recording_description', 'recording_access_level', 'room_name', 'status', 'started_at', 'ended_at', 'host_heartbeat_at', 'guest_token', 'guest_enabled', 'guest_expires_at', 'participants_enabled', 'egress_id', 'egress_path', 'egress_status'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'ended_at' => 'datetime', 'host_heartbeat_at' => 'datetime', 'guest_expires_at' => 'datetime', 'guest_enabled' => 'boolean', 'participants_enabled' => 'boolean'];
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
