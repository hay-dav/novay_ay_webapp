<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { api } from '@/services/api';
import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();
const messages = ref([]);
const peers = ref([]);
const conversations = ref([]);
const selectedPeerId = ref(null);
const selectedConversationId = ref(null);
const chatMode = ref('direct');
const body = ref('');
const listRef = ref(null);
const photoInput = ref(null);
const activePhoto = ref(null);
const sending = ref(false);
const recording = ref(false);
const chatError = ref('');
let mediaRecorder;
let recordingStream;
let audioChunks = [];
let refreshTimer;

const isStaff = computed(() => auth.isTrainer);
const isAdmin = computed(() => auth.user?.role === 'admin');
const isConversationMode = computed(() => isAdmin.value && chatMode.value === 'all');
const selectedPeer = computed(() => peers.value.find((peer) => peer.id === selectedPeerId.value) ?? null);
const selectedConversation = computed(() => conversations.value.find((item) => item.id === selectedConversationId.value) ?? null);
const hasPeerList = computed(() => isConversationMode.value || isStaff.value || peers.value.length > 1);
const selectedParticipantIds = computed(() => {
    if (!selectedConversation.value)
        return null;
    return [selectedConversation.value.sender_id, selectedConversation.value.recipient_id];
});
const chatTitle = computed(() => isConversationMode.value ? 'Все чаты' : (isStaff.value ? 'Чат с клиентом' : 'Чат с командой'));
const peerRoleLabel = computed(() => {
    if (isConversationMode.value)
        return 'Просмотр диалога';
    if (isStaff.value)
        return 'Клиент';
    return selectedPeer.value?.role === 'admin' ? 'Администратор' : 'Куратор';
});
const conversationTitle = (conversation) => `${conversation.sender?.name ?? 'Пользователь'} — ${conversation.recipient?.name ?? 'Пользователь'}`;
const conversationAvatar = (conversation) => conversation.sender?.avatar_path || conversation.recipient?.avatar_path || '';

function voiceExtension(mimeType) {
    if (mimeType.includes('mp4')) return 'm4a';
    if (mimeType.includes('ogg')) return 'ogg';
    if (mimeType.includes('wav')) return 'wav';
    if (mimeType.includes('mpeg')) return 'mp3';
    return 'webm';
}

function formatUnread(count) {
    return count > 99 ? '99+' : String(count);
}

function formatTime(value) {
    return new Intl.DateTimeFormat('ru-RU', { hour: '2-digit', minute: '2-digit' }).format(new Date(value));
}

async function loadPeers() {
    const { data } = await api.get('/chat/peers');
    peers.value = data.data;
    if (!selectedPeerId.value && peers.value.length)
        selectedPeerId.value = peers.value[0].id;
}

async function loadConversations() {
    if (!isAdmin.value)
        return;
    const { data } = await api.get('/chat/conversations');
    conversations.value = data.data;
    if (!selectedConversationId.value && conversations.value.length)
        selectedConversationId.value = conversations.value[0].id;
}

async function load(keepPosition = false) {
    const params = isConversationMode.value
        ? (selectedParticipantIds.value ? {
            participant_a_id: selectedParticipantIds.value[0],
            participant_b_id: selectedParticipantIds.value[1],
        } : null)
        : (selectedPeerId.value ? { peer_id: selectedPeerId.value } : null);

    if (!params) {
        messages.value = [];
        return;
    }

    const { data } = await api.get('/chat/messages', { params });
    messages.value = data.data;
    if (!isConversationMode.value && selectedPeer.value)
        selectedPeer.value.unread_count = 0;
    if (!keepPosition) {
        await nextTick();
        listRef.value?.scrollTo({ top: listRef.value.scrollHeight });
    }
}

async function selectPeer(peerId) {
    selectedPeerId.value = peerId;
    await load();
}

async function selectConversation(conversationId) {
    selectedConversationId.value = conversationId;
    await load();
}

async function setChatMode(mode) {
    if (chatMode.value === mode)
        return;
    chatMode.value = mode;
    messages.value = [];
    if (isConversationMode.value)
        await loadConversations();
    await load();
}

async function refreshChat() {
    if (isConversationMode.value)
        await loadConversations();
    else
        await loadPeers();
    await load(true);
}

async function send({ photo = null, voice = null } = {}) {
    const messageBody = body.value.trim();
    if (isConversationMode.value || (!messageBody && !photo && !voice) || !selectedPeerId.value || sending.value)
        return;
    sending.value = true;
    chatError.value = '';
    try {
        const payload = photo || voice ? new FormData() : { recipient_id: selectedPeerId.value, body: messageBody };
        if (payload instanceof FormData) {
            payload.append('recipient_id', String(selectedPeerId.value));
            if (messageBody) payload.append('body', messageBody);
            if (photo) payload.append('photo', photo);
            if (voice) payload.append('voice', voice);
        }
        const { data } = await api.post('/chat/messages', payload);
        messages.value.push(data.data);
        body.value = '';
        await nextTick();
        listRef.value?.scrollTo({ top: listRef.value.scrollHeight, behavior: 'smooth' });
    }
    catch (error) {
        chatError.value = error.response?.data?.errors
            ? Object.values(error.response.data.errors).flat().join(' ')
            : 'Не удалось отправить сообщение.';
    }
    finally {
        sending.value = false;
    }
}

