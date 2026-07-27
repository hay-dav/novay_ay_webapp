<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;
    use Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'phone',
        'avatar_path',
        'access_status',
        'group_name',
        'tags',
        'access_ends_at',
        'blocked_at',
        'archived_at',
        'email_verified_at',
        'privacy_policy_accepted_at',
        'privacy_policy_version',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'email_hash',
        'phone_hash',
    ];

    protected function casts(): array
    {
        return [
            'email' => 'encrypted',
            'phone' => 'encrypted',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'tags' => 'array',
            'access_ends_at' => 'datetime',
            'blocked_at' => 'datetime',
            'archived_at' => 'datetime',
            'privacy_policy_accepted_at' => 'datetime',
        ];
    }

    public static function lookupHash(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return hash_hmac('sha256', Str::lower(trim($value)), (string) config('app.key'));
    }

    public function setEmailAttribute(?string $value): void
    {
        $this->attributes['email'] = $value === null ? null : Crypt::encryptString($value);
        $this->attributes['email_hash'] = self::lookupHash($value);
    }

    public function setPhoneAttribute(?string $value): void
    {
        $this->attributes['phone'] = $value === null ? null : Crypt::encryptString($value);
        $this->attributes['phone_hash'] = self::lookupHash($value);
    }

    public function trainerProfile()
    {
        return $this->hasOne(TrainerProfile::class);
    }

    public function clientProfile()
    {
        return $this->hasOne(ClientProfile::class);
    }

    public function courses()
    {
        return $this->hasMany(Course::class, 'trainer_id');
    }

    public function accessRequests()
    {
        return $this->hasMany(AccessRequest::class);
    }

    public function foodEntries()
    {
        return $this->hasMany(FoodEntry::class);
    }

    public function progressEntries()
    {
        return $this->hasMany(ProgressEntry::class);
    }
}
