<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { api } from '@/services/api';
import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();
const peers = ref([]);
const general = ref(null);
const important = ref(null);
const curatorConversations = ref([]);
const showCuratorConversations = ref(false);
const searchQuery = ref('');
const broadcastOpen = ref(false);
const broadcastSearch = ref('');
const broadcastRecipientIds = ref([]);
const broadcastBody = ref('');
const broadcastSending = ref(false);
const broadcastError = ref('');
const messages = ref([]);
const messagesLoading = ref(false);
const activeChat = ref({ type: 'general', peerId: null });
const mobileListOpen = ref(true);
const body = ref('');
const replyTo = ref(null);
const editingMessage = ref(null);
const contextMessage = ref(null);
const mentionables = ref([]);
const listRef = ref(null);
const photoInput = ref(null);
const activePhoto = ref(null);
const sending = ref(false);
const recording = ref(false);
const chatError = ref('');
const chatPushChanging = ref(false);
const chatPushPreferences = ref({});
const hiddenMessageAvatarIds = ref(new Set());
const voicePlaybackRates = ref({});
const voicePlayers = new Map();
let mediaRecorder; let recordingStream; let audioChunks = []; let messagePressTimer;
let messagesRequestId = 0;

const isGeneral = computed(() => activeChat.value.type === 'general');
const isImportant = computed(() => activeChat.value.type === 'important');
const isRoom = computed(() => isGeneral.value || isImportant.value);
const activeRoom = computed(() => isImportant.value ? important.value : general.value);
const activeRoomSlug = computed(() => isImportant.value ? 'important-info' : 'general');
const isAdmin = computed(() => auth.user?.role === 'admin');
const canBroadcast = computed(() => isAdmin.value || Number(auth.user?.id) === 10);
const isConversationView = computed(() => activeChat.value.type === 'curator-view');
const selectedPeer = computed(() => peers.value.find((peer) => peer.id === activeChat.value.peerId) ?? null);
const activeConversation = computed(() => activeChat.value.conversation ?? null);
const chatNotificationKey = computed(() => isRoom.value ? `room:${activeRoomSlug.value}` : (selectedPeer.value ? `direct:${selectedPeer.value.id}` : null));
const chatPushEnabled = computed(() => chatNotificationKey.value ? chatPushPreferences.value[chatNotificationKey.value] !== false : true);
const chatName = computed(() => isRoom.value ? (activeRoom.value?.name ?? 'Общий чат') : selectedPeer.value?.name ?? 'Чат');
const chatSubtitle = computed(() => isRoom.value ? (isImportant.value ? 'Только для важной информации' : 'Все участники проекта') : (selectedPeer.value?.role === 'client' ? '' : (selectedPeer.value?.role === 'admin' ? 'Администратор' : 'Куратор')));
const chatItems = computed(() => [
    ...(important.value ? [{ type: 'important', id: 'important-info', name: important.value.name, subtitle: 'Важная информация от администратора', icon: 'campaign', ...important.value }] : []),
    ...(general.value ? [{ type: 'general', id: 'general', name: general.value.name, subtitle: 'Все участники проекта', icon: 'groups', ...general.value }] : []),
    ...peers.value.map((peer) => ({ type: 'direct', id: peer.id, subtitle: peer.role === 'admin' ? 'Администратор' : 'Куратор', icon: 'person', ...peer })),
]);

function formatTime(value) {
    if (!value) return '';
    const date = new Date(value); const today = new Date();
    if (date.toDateString() !== today.toDateString()) return new Intl.DateTimeFormat('ru-RU', { day: '2-digit', month: '2-digit' }).format(date);
    return new Intl.DateTimeFormat('ru-RU', { hour: '2-digit', minute: '2-digit' }).format(date);
}
function formatUnread(count) { return count > 99 ? '99+' : String(count); }
function voiceExtension(mimeType) { return mimeType.includes('mp4') ? 'm4a' : mimeType.includes('ogg') ? 'ogg' : 'webm'; }
function voicePlaybackRate(messageId) { return voicePlaybackRates.value[messageId] ?? 1; }
function setVoicePlayer(messageId, player) { if (player) voicePlayers.set(messageId, player); else voicePlayers.delete(messageId); }
function setVoicePlaybackRate(messageId, event) {
    const rate = Number(event.target.value);
    voicePlaybackRates.value = { ...voicePlaybackRates.value, [messageId]: rate };
    const player = voicePlayers.get(messageId);
    if (player) player.playbackRate = rate;
}
function preview(item) { return item.last_message || 'Сообщений пока нет'; }
function messageParts(value) {
    return String(value ?? '').split(/(https?:\/\/[^\s<]+)/gi).filter(Boolean).map((part) => ({
        value: part,
        isLink: /^https?:\/\//i.test(part),
    }));
}

const orderedOwnChatItems = computed(() => [...chatItems.value].sort((first, second) => {
    if (first.type === 'important') return -1;
    if (second.type === 'important') return 1;
    if (first.type === 'general') return -1;
    if (second.type === 'general') return 1;
    const unreadDifference = Number(Boolean(second.unread_count)) - Number(Boolean(first.unread_count));
    if (unreadDifference) return unreadDifference;
    return new Date(second.last_message_at || 0).getTime() - new Date(first.last_message_at || 0).getTime();
}));
const displayedChatItems = computed(() => showCuratorConversations.value
    ? curatorConversations.value.map((conversation) => ({
        type: 'curator-view', id: conversation.id, icon: 'forum', conversation,
        name: `${conversation.sender?.name ?? 'Участник'} — ${conversation.recipient?.name ?? 'Участник'}`,
        last_message: conversation.body || 'Сообщений пока нет', last_message_at: conversation.created_at,
    }))
    : orderedOwnChatItems.value);