function selectPhoto(event) {
    const [photo] = event.target.files ?? [];
    if (photo) send({ photo });
    event.target.value = '';
}

async function toggleVoiceRecording() {
    if (recording.value) {
        mediaRecorder?.stop();
        return;
    }
    if (!selectedPeerId.value || !navigator.mediaDevices?.getUserMedia) {
        chatError.value = 'Запись голоса не поддерживается в этом браузере.';
        return;
    }
    try {
        recordingStream = await navigator.mediaDevices.getUserMedia({ audio: true });
        audioChunks = [];
        const preferredMimeType = ['audio/webm;codecs=opus', 'audio/ogg;codecs=opus', 'audio/mp4']
            .find((mimeType) => MediaRecorder.isTypeSupported(mimeType));
        mediaRecorder = new MediaRecorder(recordingStream, preferredMimeType ? { mimeType: preferredMimeType } : undefined);
        mediaRecorder.ondataavailable = (event) => {
            if (event.data.size) audioChunks.push(event.data);
        };
        mediaRecorder.onstop = () => {
            recording.value = false;
            recordingStream?.getTracks().forEach((track) => track.stop());
            const mimeType = mediaRecorder.mimeType || preferredMimeType || audioChunks[0]?.type || 'audio/webm';
            const voice = new File([new Blob(audioChunks, { type: mimeType })], `voice-${Date.now()}.${voiceExtension(mimeType)}`, { type: mimeType });
            if (voice.size) send({ voice });
        };
        mediaRecorder.start();
        recording.value = true;
    }
    catch {
        chatError.value = 'Не удалось получить доступ к микрофону. Разрешите его использование в браузере.';
    }
}

function openPhoto(path) {
    activePhoto.value = path;
}

function closePhoto() {
    activePhoto.value = null;
}

function closePhotoOnEscape(event) {
    if (event.key === 'Escape') closePhoto();
}

onMounted(async () => {
    await loadPeers();
    await load();
    refreshTimer = window.setInterval(() => refreshChat().catch(() => undefined), 5000);
    window.addEventListener('keydown', closePhotoOnEscape);
});

onBeforeUnmount(() => {
    window.clearInterval(refreshTimer);
    window.removeEventListener('keydown', closePhotoOnEscape);
    if (mediaRecorder?.state === 'recording') mediaRecorder.stop();
    recordingStream?.getTracks().forEach((track) => track.stop());
});
</script>

