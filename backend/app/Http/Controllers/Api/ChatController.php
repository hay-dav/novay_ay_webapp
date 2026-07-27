<?php

namespace App\Http\Controllers\Api;

use App\Models\ChatMessage;
use App\Models\Notification;
use App\Models\User;
use App\Services\MediaStorage;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ChatController extends Controller
{
    public function peers(Request $request)
    {
        if ($this->isStaff($request->user())) {
            $peers = User::query()
                ->where('role', 'client')
                ->when($request->user()->role->value !== 'admin', fn ($query) => $query->whereHas(
                    'clientProfile',
                    fn ($profile) => $profile->where('trainer_id', $request->user()->id),
                ))
                ->orderBy('name')
                ->get(['id', 'name', 'role', 'avatar_path']);
        } else {
            $peers = User::query()
                ->whereIn('role', ['curator', 'admin'])
                ->orderByRaw("CASE WHEN role = 'curator' THEN 0 ELSE 1 END")
                ->get(['id', 'name', 'role', 'avatar_path']);
        }

        $media = app(MediaStorage::class);
        $peers->each(function (User $peer) use ($media): void {
            if ($peer->avatar_path) {
                $peer->setAttribute('avatar_path', $media->secureCdnUrl($peer->avatar_path));
            }
        });

        return response()->json(['data' => $peers]);
    }

    public function index(Request $request)
    {
        $peer = $this->resolvePeer($request, $request->integer('peer_id') ?: null);
        if (! $peer) {
            return response()->json(['data' => []]);
        }

        $messages = ChatMessage::query()
            ->where(function ($conversation) use ($request, $peer): void {
                $conversation
                    ->where(function ($inner) use ($request, $peer): void {
                        $inner->where('sender_id', $request->user()->id)->where('recipient_id', $peer->id);
                    })
                    ->orWhere(function ($inner) use ($request, $peer): void {
                        $inner->where('sender_id', $peer->id)->where('recipient_id', $request->user()->id);
                    });
            })
            ->with('sender:id,name')
            ->latest()
            ->limit(100)
            ->get()
            ->reverse()
            ->values();

        $media = app(MediaStorage::class);
        $messages->each(function (ChatMessage $message) use ($media): void {
            if ($message->attachment_path) {
                $message->setAttribute('attachment_path', $media->secureCdnUrl($message->attachment_path));
            }
        });

        return response()->json(['data' => $messages]);
    }

    public function store(Request $request, MediaStorage $media)
    {
        $validated = $request->validate([
            'recipient_id' => ['nullable', 'integer', 'exists:users,id'],
            'body' => ['nullable', 'string', 'max:2000', 'required_without_all:photo,voice'],
            'photo' => ['nullable', 'image', 'max:10240', 'required_without_all:body,voice'],
            'voice' => ['nullable', 'file', 'mimes:webm,ogg,mp3,m4a,wav', 'max:25600', 'required_without_all:body,photo'],
        ]);
        $recipient = $this->resolvePeer($request, $validated['recipient_id'] ?? null);
        abort_unless($recipient, 422, 'Выберите собеседника.');

        $attachmentPath = null;
        $attachmentType = null;
        if ($request->hasFile('photo')) {
            $attachmentPath = $media->store($request->file('photo'), 'chat/photos');
            $attachmentType = 'photo';
        } elseif ($request->hasFile('voice')) {
            $attachmentPath = $media->store($request->file('voice'), 'chat/voice');
            $attachmentType = 'voice';
        }

        $message = ChatMessage::query()->create([
            'sender_id' => $request->user()->id,
            'recipient_id' => $recipient->id,
            'body' => $validated['body'] ?? '',
            'attachment_path' => $attachmentPath,
            'attachment_type' => $attachmentType,
        ]);

        Notification::query()->create([
            'user_id' => $recipient->id,
            'type' => 'chat',
            'title' => 'Новое сообщение от '.$request->user()->name,
            'body' => $message->body ?: ($attachmentType === 'voice' ? 'Голосовое сообщение' : 'Фото'),
            'data' => ['chat_message_id' => $message->id, 'sender_id' => $request->user()->id],
        ]);

        $message->load('sender:id,name');
        if ($message->attachment_path) {
            $message->setAttribute('attachment_path', $media->secureCdnUrl($message->attachment_path));
        }

        return response()->json(['data' => $message], 201);
    }

    private function resolvePeer(Request $request, ?int $peerId): ?User
    {
        if ($this->isStaff($request->user())) {
            return User::query()
                ->where('role', 'client')
                ->when($request->user()->role->value !== 'admin', fn ($query) => $query->whereHas(
                    'clientProfile',
                    fn ($profile) => $profile->where('trainer_id', $request->user()->id),
                ))
                ->when($peerId, fn ($query) => $query->whereKey($peerId))
                ->orderBy('name')
                ->first();
        }

        return User::query()
            ->whereIn('role', ['curator', 'admin'])
            ->when($peerId, fn ($query) => $query->whereKey($peerId))
            ->orderByRaw("CASE WHEN role = 'curator' THEN 0 ELSE 1 END")
            ->first();
    }

    private function isStaff(User $user): bool
    {
        return in_array($user->role->value, ['curator', 'trainer', 'admin'], true);
    }

}
