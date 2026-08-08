<?php

namespace App\Http\Controllers\Api;

use App\Models\ClientProfile;
use App\Models\User;
use App\Notifications\ResetPasswordLinkNotification;
use App\Services\MediaOptimizer;
use App\Services\MediaStorage;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
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
        $validated['first_name'] = trim($validated['first_name']);
        $validated['last_name'] = trim($validated['last_name']);
        $validated['name'] = $validated['last_name'].' '.$validated['first_name'];
        $validated['role'] = 'client';
        $validated['access_status'] = 'free';
        $validated['privacy_policy_accepted_at'] = now();
        $validated['privacy_policy_version'] = '2026-07-26';

        $user = User::query()->create($validated);
        ClientProfile::query()->create(['user_id' => $user->id, 'goal' => $goal]);

        return response()->json([
            'user' => $this->present($user),
            'token' => $user->createToken('web', ['*'])->plainTextToken,
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

        return response()->json([
            'user' => $this->present($user),
            'token' => $user->createToken('web', ['*'])->plainTextToken,
        ]);
    }

    public function me(Request $request)
    {
        // Tokens issued before persistent login was introduced had an expiry.
        // Extend a valid current session once, without making the user sign in again.
        $request->user()->currentAccessToken()?->forceFill(['expires_at' => null])->save();

        return response()->json(['user' => $this->present($request->user()->load('clientProfile'))]);
    }

    public function forgotPassword(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $email = Str::lower(trim($validated['email']));
        $emailHash = User::lookupHash($email);
        $user = User::query()->where('email_hash', $emailHash)->first();

        if ($user && ! $user->blocked_at && ! $user->archived_at) {
            $token = Str::random(64);
            DB::table('password_reset_tokens')->updateOrInsert(
                ['email_hash' => $emailHash],
                ['token' => Hash::make($token), 'created_at' => now()],
            );

            $resetUrl = rtrim((string) config('app.frontend_url'), '/')
                .'/login?mode=reset&token='.urlencode($token).'&email='.urlencode($email);
            $user->notify(new ResetPasswordLinkNotification($resetUrl));
        }

        return response()->json([
            'message' => config('mail.default') === 'log'
                ? 'Запрос принят. Отправка email временно не настроена — обратитесь к администратору.'
                : 'Если аккаунт найден, ссылка для сброса пароля отправлена на указанный email.',
        ]);
    }

    public function resetPassword(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'token' => ['required', 'string', 'size:64'],
            'password' => ['required', 'string', 'min:12', 'max:255', 'confirmed'],
        ]);

        $emailHash = User::lookupHash($validated['email']);
        $reset = DB::table('password_reset_tokens')->where('email_hash', $emailHash)->first();
        $expired = ! $reset || now()->diffInMinutes($reset->created_at, true) > 60;
        if ($expired || ! Hash::check($validated['token'], $reset->token)) {
            throw ValidationException::withMessages([
                'token' => 'Ссылка для сброса пароля недействительна или устарела.',
            ]);
        }

        $user = User::query()->where('email_hash', $emailHash)->first();
        if (! $user) {
            throw ValidationException::withMessages(['email' => 'Аккаунт не найден.']);
        }

        $user->forceFill(['password' => $validated['password']])->save();
        $user->tokens()->delete();
        DB::table('password_reset_tokens')->where('email_hash', $emailHash)->delete();

        return response()->json(['message' => 'Пароль изменён. Теперь вы можете войти в кабинет.']);
    }

    public function changePassword(Request $request)
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:12', 'max:255', 'confirmed'],
        ]);

        $user = $request->user();
        if (! Hash::check($validated['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'Текущий пароль указан неверно.',
            ]);
        }

        $user->forceFill(['password' => $validated['password']])->save();
        $currentTokenId = $user->currentAccessToken()?->id;
        $user->tokens()->when($currentTokenId, fn ($query) => $query->where('id', '!=', $currentTokenId))->delete();

        return response()->json(['message' => 'Пароль успешно изменён.']);
    }

    public function updateAvatar(Request $request, MediaStorage $media, MediaOptimizer $optimizer)
    {
        $validated = $request->validate([
            'avatar' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,heic,heif', 'max:20480'],
        ]);

        $user = $request->user();
        $sourcePath = $media->store($validated['avatar'], 'avatars');
        $avatarPath = $optimizer->optimize($sourcePath, 'image');
        if (! $avatarPath) {
            $media->delete($sourcePath);
            throw ValidationException::withMessages([
                'avatar' => 'Не удалось обработать изображение. Выберите другое фото и повторите попытку.',
            ]);
        }

        $previousPath = $user->avatar_path;
        $user->update(['avatar_path' => $avatarPath]);
        $media->delete($previousPath);
        $media->delete($sourcePath);

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