<template>
  <section class="grid min-w-0 gap-6 overflow-x-hidden">
    <div>
      <p class="text-xs font-semibold uppercase tracking-[0.16em] text-primary/80">Сопровождение</p>
      <div class="mt-2 flex flex-col items-stretch gap-3 sm:flex-row sm:items-center sm:justify-between">
        <h2 class="break-words text-[28px] font-extrabold leading-9 sm:text-[32px] sm:leading-10">{{ chatTitle }}</h2>
        <div v-if="isAdmin" class="grid w-full grid-cols-2 rounded-2xl border border-white/10 bg-surface-container p-1 text-xs font-bold sm:w-auto">
          <button class="min-w-0 rounded-xl px-2 py-2 transition whitespace-nowrap sm:px-3" :class="!isConversationMode ? 'bg-primary text-[#470382]' : 'text-on-muted'" type="button" @click="setChatMode('direct')">Мои чаты</button>
          <button class="min-w-0 rounded-xl px-2 py-2 transition whitespace-nowrap sm:px-3" :class="isConversationMode ? 'bg-primary text-[#470382]' : 'text-on-muted'" type="button" @click="setChatMode('all')">Все чаты</button>
        </div>
      </div>
    </div>

    <label v-if="hasPeerList" class="grid min-w-0 gap-2 text-sm font-bold text-on-muted lg:hidden">
      {{ isConversationMode ? 'Выберите диалог' : (isStaff ? 'Выберите клиента' : 'Выберите собеседника') }}
      <select v-if="isConversationMode" class="w-full min-w-0 max-w-full truncate rounded-2xl border border-white/10 bg-surface-container px-4 py-3 text-on-surface" :value="selectedConversationId ?? ''" @change="selectConversation(Number($event.target.value))">
        <option v-for="conversation in conversations" :key="conversation.id" :value="conversation.id">{{ conversationTitle(conversation) }}</option>
      </select>
      <select v-else class="w-full min-w-0 max-w-full truncate rounded-2xl border border-white/10 bg-surface-container px-4 py-3 text-on-surface" :value="selectedPeerId ?? ''" @change="selectPeer(Number($event.target.value))">
        <option v-for="peer in peers" :key="peer.id" :value="peer.id">{{ peer.name }}{{ peer.unread_count ? ` · ${peer.unread_count} новых` : '' }}</option>
      </select>
    </label>

    <article class="glass-panel grid h-[68vh] min-w-0 overflow-hidden rounded-[28px]" :class="hasPeerList ? 'lg:grid-cols-[280px_1fr]' : ''">
      <aside v-if="hasPeerList" class="hidden overflow-y-auto border-r border-white/10 p-3 lg:block">
        <p class="px-3 pb-3 pt-2 text-xs font-bold uppercase text-on-muted">{{ isConversationMode ? 'Диалоги' : (isStaff ? 'Клиенты' : 'Команда') }}</p>
        <template v-if="isConversationMode">
          <button v-for="conversation in conversations" :key="conversation.id" class="mb-1 flex w-full items-center gap-3 rounded-2xl p-3 text-left transition" :class="selectedConversationId === conversation.id ? 'bg-primary/15 text-primary' : 'text-on-muted hover:bg-white/5'" type="button" @click="selectConversation(conversation.id)">
            <span class="grid h-10 w-10 shrink-0 place-items-center overflow-hidden rounded-full bg-surface-high">
              <img v-if="conversationAvatar(conversation)" class="h-full w-full object-cover" :src="conversationAvatar(conversation)" :alt="`Аватар ${conversation.sender?.name ?? conversation.recipient?.name ?? 'пользователя'}`" />
              <span v-else class="material-symbols-outlined">forum</span>
            </span>
            <span class="min-w-0"><span class="block truncate text-sm font-extrabold">{{ conversationTitle(conversation) }}</span><span class="block truncate text-xs opacity-70">{{ conversation.body || (conversation.attachment_type === 'photo' ? 'Фото' : 'Голосовое сообщение') }}</span></span>
          </button>
        </template>
        <template v-else>
          <button v-for="peer in peers" :key="peer.id" class="mb-1 flex w-full items-center gap-3 rounded-2xl p-3 text-left transition" :class="selectedPeerId === peer.id ? 'bg-primary/15 text-primary' : 'text-on-muted hover:bg-white/5'" type="button" @click="selectPeer(peer.id)">
            <span class="grid h-10 w-10 shrink-0 place-items-center overflow-hidden rounded-full bg-surface-high">
              <img v-if="peer.avatar_path" class="h-full w-full object-cover" :src="peer.avatar_path" :alt="`Аватар ${peer.name}`" />
              <span v-else class="material-symbols-outlined">person</span>
            </span>
            <span class="min-w-0 flex-1 truncate text-sm font-extrabold">{{ peer.name }}</span>
            <span v-if="peer.unread_count" class="grid h-5 min-w-5 place-items-center rounded-full bg-primary px-1 text-[10px] font-extrabold text-[#470382]" :aria-label="`${peer.unread_count} непрочитанных сообщений`">{{ formatUnread(peer.unread_count) }}</span>
          </button>
        </template>
      </aside>

      <div class="grid min-w-0 grid-rows-[auto_1fr_auto] overflow-hidden">
        <header v-if="isConversationMode && selectedConversation" class="flex min-w-0 items-center gap-3 border-b border-white/10 px-4 py-3 sm:px-5">
          <span class="grid h-10 w-10 shrink-0 place-items-center overflow-hidden rounded-full bg-primary/15 text-primary">
            <img v-if="conversationAvatar(selectedConversation)" class="h-full w-full object-cover" :src="conversationAvatar(selectedConversation)" :alt="`Аватар ${selectedConversation.sender?.name ?? selectedConversation.recipient?.name ?? 'пользователя'}`" />
            <span v-else class="material-symbols-outlined">forum</span>
          </span>
          <div class="min-w-0"><strong class="block break-words text-sm leading-5">{{ conversationTitle(selectedConversation) }}</strong><span class="text-xs text-on-muted">Просмотр диалога</span></div>
        </header>
        <header v-else-if="selectedPeer" class="flex min-w-0 items-center gap-3 border-b border-white/10 px-4 py-3 sm:px-5">
          <span class="grid h-10 w-10 shrink-0 place-items-center overflow-hidden rounded-full bg-primary/15 text-primary">
            <img v-if="selectedPeer.avatar_path" class="h-full w-full object-cover" :src="selectedPeer.avatar_path" :alt="`Аватар ${selectedPeer.name}`" />
            <span v-else class="material-symbols-outlined">person</span>
          </span>
          <div class="min-w-0"><strong class="block break-words text-sm leading-5">{{ selectedPeer.name }}</strong><span class="text-xs text-on-muted">{{ peerRoleLabel }}</span></div>
        </header>

        <div ref="listRef" class="grid content-start gap-3 overflow-y-auto p-5">
          <div v-for="message in messages" :key="message.id" class="max-w-[88%] rounded-2xl p-4 text-sm leading-6 sm:max-w-[75%]" :class="message.sender_id === auth.user?.id ? 'ml-auto bg-primary text-[#470382]' : 'bg-surface-container text-on-surface'">
            <div class="mb-1 flex min-w-0 items-center justify-between gap-3 text-xs font-extrabold" :class="message.sender_id === auth.user?.id ? 'text-[#470382]/75' : 'text-primary'">
              <span class="min-w-0 break-words">{{ message.sender?.name ?? 'Пользователь' }}</span>
              <span class="shrink-0 font-semibold opacity-70">{{ formatTime(message.created_at) }}</span>
            </div>
            <p v-if="message.body">{{ message.body }}</p>
            <button v-if="message.attachment_type === 'photo'" class="mt-2 block w-full cursor-zoom-in overflow-hidden rounded-xl focus:outline-none focus:ring-2 focus:ring-primary" type="button" aria-label="Открыть фото во весь экран" @click="openPhoto(message.attachment_path)">
              <img class="max-h-80 w-full rounded-xl object-cover transition hover:scale-[1.02]" :src="message.attachment_path" alt="Фото в сообщении" />
            </button>
            <audio v-else-if="message.attachment_type === 'voice'" class="mt-2 w-full min-w-[220px]" :src="message.attachment_path" controls />
          </div>
          <div v-if="!messages.length" class="rounded-2xl border border-white/10 bg-surface-container p-4 text-sm text-on-muted">
            {{ isConversationMode ? 'В этом диалоге пока нет сообщений.' : (selectedPeer ? 'Сообщений пока нет. Начните диалог.' : 'Выберите клиента для начала переписки.') }}
          </div>
        </div>

        <p v-if="chatError" class="mx-4 mt-3 rounded-xl border border-red-400/25 bg-red-500/10 px-3 py-2 text-xs font-semibold text-red-200">{{ chatError }}</p>
        <p v-if="isConversationMode" class="border-t border-white/10 bg-surface-container/60 px-5 py-4 text-sm font-semibold text-on-muted">Режим просмотра: сообщения в этом диалоге нельзя изменить или отправить от имени участника.</p>
        <form v-else class="flex flex-wrap gap-3 border-t border-white/10 bg-surface-container/60 p-4" @submit.prevent="send">
          <input ref="photoInput" class="sr-only" type="file" accept="image/jpeg,image/png,image/webp" @change="selectPhoto" />
          <button class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl border border-white/10 text-on-muted hover:text-primary disabled:opacity-40" type="button" :disabled="!selectedPeer || sending" aria-label="Прикрепить фото" @click="photoInput?.click()"><span class="material-symbols-outlined">add_photo_alternate</span></button>
          <button class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl border transition disabled:opacity-40" :class="recording ? 'border-red-400/50 bg-red-500/20 text-red-200 animate-pulse' : 'border-white/10 text-on-muted hover:text-primary'" type="button" :disabled="!selectedPeer || sending" :aria-label="recording ? 'Остановить запись голоса' : 'Записать голосовое сообщение'" @click="toggleVoiceRecording"><span class="material-symbols-outlined">{{ recording ? 'stop' : 'mic' }}</span></button>
          <input v-model="body" class="min-w-0 flex-1 rounded-2xl border border-white/10 bg-surface-low px-4 py-3 text-on-surface outline-none focus:border-primary/50" :disabled="!selectedPeer" placeholder="Напишите сообщение" />
          <button class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-primary text-[#470382] disabled:opacity-40" type="submit" :disabled="!selectedPeer || !body.trim()" aria-label="Отправить сообщение"><span class="material-symbols-outlined">send</span></button>
        </form>
      </div>
    </article>

    <div v-if="activePhoto" class="fixed inset-0 z-[100] grid place-items-center bg-black/90 p-4" role="dialog" aria-modal="true" aria-label="Просмотр фотографии" @click.self="closePhoto">
      <button class="absolute right-5 top-5 grid h-11 w-11 place-items-center rounded-full bg-white/15 text-white transition hover:bg-white/25 focus:outline-none focus:ring-2 focus:ring-white" type="button" aria-label="Закрыть фото" @click="closePhoto"><span class="material-symbols-outlined">close</span></button>
      <img class="max-h-full max-w-full rounded-xl object-contain" :src="activePhoto" alt="Фото в сообщении, увеличенное" />
    </div>
  </section>
</template>
