<?php

namespace App\Http\Controllers\Api;

use App\Jobs\OptimizeStoredMedia;
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
        $user = $request->user();
        if ($this->isStaff($request->user())) {
            $peers = User::query()
                ->where('role', 'client')
                ->when(! in_array($request->user()->role->value, ['admin', 'curator'], true), fn ($query) => $query->whereHas(
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
        $unreadBySender = ChatMessage::query()
            ->where('recipient_id', $user->id)
            ->whereNull('read_at')
            ->selectRaw('sender_id, count(*) as unread_count')
            ->groupBy('sender_id')
            ->pluck('unread_count', 'sender_id');

        $peers->each(function (User $peer) use ($media, $unreadBySender): void {
            if ($peer->avatar_path) {
                $peer->setAttribute('avatar_path', $media->secureCdnUrl($peer->avatar_path));
            }
            $peer->setAttribute('unread_count', (int) ($unreadBySender[$peer->id] ?? 0));
        });

        return response()->json(['data' => $peers]);
    }

    public function index(Request $request)
    {
        if ($request->filled(['participant_a_id', 'participant_b_id'])) {
            return $this->conversation($request);
        }

        $peer = $this->resolvePeer($request, $request->integer('peer_id') ?: null);
        if (! $peer) {
            return response()->json(['data' => []]);
        }

        ChatMessage::query()
            ->where('sender_id', $peer->id)
            ->where('recipient_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

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

    public function unreadCount(Request $request)
    {
        return response()->json([
            'data' => [
                'count' => ChatMessage::query()
                    ->where('recipient_id', $request->user()->id)
                    ->whereNull('read_at')
                    ->count(),
            ],
        ]);
    }

    public function conversations(Request $request)
    {
        abort_unless($request->user()->role->value === 'admin', 403);

        $latestMessageIds = ChatMessage::query()
            ->selectRaw('DISTINCT ON (LEAST(sender_id, recipient_id), GREATEST(sender_id, recipient_id)) id')
            ->orderByRaw('LEAST(sender_id, recipient_id)')
            ->orderByRaw('GREATEST(sender_id, recipient_id)')
            ->orderByDesc('created_at');

        $media = app(MediaStorage::class);
        $conversations = ChatMessage::query()
            ->whereIn('id', $latestMessageIds)
            ->with([
                'sender:id,name,role,avatar_path',
                'recipient:id,name,role,avatar_path',
            ])
            ->latest()
            ->get()
            ->map(function (ChatMessage $message) use ($media): ChatMessage {
                foreach ([$message->sender, $message->recipient] as $participant) {
                    if ($participant?->avatar_path) {
                        $participant->setAttribute('avatar_path', $media->secureCdnUrl($participant->avatar_path));
                    }
                }

                if ($message->attachment_path) {
                    $message->setAttribute('attachment_path', $media->secureCdnUrl($message->attachment_path));
                }

                return $message;
            })
            ->values();

        return response()->json(['data' => $conversations]);
    }

    public function store(Request $request, MediaStorage $media)
    {
        $validated = $request->validate([
            'recipient_id' => ['nullable', 'integer', 'exists:users,id'],
            'body' => ['nullable', 'string', 'max:2000', 'required_without_all:photo,voice'],
            'photo' => ['nullable', 'image', 'max:10240', 'required_without_all:body,voice'],
            // Safari commonly records an audio/mp4 file, while Chrome uses
            // audio/webm. Validate the detected media type rather than a
            // narrow extension list so voice messages work on both platforms.
            'voice' => ['nullable', 'file', 'mimetypes:audio/*,video/webm,application/ogg', 'max:25600', 'required_without_all:body,photo'],
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
        if ($attachmentPath && $attachmentType) {
            OptimizeStoredMedia::dispatch(
                ChatMessage::class,
                $message->id,
                'attachment_path',
                $attachmentPath,
                $attachmentType === 'photo' ? 'image' : 'audio',
            );
        }

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
                ->when(! in_array($request->user()->role->value, ['admin', 'curator'], true), fn ($query) => $query->whereHas(
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

    private function conversation(Request $request)
    {
        abort_unless($request->user()->role->value === 'admin', 403);

        $validated = $request->validate([
            'participant_a_id' => ['required', 'integer', 'different:participant_b_id', 'exists:users,id'],
            'participant_b_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $messages = ChatMessage::query()
            ->where(function ($query) use ($validated): void {
                $query
                    ->where('sender_id', $validated['participant_a_id'])
                    ->where('recipient_id', $validated['participant_b_id']);
            })
            ->orWhere(function ($query) use ($validated): void {
                $query
                    ->where('sender_id', $validated['participant_b_id'])
                    ->where('recipient_id', $validated['participant_a_id']);
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

    private function isStaff(User $user): bool
    {
        return in_array($user->role->value, ['curator', 'trainer', 'admin'], true);
    }

}
