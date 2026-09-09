<?php

namespace App\Http\Controllers\Api;

use App\Models\ChatMessage;
use App\Models\ChatNotificationPreference;
use App\Models\ChatRoom;
use App\Models\ChatRoomRead;
use App\Models\Notification;
use App\Models\User;
use App\Services\MediaStorage;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ChatController extends Controller
{
    private const CHAT_CURATOR_ID = 10;

    public function peers(Request $request)
    {
        $user = $request->user();
        if ($this->isStaff($user) || $this->isChatCurator($user)) {
            $peers = User::query()
                ->where('role', 'client')
                ->when(! $this->isChatCurator($user) && ! in_array($user->role->value, ['admin', 'curator'], true), fn ($query) => $query->whereHas(
                    'clientProfile', fn ($profile) => $profile->where('trainer_id', $user->id),
                ))
                ->orderBy('name')
                ->get(['id', 'name', 'role', 'avatar_path']);
        } else {
            $peers = $this->supportTeam();
        }

        $media = app(MediaStorage::class);
        $unreadBySender = ChatMessage::query()
            ->whereNull('chat_room_id')->where('recipient_id', $user->id)->whereNull('read_at')
            ->selectRaw('sender_id, count(*) as unread_count')->groupBy('sender_id')->pluck('unread_count', 'sender_id');

        $peers->each(function (User $peer) use ($media, $unreadBySender, $user): void {
            if ($peer->avatar_path) $peer->setAttribute('avatar_path', $media->secureCdnUrl($peer->avatar_path));
            $peer->setAttribute('unread_count', (int) ($unreadBySender[$peer->id] ?? 0));
            $last = ChatMessage::query()->whereNull('chat_room_id')
                ->where(fn ($q) => $q->where(fn ($i) => $i->where('sender_id', $user->id)->where('recipient_id', $peer->id))
                    ->orWhere(fn ($i) => $i->where('sender_id', $peer->id)->where('recipient_id', $user->id)))
                ->latest()->first(['id', 'body', 'attachment_type', 'created_at']);
            $peer->setAttribute('last_message', $last ? $this->preview($last) : null);
            $peer->setAttribute('last_message_at', $last?->created_at);
        });

        return response()->json(['data' => $peers->values()]);
    }

    public function general(Request $request)
    {
        if (! $this->canUseRoomChats($request->user())) return response()->json(['data' => null]);

        return $this->roomOverview($request, $this->generalRoom());
    }

    public function important(Request $request)
    {
        return $this->roomOverview($request, $this->importantRoom());
    }

    private function roomOverview(Request $request, ChatRoom $room)
    {
        $last = $room->messages()->latest()->first(['id', 'body', 'attachment_type', 'created_at']);
        $read = ChatRoomRead::query()->where('chat_room_id', $room->id)->where('user_id', $request->user()->id)->value('last_read_message_id') ?? 0;
        $unread = $room->messages()->where('id', '>', $read)->where('sender_id', '!=', $request->user()->id)->count();

        return response()->json(['data' => [
            'id' => $room->id, 'slug' => $room->slug, 'name' => $room->name,
            'last_message' => $last ? $this->preview($last) : null,
            'last_message_at' => $last?->created_at, 'unread_count' => $unread,
        ]]);
    }

    public function generalMessages(Request $request)
    {
        abort_unless($this->canUseRoomChats($request->user()), 403);

        return $this->roomMessages($request, $this->generalRoom());
    }

    public function importantMessages(Request $request)
    {
        return $this->roomMessages($request, $this->importantRoom());
    }

    private function roomMessages(Request $request, ChatRoom $room)
    {
        $messages = $room->messages()->with($this->messageRelations())->latest()->limit(100)->get()->reverse()->values();
        $lastId = $messages->last()?->id;
        // A read receipt belongs only to the current user. Polling the chat must
        // never change it: otherwise an unread badge can vanish without the user
        // intentionally opening the conversation.
        if ($lastId && $request->boolean('mark_read')) ChatRoomRead::query()->updateOrCreate(
            ['chat_room_id' => $room->id, 'user_id' => $request->user()->id],
            ['last_read_message_id' => $lastId],
        );

        return response()->json(['data' => $this->decorateMessages($messages)]);
    }

    public function index(Request $request)
    {
        if ($request->filled(['participant_a_id', 'participant_b_id'])) return $this->conversation($request);
        $peer = $this->resolvePeer($request, $request->integer('peer_id') ?: null);
        if (! $peer) return response()->json(['data' => []]);

        ChatMessage::query()->whereNull('chat_room_id')->where('sender_id', $peer->id)->where('recipient_id', $request->user()->id)->whereNull('read_at')->update(['read_at' => now()]);
        $messages = ChatMessage::query()->whereNull('chat_room_id')
            ->where(fn ($q) => $q->where(fn ($i) => $i->where('sender_id', $request->user()->id)->where('recipient_id', $peer->id))
                ->orWhere(fn ($i) => $i->where('sender_id', $peer->id)->where('recipient_id', $request->user()->id)))
            ->with($this->messageRelations())->latest()->limit(100)->get()->reverse()->values();
        return response()->json(['data' => $this->decorateMessages($messages)]);
    }

    public function unreadCount(Request $request)
    {
        $user = $request->user();
        $directUnread = ChatMessage::query()->whereNull('chat_room_id')->where('recipient_id', $user->id)->whereNull('read_at')->count();
        $rooms = collect([$this->importantRoom()]);
        if ($this->canUseRoomChats($user)) $rooms->prepend($this->generalRoom());
        $roomUnread = $rooms->sum(function (ChatRoom $room) use ($user): int {
            $lastReadId = ChatRoomRead::query()->where('chat_room_id', $room->id)->where('user_id', $user->id)->value('last_read_message_id') ?? 0;
            return $room->messages()->where('id', '>', $lastReadId)->where('sender_id', '!=', $user->id)->count();
        });

        return response()->json(['data' => ['count' => $directUnread + $roomUnread]]);
    }

    public function conversations(Request $request)
    {
        abort_unless($request->user()->role->value === 'admin', 403);
        $ids = ChatMessage::query()->whereNull('chat_room_id')
            ->selectRaw('DISTINCT ON (LEAST(sender_id, recipient_id), GREATEST(sender_id, recipient_id)) id')
            ->orderByRaw('LEAST(sender_id, recipient_id)')->orderByRaw('GREATEST(sender_id, recipient_id)')->orderByDesc('created_at');
        $conversations = ChatMessage::query()->whereIn('id', $ids)->with($this->conversationRelations())->latest()->get();
        return response()->json(['data' => $this->decorateConversations($conversations)]);
    }

    public function curatorConversations(Request $request)
    {
        abort_unless($request->user()->role->value === 'admin', 403);
        $curator = User::query()->find(self::CHAT_CURATOR_ID, ['id']);
        if (! $curator) return response()->json(['data' => []]);

        $messages = ChatMessage::query()->whereNull('chat_room_id')
            ->where(fn ($query) => $query->where('sender_id', $curator->id)->orWhere('recipient_id', $curator->id))
            ->with($this->conversationRelations())
            ->latest()->get()
            ->groupBy(fn (ChatMessage $message) => min($message->sender_id, $message->recipient_id).'-'.max($message->sender_id, $message->recipient_id))
            ->map->first()->values();

        return response()->json(['data' => $this->decorateConversations($messages)]);
    }

    public function store(Request $request, MediaStorage $media)
    {
        $validated = $request->validate([
            'room_slug' => ['nullable', 'string', 'in:general,important-info'],
            'reply_to_id' => ['nullable', 'integer', 'exists:chat_messages,id'],
            'recipient_id' => ['nullable', 'integer', 'exists:users,id', 'required_without:room_slug'],
            'body' => ['nullable', 'string', 'max:2000', 'required_without_all:photo,voice'],
            'photo' => ['nullable', 'image', 'max:10240', 'required_without_all:body,voice'],
            'voice' => ['nullable', 'file', 'mimetypes:audio/*,video/webm,application/ogg', 'max:25600', 'required_without_all:body,photo'],
        ]);
        $attachmentPath = null; $attachmentType = null;
        if ($request->hasFile('photo')) { $attachmentPath = $media->storeOptimized($request->file('photo'), 'chat/photos', 'image'); $attachmentType = 'photo'; }
        elseif ($request->hasFile('voice')) { $attachmentPath = $media->storeOptimized($request->file('voice'), 'chat/voice', 'audio'); $attachmentType = 'voice'; }

        if (filled($validated['room_slug'] ?? null)) {
            $room = ($validated['room_slug'] ?? null) === 'important-info' ? $this->importantRoom() : $this->generalRoom();
            abort_unless($room->slug === 'important-info' || $this->canUseRoomChats($request->user()), 403);
            abort_if($room->slug === 'important-info' && $request->user()->role->value !== 'admin', 403, 'Только администратор может публиковать важную информацию.');
            $message = ChatMessage::query()->create(['chat_room_id' => $room->id, 'reply_to_id' => $validated['reply_to_id'] ?? null, 'sender_id' => $request->user()->id, 'body' => $validated['body'] ?? '', 'attachment_path' => $attachmentPath, 'attachment_type' => $attachmentType]);
            $senderName = $request->user()->name;
            User::query()->where('id', '!=', $request->user()->id)->whereNull('blocked_at')->whereNull('archived_at')
                ->when($room->slug !== 'important-info', fn ($query) => $query->where(fn ($members) => $members->where('role', '!=', 'client')->orWhereNull('staff_status')->orWhereNotIn('staff_status', ['newcomer', 'dropped_out'])))
                ->eachById(function (User $recipient) use ($message, $senderName, $attachmentType, $room): void {
                Notification::query()->create(['user_id' => $recipient->id, 'type' => 'chat', 'title' => $room->name.': '.$senderName, 'body' => $message->body ?: ($attachmentType === 'voice' ? 'Голосовое сообщение' : 'Фото'), 'data' => ['chat_message_id' => $message->id, 'room_slug' => $room->slug, 'chat_notification_key' => 'room:'.$room->slug]]);
            });
        } else {
            $recipient = $this->resolvePeer($request, $validated['recipient_id']);
            abort_unless($recipient, 422, 'Выберите собеседника.');
            $message = ChatMessage::query()->create(['reply_to_id' => $validated['reply_to_id'] ?? null, 'sender_id' => $request->user()->id, 'recipient_id' => $recipient->id, 'body' => $validated['body'] ?? '', 'attachment_path' => $attachmentPath, 'attachment_type' => $attachmentType]);
            $senderName = $this->isChatCurator($request->user()) ? 'Куратор' : $request->user()->name;
            Notification::query()->create(['user_id' => $recipient->id, 'type' => 'chat', 'title' => 'Новое сообщение от '.$senderName, 'body' => $message->body ?: ($attachmentType === 'voice' ? 'Голосовое сообщение' : 'Фото'), 'data' => ['chat_message_id' => $message->id, 'sender_id' => $request->user()->id, 'chat_notification_key' => 'direct:'.$request->user()->id]]);
        }
        $message->load($this->messageRelations());
        return response()->json(['data' => $this->decorateMessage($message)], 201);
    }

    public function broadcast(Request $request)
    {
        abort_unless($this->canBroadcast($request->user()), 403);

        $data = $request->validate([
            'recipient_ids' => ['required', 'array', 'min:1', 'max:500'],
            'recipient_ids.*' => ['required', 'integer', 'distinct', 'exists:users,id'],
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $recipientIds = collect($data['recipient_ids'])->map(fn ($id) => (int) $id)->unique()->values();
        $recipients = User::query()
            ->whereIn('id', $recipientIds)
            ->where('role', 'client')
            ->whereNull('blocked_at')
            ->whereNull('archived_at')
            ->get(['id']);

        abort_unless($recipients->count() === $recipientIds->count(), 422, 'В списке есть недоступные получатели.');

        $sender = $request->user();
        $senderName = $this->isChatCurator($sender) ? 'Куратор' : $sender->name;
        DB::transaction(function () use ($recipients, $sender, $senderName, $data): void {
            foreach ($recipients as $recipient) {
                $message = ChatMessage::query()->create([
                    'sender_id' => $sender->id,
                    'recipient_id' => $recipient->id,
                    'body' => $data['body'],
                ]);

                Notification::query()->create([
                    'user_id' => $recipient->id,
                    'type' => 'chat',
                    'title' => 'Новое сообщение от '.$senderName,
                    'body' => $message->body,
                    'data' => [
                        'chat_message_id' => $message->id,
                        'sender_id' => $sender->id,
                        'chat_notification_key' => 'direct:'.$sender->id,
                    ],
                ]);
            }
        });

        return response()->json(['data' => ['recipients_count' => $recipients->count()]], 201);
    }

    public function update(Request $request, ChatMessage $chatMessage)
    {
        abort_unless($chatMessage->sender_id === $request->user()->id, 403);
        abort_if($chatMessage->attachment_type && ! $chatMessage->body, 422, 'Нельзя заменить вложение текстом.');
        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);
        $chatMessage->update(['body' => $data['body'], 'edited_at' => now()]);
        $chatMessage->load($this->messageRelations());
        return response()->json(['data' => $this->decorateMessage($chatMessage)]);
    }

    public function destroy(Request $request, ChatMessage $chatMessage)
    {
        abort_unless($chatMessage->sender_id === $request->user()->id, 403);

        ChatMessage::query()->where('reply_to_id', $chatMessage->id)->update(['reply_to_id' => null]);
        $chatMessage->reactions()->delete();
        if ($chatMessage->attachment_path) Storage::disk('s3')->delete($chatMessage->attachment_path);
        $chatMessage->delete();

        return response()->noContent();
    }

    public function toggleReaction(Request $request, ChatMessage $chatMessage)
    {
        $data = $request->validate(['emoji' => ['required', 'string', 'max:16']]);
        $reaction = $chatMessage->reactions()->where('user_id', $request->user()->id)->where('emoji', $data['emoji'])->first();
        if ($reaction) $reaction->delete();
        else $chatMessage->reactions()->create(['user_id' => $request->user()->id, 'emoji' => $data['emoji']]);
        $chatMessage->load($this->messageRelations());
        return response()->json(['data' => $this->decorateMessage($chatMessage)]);
    }

    public function mentionables(Request $request)
    {
        $users = User::query()
            ->whereNull('blocked_at')
            ->whereNull('archived_at')
            ->orderBy('name')
            ->get(['id', 'name', 'avatar_path']);
        $media = app(MediaStorage::class);
        $users->each(function (User $user) use ($media): void {
            if ($user->avatar_path) $user->setAttribute('avatar_path', $media->secureCdnUrl($user->avatar_path));
        });

        return response()->json(['data' => $users]);
    }

    public function notificationPreferences(Request $request)
    {
        return response()->json(['data' => ChatNotificationPreference::query()->where('user_id', $request->user()->id)->pluck('enabled', 'chat_key')]);
    }

    public function updateNotificationPreference(Request $request)
    {
        $data = $request->validate([
            'chat_key' => ['required', 'string', 'max:80', 'regex:/^(room:(general|important-info)|direct:[1-9][0-9]*)$/'],
            'enabled' => ['required', 'boolean'],
        ]);
        if (str_starts_with($data['chat_key'], 'direct:')) {
            $peer = $this->resolvePeer($request, (int) substr($data['chat_key'], 7));
            abort_unless($peer, 422, 'Недоступный чат.');
        }
        ChatNotificationPreference::query()->updateOrCreate(
            ['user_id' => $request->user()->id, 'chat_key' => $data['chat_key']],
            ['enabled' => $data['enabled']],
        );

        return response()->json(['data' => ['chat_key' => $data['chat_key'], 'enabled' => (bool) $data['enabled']]]);
    }

    private function resolvePeer(Request $request, ?int $peerId): ?User
    {
        $user = $request->user();
        if ($this->isStaff($user) || $this->isChatCurator($user)) return User::query()->where('role', 'client')->when(! $this->isChatCurator($user) && ! in_array($user->role->value, ['admin', 'curator'], true), fn ($q) => $q->whereHas('clientProfile', fn ($p) => $p->where('trainer_id', $user->id)))->when($peerId, fn ($q) => $q->whereKey($peerId))->orderBy('name')->first();
        return $this->supportTeam()->first(fn (User $peer) => ! $peerId || $peer->id === $peerId);
    }

    private function supportTeam()
    {
        // The account was created before the profile-name normalisation, so
        // its stored name may be ordered differently. There is one support
        // administrator exposed in personal chats; always present it under
        // the agreed public name.
        $admin = User::query()->where('role', 'admin')->orderBy('id')->first(['id', 'name', 'role', 'avatar_path']);
        $curator = User::query()->find(self::CHAT_CURATOR_ID, ['id', 'name', 'role', 'avatar_path']);
        $team = collect([$admin, $curator])->filter()->unique('id')->values();
        $team->each(function (User $member) use ($admin, $curator): void {
            if ($admin && $member->id === $admin->id) $member->setAttribute('name', 'Лазарева Анастасия');
            if ($curator && $member->id === $curator->id) {
                $member->setAttribute('name', 'Куратор');
                $member->setAttribute('avatar_path', null);
                $member->setAttribute('role', 'client');
            }
        });
        return $team;
    }

    private function generalRoom(): ChatRoom
    {
        return ChatRoom::query()->firstOrCreate(['slug' => 'general'], ['name' => 'Чат ОБЩЕНИЕ']);
    }

    private function importantRoom(): ChatRoom
    {
        return ChatRoom::query()->firstOrCreate(['slug' => 'important-info'], ['name' => 'ИНФО']);
    }

    private function conversation(Request $request)
    {
        abort_unless($request->user()->role->value === 'admin', 403);
        $data = $request->validate(['participant_a_id' => ['required', 'integer', 'different:participant_b_id', 'exists:users,id'], 'participant_b_id' => ['required', 'integer', 'exists:users,id']]);
        $messages = ChatMessage::query()->whereNull('chat_room_id')->where(fn ($q) => $q->where(fn ($i) => $i->where('sender_id', $data['participant_a_id'])->where('recipient_id', $data['participant_b_id']))->orWhere(fn ($i) => $i->where('sender_id', $data['participant_b_id'])->where('recipient_id', $data['participant_a_id'])))->with($this->messageRelations())->latest()->limit(100)->get()->reverse()->values();
        return response()->json(['data' => $this->decorateMessages($messages)]);
    }

    private function decorateMessages($messages)
    {
        return $messages->map(fn (ChatMessage $message) => $this->decorateMessage($message))->values();
    }

    private function decorateMessage(ChatMessage $message): ChatMessage
    {
        if ($message->attachment_path) $message->setAttribute('attachment_path', app(MediaStorage::class)->secureCdnUrl($message->attachment_path));
        $this->decorateParticipant($message->sender, $message->chat_room_id === null);
        if ($message->replyTo) $this->decorateParticipant($message->replyTo->sender, $message->chat_room_id === null);
        return $message;
    }

    private function decorateConversations($conversations)
    {
        return $conversations->map(function (ChatMessage $message): ChatMessage {
            foreach ([$message->sender, $message->recipient] as $participant) $this->decorateParticipant($participant, true);
            return $this->decorateMessage($message);
        })->values();
    }

    private function decorateParticipant(?User $participant, bool $isDirectChat): void
    {
        if (! $participant) return;
        if ($isDirectChat && (int) $participant->id === self::CHAT_CURATOR_ID) {
            $participant->setAttribute('name', 'Куратор');
            $participant->setAttribute('avatar_path', null);
            $participant->setAttribute('role', 'client');
            return;
        }
        if ($participant->avatar_path) $participant->setAttribute('avatar_path', app(MediaStorage::class)->secureCdnUrl($participant->avatar_path));
    }

    private function messageRelations(): array
    {
        return ['sender:id,name,avatar_path', 'replyTo.sender:id,name,avatar_path', 'reactions.user:id,name'];
    }

    private function conversationRelations(): array
    {
        return ['sender:id,name,role,avatar_path', 'recipient:id,name,role,avatar_path'];
    }

    private function preview(ChatMessage $message): string
    {
        return $message->body ?: ($message->attachment_type === 'voice' ? 'Голосовое сообщение' : 'Фото');
    }

    private function isStaff(User $user): bool
    {
        return in_array($user->role->value, ['curator', 'trainer', 'admin'], true);
    }

    private function canUseRoomChats(User $user): bool
    {
        return $user->role->value !== 'client' || ! in_array($user->staff_status, ['newcomer', 'dropped_out'], true);
    }

    /**
     * Chat-only access for Anna Averyanova. Her application role deliberately
     * remains client; this exception must not grant rights outside chat.
     */
    private function isChatCurator(User $user): bool
    {
        return (int) $user->id === self::CHAT_CURATOR_ID;
    }

    private function canBroadcast(User $user): bool
    {
        return $user->role->value === 'admin' || $this->isChatCurator($user);
    }
}