const filteredChatItems = computed(() => {
    const query = searchQuery.value.trim().toLocaleLowerCase('ru-RU');
    if (!query) return displayedChatItems.value;
    return displayedChatItems.value.filter((item) => `${item.name ?? ''} ${item.subtitle ?? ''}`.toLocaleLowerCase('ru-RU').includes(query));
});
const filteredBroadcastRecipients = computed(() => {
    const query = broadcastSearch.value.trim().toLocaleLowerCase('ru-RU');
    if (!query) return peers.value;
    return peers.value.filter((peer) => peer.name.toLocaleLowerCase('ru-RU').includes(query));
});
const allVisibleBroadcastSelected = computed(() => filteredBroadcastRecipients.value.length > 0
    && filteredBroadcastRecipients.value.every((peer) => broadcastRecipientIds.value.includes(peer.id)));
const selectedBroadcastRecipients = computed(() => peers.value
    .filter((peer) => broadcastRecipientIds.value.includes(peer.id)));
const directoryAvatarById = computed(() => new Map(
    [...mentionables.value, ...peers.value]
        .filter((user) => user.avatar_path)
        .map((user) => [user.id, user.avatar_path]),
));

const mentionQuery = computed(() => (body.value.match(/@([^\s@]*)$/)?.[1] ?? '').toLocaleLowerCase('ru-RU'));
const mentionSuggestions = computed(() => mentionQuery.value ? mentionables.value.filter((user) => user.name.toLocaleLowerCase('ru-RU').includes(mentionQuery.value)).slice(0, 5) : []);
const desktopChatLayout = () => window.matchMedia('(min-width: 1024px)').matches;
// Avatar links from the private CDN are signed and expire, so a chat refresh
// must always replace a previously cached link with the current API value.
const preserveAvatar = (_current, next) => next?.avatar_path ?? null;
function messageAvatar(message) {
    if (hiddenMessageAvatarIds.value.has(message.id)) return null;
    // Use the same current URL as the shared chat-user directory instead of
    // keeping a separate signed S3 URL in every message row.
    return directoryAvatarById.value.get(message.sender_id)
        ?? (message.sender_id === auth.user?.id ? auth.user?.avatar_path : message.sender?.avatar_path)
        ?? null;
}
function showMessageAvatar(message) {
    return isRoom.value || Number(message.sender_id) !== 10;
}
function messageAvatarInitials(message) {
    return String(messageSenderName(message))
        .trim()
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0])
        .join('')
        .toLocaleUpperCase('ru-RU') || '•';
}
function hideMessageAvatar(messageId) {
    hiddenMessageAvatarIds.value = new Set([...hiddenMessageAvatarIds.value, messageId]);
}
function messageSenderName(message) {
    if (selectedPeer.value?.id === 10 && Number(message.sender_id) === 10 && Number(auth.user?.id) !== 10)
        return 'Куратор';
    return message.sender?.name ?? 'Пользователь';
}
function preservePeerAvatars(nextPeers) {
    const currentPeers = new Map(peers.value.map((peer) => [peer.id, peer]));
    return nextPeers.map((peer) => ({ ...peer, avatar_path: preserveAvatar(currentPeers.get(peer.id), peer) }));
}
function preserveMessageAvatars(nextMessages) {
    const currentMessages = new Map(messages.value.map((message) => [message.id, message]));
    return nextMessages.map((message) => {
        const previous = currentMessages.get(message.id);
        if (message.sender) message.sender.avatar_path = preserveAvatar(previous?.sender, message.sender);
        return message;
    });
}

