<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { api } from '@/services/api';
import { useAuthStore } from '@/stores/auth';
const auth = useAuthStore();
const messages = ref([]);
const peers = ref([]);
const selectedPeerId = ref(null);
const body = ref('');
const listRef = ref(null);
const photoInput = ref(null);
const sending = ref(false);
const recording = ref(false);
const chatError = ref('');
let mediaRecorder;
let recordingStream;
let audioChunks = [];
let refreshTimer;
const selectedPeer = computed(() => peers.value.find((peer) => peer.id === selectedPeerId.value) ?? null);
const isStaff = computed(() => auth.isTrainer);
const hasPeerList = computed(() => isStaff.value || peers.value.length > 1);
const peerRoleLabel = computed(() => {
    if (isStaff.value)
        return 'Клиент';
    return selectedPeer.value?.role === 'admin' ? 'Администратор' : 'Куратор';
});
async function loadPeers() {
    const { data } = await api.get('/chat/peers');
    peers.value = data.data;
    if (!selectedPeerId.value && peers.value.length)
        selectedPeerId.value = peers.value[0].id;
}
async function load(keepPosition = false) {
    if (!selectedPeerId.value) {
        messages.value = [];
        return;
    }
    const { data } = await api.get('/chat/messages', { params: { peer_id: selectedPeerId.value } });
    messages.value = data.data;
    if (!keepPosition) {
        await nextTick();
        listRef.value?.scrollTo({ top: listRef.value.scrollHeight });
    }
}
async function selectPeer(peerId) {
    selectedPeerId.value = peerId;
    await load();
}
async function send({ photo = null, voice = null } = {}) {
    const messageBody = body.value.trim();
    if ((!messageBody && !photo && !voice) || !selectedPeerId.value || sending.value)
        return;
    sending.value = true;
    chatError.value = '';
    try {
        const payload = photo || voice ? new FormData() : { recipient_id: selectedPeerId.value, body: messageBody };
        if (payload instanceof FormData) {
            payload.append('recipient_id', String(selectedPeerId.value));
            if (messageBody)
                payload.append('body', messageBody);
            if (photo)
                payload.append('photo', photo);
            if (voice)
                payload.append('voice', voice);
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
    if (photo)
        send({ photo });
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
        mediaRecorder = new MediaRecorder(recordingStream);
        mediaRecorder.ondataavailable = (event) => {
            if (event.data.size)
                audioChunks.push(event.data);
        };
        mediaRecorder.onstop = () => {
            recording.value = false;
            recordingStream?.getTracks().forEach((track) => track.stop());
            const voice = new File([new Blob(audioChunks, { type: mediaRecorder.mimeType || 'audio/webm' })], `voice-${Date.now()}.webm`, { type: mediaRecorder.mimeType || 'audio/webm' });
            if (voice.size)
                send({ voice });
        };
        mediaRecorder.start();
        recording.value = true;
    }
    catch {
        chatError.value = 'Не удалось получить доступ к микрофону. Разрешите его использование в браузере.';
    }
}
function formatTime(value) {
    return new Intl.DateTimeFormat('ru-RU', { hour: '2-digit', minute: '2-digit' }).format(new Date(value));
}
onMounted(async () => {
    await loadPeers();
    await load();
    refreshTimer = window.setInterval(() => load(true).catch(() => undefined), 5000);
});
onBeforeUnmount(() => {
    window.clearInterval(refreshTimer);
    if (mediaRecorder?.state === 'recording')
        mediaRecorder.stop();
    recordingStream?.getTracks().forEach((track) => track.stop());
});
</script>

<template>
  <section class="grid gap-6">
    <div>
      <p class="text-xs font-semibold uppercase tracking-[0.16em] text-primary/80">Сопровождение</p>
      <h2 class="mt-2 text-[32px] font-extrabold leading-10">{{ isStaff ? 'Чат с клиентом' : 'Чат с командой' }}</h2>
    </div>

    <label v-if="hasPeerList" class="grid gap-2 text-sm font-bold text-on-muted lg:hidden">
      {{ isStaff ? 'Выберите клиента' : 'Выберите собеседника' }}
      <select class="rounded-2xl border border-white/10 bg-surface-container px-4 py-3 text-on-surface" :value="selectedPeerId ?? ''" @change="selectPeer(Number($event.target.value))">
        <option v-for="peer in peers" :key="peer.id" :value="peer.id">{{ peer.name }}</option>
      </select>
    </label>

    <article class="glass-panel grid h-[68vh] overflow-hidden rounded-[28px]" :class="hasPeerList ? 'lg:grid-cols-[260px_1fr]' : ''">
      <aside v-if="hasPeerList" class="hidden overflow-y-auto border-r border-white/10 p-3 lg:block">
        <p class="px-3 pb-3 pt-2 text-xs font-bold uppercase text-on-muted">{{ isStaff ? 'Клиенты' : 'Команда' }}</p>
        <button v-for="peer in peers" :key="peer.id" class="mb-1 flex w-full items-center gap-3 rounded-2xl p-3 text-left transition" :class="selectedPeerId === peer.id ? 'bg-primary/15 text-primary' : 'text-on-muted hover:bg-white/5'" type="button" @click="selectPeer(peer.id)">
          <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-surface-high"><span class="material-symbols-outlined">person</span></span>
          <span class="min-w-0 truncate text-sm font-extrabold">{{ peer.name }}</span>
        </button>
      </aside>

      <div class="grid min-w-0 grid-rows-[auto_1fr_auto] overflow-hidden">
        <header v-if="selectedPeer" class="flex items-center gap-3 border-b border-white/10 px-5 py-3">
          <span class="grid h-10 w-10 place-items-center rounded-full bg-primary/15 text-primary"><span class="material-symbols-outlined">person</span></span>
          <div><strong class="block text-sm">{{ selectedPeer.name }}</strong><span class="text-xs text-on-muted">{{ peerRoleLabel }}</span></div>
        </header>

        <div ref="listRef" class="grid content-start gap-3 overflow-y-auto p-5">
          <div v-for="message in messages" :key="message.id" class="max-w-[88%] rounded-2xl p-4 text-sm leading-6 sm:max-w-[75%]" :class="message.sender_id === auth.user?.id ? 'ml-auto bg-primary text-[#470382]' : 'bg-surface-container text-on-surface'">
            <div class="mb-1 flex items-center justify-between gap-4 text-xs font-extrabold" :class="message.sender_id === auth.user?.id ? 'text-[#470382]/75' : 'text-primary'">
              <span>{{ message.sender?.name ?? 'Пользователь' }}</span>
              <span class="font-semibold opacity-70">{{ formatTime(message.created_at) }}</span>
            </div>
            <p v-if="message.body">{{ message.body }}</p>
            <img v-if="message.attachment_type === 'photo'" class="mt-2 max-h-80 w-full rounded-xl object-cover" :src="message.attachment_path" alt="Фото в сообщении" />
            <audio v-else-if="message.attachment_type === 'voice'" class="mt-2 w-full min-w-[220px]" :src="message.attachment_path" controls />
          </div>
          <div v-if="!messages.length" class="rounded-2xl border border-white/10 bg-surface-container p-4 text-sm text-on-muted">
            {{ selectedPeer ? 'Сообщений пока нет. Начните диалог.' : 'Выберите клиента для начала переписки.' }}
          </div>
        </div>

        <p v-if="chatError" class="mx-4 mt-3 rounded-xl border border-red-400/25 bg-red-500/10 px-3 py-2 text-xs font-semibold text-red-200">{{ chatError }}</p>
        <form class="flex flex-wrap gap-3 border-t border-white/10 bg-surface-container/60 p-4" @submit.prevent="send">
          <input ref="photoInput" class="sr-only" type="file" accept="image/jpeg,image/png,image/webp" @change="selectPhoto" />
          <button class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl border border-white/10 text-on-muted hover:text-primary disabled:opacity-40" type="button" :disabled="!selectedPeer || sending" aria-label="Прикрепить фото" @click="photoInput?.click()"><span class="material-symbols-outlined">add_photo_alternate</span></button>
          <button class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl border transition disabled:opacity-40" :class="recording ? 'border-red-400/50 bg-red-500/20 text-red-200 animate-pulse' : 'border-white/10 text-on-muted hover:text-primary'" type="button" :disabled="!selectedPeer || sending" :aria-label="recording ? 'Остановить запись голоса' : 'Записать голосовое сообщение'" @click="toggleVoiceRecording"><span class="material-symbols-outlined">{{ recording ? 'stop' : 'mic' }}</span></button>
          <input v-model="body" class="min-w-0 flex-1 rounded-2xl border border-white/10 bg-surface-low px-4 py-3 text-on-surface outline-none focus:border-primary/50" :disabled="!selectedPeer" placeholder="Напишите сообщение" />
          <button class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-primary text-[#470382] disabled:opacity-40" type="submit" :disabled="!selectedPeer || !body.trim()" aria-label="Отправить сообщение"><span class="material-symbols-outlined">send</span></button>
        </form>
      </div>
    </article>
  </section>
</template>
