<?php

namespace App\Http\Controllers\Api;

use App\Models\ClientProfile;
use App\Models\User;
use App\Services\MediaStorage;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'required_without:phone'],
            'phone' => ['nullable', 'string', 'max:32', 'required_without:email'],
            'goal' => ['nullable', 'string', 'max:1000'],
            'password' => ['required', 'string', 'min:12', 'max:255'],
            'privacy_policy_accepted' => ['required', 'accepted'],
        ]);

        $emailHash = User::lookupHash($validated['email'] ?? null);
        $phoneHash = User::lookupHash($validated['phone'] ?? null);
        if (($emailHash && User::query()->where('email_hash', $emailHash)->exists())
            || ($phoneHash && User::query()->where('phone_hash', $phoneHash)->exists())
        ) {
            throw ValidationException::withMessages(['email' => 'Аккаунт с такими данными уже существует.']);
        }

        $goal = $validated['goal'] ?? null;
        unset($validated['goal'], $validated['privacy_policy_accepted']);
        $validated['role'] = 'client';
        $validated['access_status'] = 'free';
        $validated['privacy_policy_accepted_at'] = now();
        $validated['privacy_policy_version'] = '2026-07-26';

        $user = User::query()->create($validated);
        ClientProfile::query()->create(['user_id' => $user->id, 'goal' => $goal]);

        return response()->json([
            'user' => $this->present($user),
            'token' => $user->createToken('web', ['*'], now()->addHours(8))->plainTextToken,
        ], 201);
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $lookupHash = User::lookupHash($credentials['email']);
        $user = User::query()
            ->where('email_hash', $lookupHash)
            ->orWhere('phone_hash', $lookupHash)
            ->first();

        if (! $user || $user->blocked_at || $user->archived_at || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'Неверный логин или пароль.']);
        }

        $user->tokens()->where('name', 'web')->delete();

        return response()->json([
            'user' => $this->present($user),
            'token' => $user->createToken('web', ['*'], now()->addHours(8))->plainTextToken,
        ]);
    }

    public function me(Request $request)
    {
        return response()->json(['user' => $this->present($request->user()->load('clientProfile'))]);
    }

    public function updateAvatar(Request $request, MediaStorage $media)
    {
        $validated = $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $user = $request->user();
        $media->delete($user->avatar_path);
        $user->update(['avatar_path' => $media->store($validated['avatar'], 'avatars')]);

        return response()->json(['data' => $this->present($user->fresh()->load('clientProfile'))]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->noContent();
    }

    private function present(User $user): User
    {
        if ($user->avatar_path) {
            $user->setAttribute('avatar_path', app(MediaStorage::class)->secureCdnUrl($user->avatar_path, 3600));
        }

        return $user;
    }
}