async function loadDirectory() {
    const requests = [api.get('/chat/peers'), api.get('/chat/general'), api.get('/chat/important'), api.get('/chat/mentionables')];
    if (isAdmin.value) requests.push(api.get('/chat/curator-conversations'));
    const [peersResponse, generalResponse, importantResponse, mentionablesResponse, conversationsResponse] = await Promise.all(requests);
    peers.value = preservePeerAvatars(peersResponse.data.data ?? []);
    general.value = generalResponse.data.data;
    important.value = importantResponse.data.data;
    mentionables.value = mentionablesResponse.data.data ?? [];
    if (conversationsResponse) curatorConversations.value = conversationsResponse.data.data ?? [];
    if (!general.value && !important.value && isRoom.value)
        activeChat.value = { type: 'direct', peerId: peers.value[0]?.id ?? null };
}
async function loadChatPushPreferences() {
    const { data } = await api.get('/chat/notification-preferences');
    chatPushPreferences.value = data.data ?? {};
}
async function scrollToLatest() {
    await nextTick();
    requestAnimationFrame(() => {
        listRef.value?.scrollTo({ top: listRef.value.scrollHeight });
        // A second frame accounts for the mobile dialog becoming visible.
        requestAnimationFrame(() => listRef.value?.scrollTo({ top: listRef.value.scrollHeight }));
    });
}
async function loadMessages(keepPosition = false) {
    const requestId = ++messagesRequestId;
    if (!keepPosition) messagesLoading.value = true;
    try {
        const response = isRoom.value
            ? await api.get(`/chat/${isImportant.value ? 'important' : 'general'}/messages`, { params: { mark_read: !keepPosition } })
            : (isConversationView.value && activeConversation.value ? await api.get('/chat/messages', { params: { participant_a_id: activeConversation.value.sender_id, participant_b_id: activeConversation.value.recipient_id } })
            : (selectedPeer.value ? await api.get('/chat/messages', { params: { peer_id: selectedPeer.value.id } }) : null));
        if (requestId !== messagesRequestId) return;
        messages.value = preserveMessageAvatars(response?.data.data ?? []);
        if (isRoom.value && activeRoom.value && !keepPosition) activeRoom.value.unread_count = 0;
        if (!isRoom.value && selectedPeer.value) selectedPeer.value.unread_count = 0;
        if (!keepPosition || (!isRoom.value && !isConversationView.value))
            window.dispatchEvent(new Event('novaya-ya:chat-read'));
        if (!keepPosition) await scrollToLatest();
    } finally {
        if (requestId === messagesRequestId) messagesLoading.value = false;
    }
}
async function openChat(item) {
    messages.value = [];
    activeChat.value = item.type === 'general' || item.type === 'important' ? { type: item.type, peerId: null } : (item.type === 'curator-view' ? { type: 'curator-view', conversation: item.conversation } : { type: 'direct', peerId: item.id });
    mobileListOpen.value = false;
    await loadMessages();
}
function showChatList() { mobileListOpen.value = true; }
async function openCuratorConversation(conversation) {
    showCuratorConversations.value = true;
    await openChat({ type: 'curator-view', conversation });
}
async function returnToOwnChats() {
    showCuratorConversations.value = false;
    await openChat({ type: 'general' });
}
async function refreshForNotification(event) {
    const notification = event instanceof CustomEvent ? event.detail : event;
    if (notification?.type !== 'chat') return;
    const tasks = [loadDirectory().catch(() => undefined)];
    if (!mobileListOpen.value || desktopChatLayout()) tasks.push(loadMessages(true));
    await Promise.all(tasks);
}

async function send({ photo = null, voice = null } = {}) {
    const messageBody = body.value.trim();
    if ((!messageBody && !photo && !voice) || sending.value || isConversationView.value || (isImportant.value && !isAdmin.value) || (!isRoom.value && !selectedPeer.value)) return;
    sending.value = true; chatError.value = '';
    try {
        if (editingMessage.value) {
            const { data } = await api.patch(`/chat/messages/${editingMessage.value.id}`, { body: messageBody });
            const index = messages.value.findIndex((message) => message.id === data.data.id);
            if (index >= 0) messages.value[index] = data.data;
            body.value = ''; editingMessage.value = null;
            return;
        }
        const payload = photo || voice ? new FormData() : (isRoom.value ? { room_slug: activeRoomSlug.value, body: messageBody, reply_to_id: replyTo.value?.id } : { recipient_id: selectedPeer.value.id, body: messageBody, reply_to_id: replyTo.value?.id });
        if (payload instanceof FormData) {
            if (isRoom.value) payload.append('room_slug', activeRoomSlug.value); else payload.append('recipient_id', String(selectedPeer.value.id));
            if (messageBody) payload.append('body', messageBody);
            if (replyTo.value) payload.append('reply_to_id', String(replyTo.value.id));
            if (photo) payload.append('photo', photo);
            if (voice) payload.append('voice', voice);
        }
        const { data } = await api.post('/chat/messages', payload);
        messages.value.push(data.data); body.value = ''; replyTo.value = null;
        await scrollToLatest();
        await loadDirectory();
    } catch (error) {
        chatError.value = error.response?.data?.message ?? 'Не удалось отправить сообщение.';
    } finally { sending.value = false; }
}
function startReply(message) { replyTo.value = message; editingMessage.value = null; }
function startEdit(message) { editingMessage.value = message; replyTo.value = null; body.value = message.body ?? ''; }
function cancelComposerMode() { replyTo.value = null; editingMessage.value = null; body.value = ''; }
function startMessagePress(message) { window.clearTimeout(messagePressTimer); messagePressTimer = window.setTimeout(() => { contextMessage.value = message; }, 500); }
function cancelMessagePress() { window.clearTimeout(messagePressTimer); }
function closeMessageMenu() { contextMessage.value = null; }
function replyFromMenu() { startReply(contextMessage.value); closeMessageMenu(); }
function editFromMenu() { startEdit(contextMessage.value); closeMessageMenu(); }
async function deleteFromMenu() {
    const message = contextMessage.value;
    if (!message || Number(message.sender_id) !== Number(auth.user?.id)) return;
    try {
        await api.delete(`/chat/messages/${message.id}`);
        messages.value = messages.value.filter((item) => item.id !== message.id);
        if (replyTo.value?.id === message.id || editingMessage.value?.id === message.id) cancelComposerMode();
        await loadDirectory();
    } catch (error) {
        chatError.value = error.response?.data?.message ?? 'Не удалось удалить сообщение.';
    } finally {
        closeMessageMenu();
    }
}
async function reactFromMenu(emoji) { await toggleReaction(contextMessage.value, emoji); closeMessageMenu(); }
function insertMention(user) { body.value = body.value.replace(/@([^\s@]*)$/, `@${user.name} `); }
async function toggleReaction(message, emoji) {
    const { data } = await api.post(`/chat/messages/${message.id}/reactions`, { emoji });
    const index = messages.value.findIndex((item) => item.id === message.id);
    if (index >= 0) messages.value[index] = data.data;
}
async function toggleChatPush() {
    if (chatPushChanging.value) return;
    chatPushChanging.value = true;
    try {
        if (!chatNotificationKey.value) return;
        const { data } = await api.patch('/chat/notification-preferences', { chat_key: chatNotificationKey.value, enabled: !chatPushEnabled.value });
        chatPushPreferences.value = { ...chatPushPreferences.value, [data.data.chat_key]: data.data.enabled };
        window.dispatchEvent(new CustomEvent('novaya-ya:chat-push-preference', { detail: data.data }));
    } catch {
        chatError.value = 'Не удалось изменить настройку уведомлений чата.';
    } finally { chatPushChanging.value = false; }
}
function openBroadcast() {
    broadcastRecipientIds.value = [];
    broadcastSearch.value = '';
    broadcastBody.value = '';
    broadcastError.value = '';
    broadcastOpen.value = true;
}
function toggleVisibleBroadcastRecipients() {
    const visibleIds = filteredBroadcastRecipients.value.map((peer) => peer.id);
    broadcastRecipientIds.value = allVisibleBroadcastSelected.value
        ? broadcastRecipientIds.value.filter((id) => !visibleIds.includes(id))
        : [...new Set([...broadcastRecipientIds.value, ...visibleIds])];
}
function removeBroadcastRecipient(userId) {
    broadcastRecipientIds.value = broadcastRecipientIds.value.filter((id) => id !== userId);
}
async function sendBroadcast() {
    if (!broadcastRecipientIds.value.length || !broadcastBody.value.trim() || broadcastSending.value) return;
    broadcastSending.value = true;
    broadcastError.value = '';
    try {
        const { data } = await api.post('/chat/broadcast', {
            recipient_ids: broadcastRecipientIds.value,
            body: broadcastBody.value.trim(),
        });
        broadcastOpen.value = false;
        chatError.value = `Сообщение отправлено: ${data.data.recipients_count} получ.`;
        await loadDirectory();
    } catch (error) {
        broadcastError.value = error.response?.data?.message ?? 'Не удалось отправить рассылку.';
    } finally { broadcastSending.value = false; }
}
function selectPhoto(event) { const [photo] = event.target.files ?? []; if (photo) send({ photo }); event.target.value = ''; }
async function toggleVoiceRecording() {
    if (recording.value) { mediaRecorder?.stop(); return; }
    if (!navigator.mediaDevices?.getUserMedia) { chatError.value = 'Запись голоса не поддерживается в этом браузере.'; return; }
    try {
        recordingStream = await navigator.mediaDevices.getUserMedia({ audio: true }); audioChunks = [];
        const mimeType = ['audio/webm;codecs=opus', 'audio/ogg;codecs=opus', 'audio/mp4'].find((type) => MediaRecorder.isTypeSupported(type));
        mediaRecorder = new MediaRecorder(recordingStream, mimeType ? { mimeType } : undefined);
        mediaRecorder.ondataavailable = (event) => { if (event.data.size) audioChunks.push(event.data); };
        mediaRecorder.onstop = () => { recording.value = false; recordingStream?.getTracks().forEach((track) => track.stop()); const type = mediaRecorder.mimeType || mimeType || 'audio/webm'; const voice = new File([new Blob(audioChunks, { type })], `voice-${Date.now()}.${voiceExtension(type)}`, { type }); if (voice.size) send({ voice }); };
        mediaRecorder.start(); recording.value = true;
    } catch { chatError.value = 'Не удалось получить доступ к микрофону.'; }
}
onMounted(async () => { await Promise.all([loadDirectory(), loadChatPushPreferences()]); if (desktopChatLayout()) await loadMessages(); window.addEventListener('novaya-ya:notification', refreshForNotification); window.addEventListener('novaya-ya:show-chat-list', showChatList); });
onBeforeUnmount(() => { window.removeEventListener('novaya-ya:notification', refreshForNotification); window.removeEventListener('novaya-ya:show-chat-list', showChatList); window.clearTimeout(messagePressTimer); if (mediaRecorder?.state === 'recording') mediaRecorder.stop(); recordingStream?.getTracks().forEach((track) => track.stop()); });
</script>

<template>
  <section class="chat-page min-w-0">
    <div class="mb-5 hidden lg:block">
      <p class="text-xs font-semibold uppercase tracking-[0.16em] text-primary/80">Сопровождение</p>
      <h2 class="mt-2 text-[32px] font-extrabold leading-10">Чат с командой</h2>
    </div>
    <article class="chat-shell glass-panel grid min-w-0 overflow-hidden rounded-[28px] lg:h-[68vh] lg:grid-cols-[300px_1fr]">
      <aside class="chat-directory brand-scrollbar min-h-0 overflow-y-auto border-white/10 lg:border-r lg:p-3" :class="mobileListOpen ? 'flex' : 'hidden lg:flex'">
        <label class="relative mx-3 mt-3 block">
          <span class="material-symbols-outlined pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[20px] text-on-muted">search</span>
          <input v-model="searchQuery" class="w-full rounded-xl border border-white/10 bg-surface-low py-2.5 pl-10 pr-3 text-sm text-on-surface outline-none placeholder:text-on-muted/70 focus:border-primary/60" type="search" placeholder="Поиск по имени или фамилии" />
        </label>
        <div v-if="isAdmin" class="grid gap-1 border-b border-white/10 p-2 lg:grid-cols-2">
          <button class="rounded-xl px-2 py-2 text-xs font-bold" :class="!showCuratorConversations ? 'bg-primary text-[#470382]' : 'text-on-muted hover:bg-white/5'" type="button" @click="returnToOwnChats">Мои чаты</button>
          <button class="rounded-xl px-2 py-2 text-xs font-bold" :class="showCuratorConversations ? 'bg-primary text-[#470382]' : 'text-on-muted hover:bg-white/5'" type="button" @click="showCuratorConversations = true">Чаты куратора</button>
        </div>
        <div class="sticky top-0 z-10 flex items-center justify-between gap-3 border-b border-white/10 bg-surface-container/95 px-5 py-4 backdrop-blur lg:hidden"><div><h2 class="text-2xl font-extrabold">Чаты</h2><p class="mt-1 text-sm text-on-muted">Сопровождение и поддержка</p></div><button v-if="canBroadcast" class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-primary/15 text-primary transition hover:bg-primary/25" type="button" title="Сделать рассылку" aria-label="Сделать рассылку" @click="openBroadcast"><span class="material-symbols-outlined">campaign</span></button></div>
        <p class="hidden px-3 pb-3 pt-2 text-xs font-bold uppercase text-on-muted lg:block">Диалоги</p>
        <button v-for="item in filteredChatItems" :key="`${item.type}-${item.id}`" class="chat-item flex w-full items-center gap-3 px-5 py-4 text-left transition lg:mb-1 lg:rounded-2xl lg:p-3" :class="(isRoom && item.type === activeChat.type) || (!isRoom && item.id === selectedPeer?.id) ? 'bg-primary/15 text-primary' : 'text-on-surface hover:bg-white/5'" type="button" @click="openChat(item)">
          <span class="grid h-12 w-12 shrink-0 place-items-center overflow-hidden rounded-full bg-surface-high text-primary"><img v-if="item.avatar_path" class="h-full w-full object-cover" :src="item.avatar_path" :alt="`Аватар ${item.name}`" @error="item.avatar_path = null" /><span v-else class="material-symbols-outlined">{{ item.icon }}</span></span>
          <span class="min-w-0 flex-1"><span class="flex items-start justify-between gap-2"><strong class="text-sm" :class="item.type === 'curator-view' ? 'whitespace-normal break-words leading-5' : 'truncate'">{{ item.name }}</strong><small class="shrink-0 pt-0.5 text-[11px] font-semibold text-on-muted">{{ formatTime(item.last_message_at) }}</small></span><span class="mt-1 flex items-center gap-2"><span class="min-w-0 flex-1 truncate text-xs text-on-muted">{{ preview(item) }}</span><span v-if="item.unread_count" class="grid h-5 min-w-5 place-items-center rounded-full bg-danger-container px-1 text-[10px] font-extrabold text-danger">{{ formatUnread(item.unread_count) }}</span></span></span>
        </button>
      </aside>
      <div class="chat-dialog grid min-h-0 grid-rows-[auto_1fr_auto]" :class="mobileListOpen ? 'hidden lg:grid' : 'grid'">
        <header class="flex min-w-0 items-center gap-3 border-b border-white/10 px-4 py-3 sm:px-5"><button class="grid h-10 w-8 shrink-0 place-items-center text-primary lg:hidden" type="button" aria-label="Вернуться к чатам" @click="showChatList"><span class="material-symbols-outlined">arrow_back</span></button><span class="grid h-10 w-10 shrink-0 place-items-center overflow-hidden rounded-full bg-primary/15 text-primary"><img v-if="selectedPeer?.avatar_path && !isRoom" class="h-full w-full object-cover" :src="selectedPeer.avatar_path" :alt="`Аватар ${chatName}`" @error="selectedPeer.avatar_path = null" /><span v-else class="material-symbols-outlined">{{ isRoom ? (isImportant ? 'campaign' : 'groups') : 'person' }}</span></span><div class="min-w-0 flex-1"><strong class="block truncate text-sm">{{ chatName }}</strong><span class="block truncate text-xs text-on-muted">{{ chatSubtitle }}</span></div><button v-if="canBroadcast" class="hidden h-10 w-10 shrink-0 place-items-center rounded-xl text-on-muted hover:bg-white/5 hover:text-primary lg:grid" type="button" title="Сделать рассылку" aria-label="Сделать рассылку" @click="openBroadcast"><span class="material-symbols-outlined">campaign</span></button><button class="grid h-10 w-10 shrink-0 place-items-center rounded-xl text-on-muted hover:bg-white/5 hover:text-primary disabled:opacity-50" type="button" :disabled="chatPushChanging" :title="chatPushEnabled ? 'Отключить push-уведомления чата' : 'Включить push-уведомления чата'" :aria-label="chatPushEnabled ? 'Отключить push-уведомления чата' : 'Включить push-уведомления чата'" :aria-pressed="chatPushEnabled" @click="toggleChatPush"><span class="material-symbols-outlined">{{ chatPushEnabled ? 'notifications' : 'notifications_off' }}</span></button></header>
        <div ref="listRef" class="brand-scrollbar grid min-h-0 content-start gap-3 overflow-y-auto p-4 sm:p-5">
          <div v-for="message in messages" :key="message.id" class="flex items-end gap-2" :class="message.sender_id === auth.user?.id ? 'justify-end' : 'justify-start'">
            <span v-if="message.sender_id !== auth.user?.id && showMessageAvatar(message)" class="grid h-8 w-8 shrink-0 place-items-center overflow-hidden rounded-full bg-primary/15 text-[10px] font-extrabold text-primary"><img v-if="messageAvatar(message)" class="h-full w-full object-cover" :src="messageAvatar(message)" :alt="`Аватар ${message.sender?.name ?? 'пользователя'}`" @error="hideMessageAvatar(message.id)" /><span v-else aria-hidden="true">{{ messageAvatarInitials(message) }}</span></span>
            <div class="max-w-[88%] select-none rounded-2xl p-3 text-sm leading-6 sm:max-w-[75%]" :class="message.sender_id === auth.user?.id ? 'rounded-br-md bg-primary text-[#470382]' : 'rounded-bl-md bg-surface-container text-on-surface'" @pointerdown="startMessagePress(message)" @pointerup="cancelMessagePress" @pointerleave="cancelMessagePress" @pointercancel="cancelMessagePress" @contextmenu.prevent="contextMessage = message">
              <div v-if="isRoom || message.sender_id !== auth.user?.id" class="mb-1 flex justify-between gap-3 text-xs font-extrabold"><span>{{ messageSenderName(message) }}</span><span>{{ formatTime(message.created_at) }}</span></div>
              <button v-if="message.reply_to" class="mb-2 block w-full border-l-2 border-primary/70 bg-black/10 px-2 text-left text-xs" type="button" @click="startReply(message.reply_to)">{{ messageSenderName(message.reply_to) }}: {{ message.reply_to.body }}</button>
              <p v-if="message.body" class="whitespace-pre-wrap break-words"><template v-for="(part, index) in messageParts(message.body)" :key="`${message.id}-${index}`"><a v-if="part.isLink" class="break-all font-semibold underline decoration-current/70 underline-offset-2 hover:opacity-80" :href="part.value" target="_blank" rel="noopener noreferrer" @click.stop>{{ part.value }}</a><template v-else>{{ part.value }}</template></template> <small v-if="message.edited_at" class="opacity-60">(изм.)</small></p>
              <button v-if="message.attachment_type === 'photo'" class="mt-2 block overflow-hidden rounded-xl" type="button" @click="activePhoto = message.attachment_path"><img class="max-h-80 rounded-xl object-cover" :src="message.attachment_path" alt="Фото" @load="scrollToLatest" /></button>
              <div v-else-if="message.attachment_type === 'voice'" class="mt-2 flex items-center gap-2">
                <audio :ref="(player) => setVoicePlayer(message.id, player)" class="chat-voice" controls @loadedmetadata="scrollToLatest"><source :src="message.attachment_path" type="audio/mp4" /></audio>
                <label class="sr-only" :for="`voice-rate-${message.id}`">Скорость воспроизведения</label>
                <select :id="`voice-rate-${message.id}`" class="chat-voice-rate" :value="voicePlaybackRate(message.id)" title="Скорость воспроизведения" aria-label="Скорость воспроизведения" @pointerdown.stop @change="setVoicePlaybackRate(message.id, $event)">
                  <option :value="1">1×</option>
                  <option :value="1.5">1.5×</option>
                  <option :value="2">2×</option>
                </select>
              </div>
              <div v-if="message.reactions?.length" class="mt-2 flex flex-wrap gap-1 text-xs"><span v-for="reaction in message.reactions" :key="reaction.id" class="rounded-full bg-black/15 px-1">{{ reaction.emoji }}</span></div>
            </div>
            <span v-if="message.sender_id === auth.user?.id && showMessageAvatar(message)" class="grid h-8 w-8 shrink-0 place-items-center overflow-hidden rounded-full bg-primary/15 text-[10px] font-extrabold text-primary"><img v-if="messageAvatar(message)" class="h-full w-full object-cover" :src="messageAvatar(message)" alt="Ваш аватар" @error="hideMessageAvatar(message.id)" /><span v-else aria-hidden="true">{{ messageAvatarInitials(message) }}</span></span>
          </div>
          <p v-if="messagesLoading" class="rounded-2xl border border-white/10 bg-surface-container p-4 text-sm text-on-muted">Загрузка сообщений…</p>
          <p v-else-if="!messages.length" class="rounded-2xl border border-white/10 bg-surface-container p-4 text-sm text-on-muted">Сообщений пока нет. Начните диалог.</p>
        </div>
        <div v-if="isImportant && !isAdmin" class="border-t border-white/10 bg-surface-container/70 px-4 py-5 text-center text-sm text-on-muted">Публиковать сообщения в этом чате может только администратор.</div>
        <form v-else class="relative flex items-end gap-2 border-t border-white/10 bg-surface-container/70 p-3 sm:p-4" @submit.prevent="send">
          <div v-if="replyTo || editingMessage" class="absolute bottom-full left-0 right-0 flex items-center justify-between border-t border-white/10 bg-surface-high px-4 py-2 text-xs"><span class="truncate">{{ editingMessage ? 'Редактирование сообщения' : `Ответ: ${messageSenderName(replyTo)}` }}</span><button type="button" aria-label="Отменить" @click="cancelComposerMode"><span class="material-symbols-outlined text-[18px]">close</span></button></div>
          <div v-if="mentionSuggestions.length" class="absolute bottom-full left-3 right-3 max-h-44 overflow-y-auto rounded-t-2xl border border-white/10 bg-surface-highest p-1"><button v-for="user in mentionSuggestions" :key="user.id" class="block w-full rounded-xl px-3 py-2 text-left text-sm hover:bg-white/5" type="button" @click="insertMention(user)">@{{ user.name }}</button></div>
          <input ref="photoInput" class="sr-only" type="file" accept="image/jpeg,image/png,image/webp" @change="selectPhoto" />
          <button class="grid h-11 w-11 shrink-0 place-items-center rounded-xl text-on-muted hover:text-primary disabled:opacity-40" type="button" :disabled="sending" aria-label="Прикрепить фото" @click="photoInput?.click()"><span class="material-symbols-outlined">add_photo_alternate</span></button>
          <button class="grid h-11 w-11 shrink-0 place-items-center rounded-xl transition disabled:opacity-40" :class="recording ? 'bg-red-500/20 text-red-200' : 'text-on-muted hover:text-primary'" type="button" :disabled="sending" @click="toggleVoiceRecording"><span class="material-symbols-outlined">{{ recording ? 'stop' : 'mic' }}</span></button>
          <textarea v-model="body" rows="1" class="min-w-0 flex-1 resize-none rounded-2xl border border-white/10 bg-surface-low px-4 py-3 text-[16px] text-on-surface outline-none focus:border-primary/50" placeholder="Сообщение" />
          <button class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-primary text-[#470382] disabled:opacity-40" type="submit" :disabled="sending || !body.trim()" aria-label="Отправить"><span class="material-symbols-outlined">send</span></button>
        </form>
        <p v-if="chatError" class="absolute bottom-20 left-4 right-4 rounded-xl border border-red-400/25 bg-red-500/10 px-3 py-2 text-xs font-semibold text-red-200">{{ chatError }}</p>
      </div>
    </article>
    <div v-if="contextMessage" class="fixed inset-0 z-[190] flex items-end justify-center bg-black/45 p-4 sm:items-center" role="dialog" aria-modal="true" aria-label="Действия с сообщением" @click.self="closeMessageMenu">
      <div class="w-full max-w-sm rounded-3xl border border-white/10 bg-surface-highest p-2 shadow-2xl">
        <button class="flex w-full items-center gap-3 rounded-2xl px-4 py-3 text-left text-sm font-semibold hover:bg-white/5" type="button" @click="replyFromMenu"><span class="material-symbols-outlined text-primary">reply</span>Ответить</button>
        <div class="flex items-center gap-2 px-4 py-2"><span class="text-sm text-on-muted">Реакция</span><button v-for="emoji in ['❤️', '👍', '🔥', '👏']" :key="emoji" class="grid h-10 w-10 place-items-center rounded-full bg-surface-high text-lg hover:bg-primary/20" type="button" @click="reactFromMenu(emoji)">{{ emoji }}</button></div>
        <button v-if="contextMessage.sender_id === auth.user?.id" class="flex w-full items-center gap-3 rounded-2xl px-4 py-3 text-left text-sm font-semibold hover:bg-white/5" type="button" @click="editFromMenu"><span class="material-symbols-outlined text-primary">edit</span>Изменить</button>
        <button v-if="contextMessage.sender_id === auth.user?.id" class="flex w-full items-center gap-3 rounded-2xl px-4 py-3 text-left text-sm font-semibold text-red-200 hover:bg-red-500/10" type="button" @click="deleteFromMenu"><span class="material-symbols-outlined">delete</span>Удалить</button>
        <button class="mt-1 w-full rounded-2xl px-4 py-3 text-sm text-on-muted hover:bg-white/5" type="button" @click="closeMessageMenu">Отмена</button>
      </div>
    </div>
    <div v-if="broadcastOpen" class="fixed inset-0 z-[195] flex items-end justify-center bg-black/70 p-4 backdrop-blur-sm sm:items-center" role="dialog" aria-modal="true" aria-labelledby="broadcast-title" @click.self="broadcastOpen = false">
      <form class="flex max-h-[calc(100dvh-1rem)] w-full max-w-2xl flex-col overflow-hidden rounded-3xl border border-white/10 bg-surface-highest shadow-2xl sm:max-h-[90dvh]" @submit.prevent="sendBroadcast">
        <div class="flex items-start justify-between gap-4 border-b border-white/10 p-5"><div><p class="text-xs font-bold uppercase tracking-[0.14em] text-primary">Сообщения</p><h3 id="broadcast-title" class="mt-1 text-xl font-extrabold">Массовая рассылка</h3><p class="mt-1 text-sm text-on-muted">Выберите участниц и отправьте им одно сообщение.</p></div><button class="grid h-10 w-10 shrink-0 place-items-center rounded-xl text-on-muted hover:bg-white/5" type="button" aria-label="Закрыть" :disabled="broadcastSending" @click="broadcastOpen = false"><span class="material-symbols-outlined">close</span></button></div>
        <div class="grid min-h-0 flex-1 gap-4 overflow-y-auto p-4 sm:grid-cols-2 sm:p-5">
          <div class="min-h-0"><label class="relative block"><span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-[20px] text-on-muted">search</span><input v-model.trim="broadcastSearch" class="w-full rounded-xl border border-white/10 bg-surface-low py-2.5 pl-10 pr-3 text-sm text-on-surface outline-none focus:border-primary/50" type="search" placeholder="Поиск по имени или фамилии" /></label><label class="mt-3 flex cursor-pointer items-center gap-2 text-sm font-bold text-primary"><input class="h-4 w-4 accent-[#c992ff]" type="checkbox" :checked="allVisibleBroadcastSelected" @change="toggleVisibleBroadcastRecipients" />{{ allVisibleBroadcastSelected ? 'Снять выбор с видимых' : 'Выбрать видимых' }}</label><div v-if="selectedBroadcastRecipients.length" class="mt-3 rounded-2xl border border-primary/20 bg-primary/10 p-3"><p class="text-xs font-bold uppercase tracking-[0.12em] text-primary">Выбрано: {{ selectedBroadcastRecipients.length }}</p><div class="mt-2 flex max-h-24 flex-wrap gap-1.5 overflow-y-auto"><button v-for="peer in selectedBroadcastRecipients" :key="peer.id" class="inline-flex max-w-full items-center gap-1 rounded-full bg-surface-high px-2 py-1 text-xs font-semibold text-on-surface hover:bg-red-500/15" type="button" :title="`Убрать ${peer.name}`" @click="removeBroadcastRecipient(peer.id)"><span class="truncate">{{ peer.name }}</span><span class="material-symbols-outlined text-[14px]">close</span></button></div></div><div class="brand-scrollbar mt-3 max-h-52 overflow-y-auto pr-1 sm:max-h-72"><label v-for="peer in filteredBroadcastRecipients" :key="peer.id" class="flex cursor-pointer items-center gap-3 rounded-xl px-3 py-2 hover:bg-white/5"><input v-model="broadcastRecipientIds" class="h-4 w-4 accent-[#c992ff]" type="checkbox" :value="peer.id" /><span class="min-w-0 flex-1 truncate text-sm font-semibold">{{ peer.name }}</span></label><p v-if="!filteredBroadcastRecipients.length" class="p-3 text-sm text-on-muted">Участницы не найдены.</p></div></div>
          <div class="flex min-h-0 flex-col"><label class="text-sm font-bold text-on-muted">Сообщение<textarea v-model="broadcastBody" class="mt-2 min-h-32 w-full resize-y rounded-2xl border border-white/10 bg-surface-low p-3 text-sm text-on-surface outline-none focus:border-primary/50 sm:min-h-40" maxlength="2000" placeholder="Текст рассылки" required /></label><p class="mt-2 text-xs text-on-muted">Выбрано: {{ broadcastRecipientIds.length }} · {{ broadcastBody.length }}/2000</p><p v-if="broadcastError" class="mt-3 rounded-xl border border-red-400/25 bg-red-500/10 p-3 text-sm text-red-200">{{ broadcastError }}</p></div>
        </div>
        <div class="flex shrink-0 justify-end gap-3 border-t border-white/10 p-4 sm:p-5"><button class="rounded-xl px-4 py-2 text-sm font-bold text-on-muted hover:bg-white/5" type="button" :disabled="broadcastSending" @click="broadcastOpen = false">Отмена</button><button class="rounded-xl bg-primary px-5 py-2 text-sm font-extrabold text-[#470382] disabled:opacity-50" type="submit" :disabled="broadcastSending || !broadcastRecipientIds.length || !broadcastBody.trim()">{{ broadcastSending ? 'Отправляем…' : 'Отправить' }}</button></div>
      </form>
    </div>
    <div v-if="activePhoto" class="fixed inset-0 z-[200] grid place-items-center bg-black/90 p-4" role="dialog" aria-modal="true" @click.self="activePhoto = null"><button class="absolute right-5 top-5 z-10 grid h-11 w-11 place-items-center rounded-full bg-white/15 text-white" type="button" aria-label="Закрыть" @click="activePhoto = null"><span class="material-symbols-outlined">close</span></button><img class="h-auto max-h-[90dvh] w-auto max-w-[94vw] rounded-xl object-contain" :src="activePhoto" alt="Фото в сообщении" @error="chatError = 'Не удалось загрузить фотографию.'" /></div>
  </section>
</template>

<style scoped>
.chat-page { min-height: min(760px, calc(100dvh - 9rem)); }
.chat-shell { height: calc(100dvh - 10.5rem); min-height: 32rem; }
.chat-directory { flex-direction: column; }
.chat-voice { display: block; width: 100%; min-width: 0; }
.chat-voice-rate { min-height: 2.25rem; flex: 0 0 auto; appearance: none; border: 1px solid rgba(219, 184, 255, 0.35); border-radius: 0.75rem; background: #211e25; color: #dbb8ff; cursor: pointer; font-size: 0.75rem; font-weight: 800; padding: 0 0.6rem; }
.chat-voice-rate:focus-visible { outline: 2px solid #dbb8ff; outline-offset: 2px; }
.chat-voice-rate option { background: #211e25; color: #f4eff7; }
.brand-scrollbar { scrollbar-color: #8c55c7 #211e25; scrollbar-width: thin; }
.brand-scrollbar::-webkit-scrollbar { width: 8px; }
.brand-scrollbar::-webkit-scrollbar-track { background: #211e25; border-radius: 999px; }
.brand-scrollbar::-webkit-scrollbar-thumb { background: linear-gradient(#dbb8ff, #8c55c7); border: 2px solid #211e25; border-radius: 999px; }
@media (max-width: 1023px) {
  .chat-page { min-height: 0; width: 100%; }
  .chat-shell { width: 95%; min-width: 0; height: calc(100dvh - 5rem); min-height: 0; margin-inline: auto; border-radius: 1.75rem; }
  .chat-dialog { min-width: 0; width: 100%; }
  .chat-voice { width: clamp(150px, calc(100vw - 9rem), 260px); max-width: 100%; }
  .brand-scrollbar { padding: 1rem; overscroll-behavior: contain; }
  .brand-scrollbar > div { max-width: calc(100% - 0.5rem); overflow-wrap: anywhere; word-break: break-word; }
  .chat-dialog form { display: grid; grid-template-columns: 2.75rem 2.75rem minmax(0, 1fr) 2.75rem; gap: 0.5rem; min-width: 0; padding: 0.75rem; }
  .chat-dialog form > * { min-width: 0; }
  .chat-dialog form input { width: 100%; padding-right: 0.75rem; padding-left: 0.75rem; }
}
@media (min-width: 1024px) { .chat-shell { height: 68vh; min-height: 30rem; } }
</style>
