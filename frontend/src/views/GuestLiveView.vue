<script setup>
import { computed, nextTick, onBeforeUnmount, ref } from 'vue';
import { ConnectionState, Room, RoomEvent, Track } from 'livekit-client';
import { api } from '@/services/api';
import { useRoute } from 'vue-router';

const route = useRoute();
const video = ref(null);
const localVideo = ref(null);
const hostName = ref('Тренер');
const loading = ref(false);
const connected = ref(false);
const error = ref('');
const audioEnabled = ref(false);
const microphoneEnabled = ref(false);
const cameraEnabled = ref(false);
const canPublish = ref(false);
const joining = ref(false);
const participantsOpen = ref(false);
const participants = ref([]);

let room;
let hostIdentity = '';
const audioElements = new Map();

const joinLabel = computed(() => connected.value ? 'Вы подключены к эфиру' : 'Подключиться к эфиру');

function liveKitUrl(url) {
    return url.replace(/^(wss?):\/\/(?:localhost|127\.0\.0\.1)(?=[:/]|$)/, (_, protocol) => `${protocol}://${window.location.hostname}`);
}

function attach(track, participant) {
    if (track.kind === Track.Kind.Audio) {
        const element = track.attach();
        element.autoplay = true;
        element.playsInline = true;
        element.className = 'hidden';
        document.body.appendChild(element);
        audioElements.set(track.sid, element);
        return;
    }
    if (track.kind === Track.Kind.Video && String(participant.identity) === hostIdentity && video.value) {
        track.attach(video.value);
        video.value.play().catch(() => undefined);
    }
}

function updateParticipants() {
    if (!room) {
        participants.value = [];
        return;
    }

    const remote = [...room.remoteParticipants.values()].map((participant) => {
        const cameraPublication = participant.getTrackPublication(Track.Source.Camera);
        const microphonePublication = participant.getTrackPublication(Track.Source.Microphone);

        return {
            identity: participant.identity,
            name: participant.name || participant.identity,
            cameraEnabled: Boolean(cameraPublication?.track && !cameraPublication.isMuted),
            microphoneEnabled: Boolean(microphonePublication?.track && !microphonePublication.isMuted),
        };
    });

    participants.value = [{
        identity: room.localParticipant.identity,
        name: 'Вы',
        cameraEnabled: room.localParticipant.isCameraEnabled,
        microphoneEnabled: room.localParticipant.isMicrophoneEnabled,
    }, ...remote];
}

function detach(track) {
    const element = audioElements.get(track.sid);
    element?.remove();
    audioElements.delete(track.sid);
    track.detach();
}

function attachExistingTracks() {
    room?.remoteParticipants.forEach((participant) => participant.trackPublications.forEach((publication) => {
        if (publication.track)
            attach(publication.track, participant);
    }));
}

function attachLocalCamera() {
    const track = room?.localParticipant.getTrackPublication(Track.Source.Camera)?.videoTrack;
    if (track && localVideo.value) {
        track.attach(localVideo.value);
        localVideo.value.play().catch(() => undefined);
    }
}

function cameraParticipants() {
    if (!room)
        return [];
    return [...room.remoteParticipants.values()]
        .filter((participant) => String(participant.identity) !== hostIdentity)
        .filter((participant) => {
            const publication = participant.getTrackPublication(Track.Source.Camera);
            return publication?.track && !publication.isMuted;
        })
        .map((participant) => String(participant.identity));
}

async function enforceCameraLimit() {
    if (!room?.localParticipant.isCameraEnabled)
        return;
    const allowed = [String(room.localParticipant.identity), ...cameraParticipants()].sort().slice(0, 2);
    if (!allowed.includes(String(room.localParticipant.identity))) {
        await room.localParticipant.setCameraEnabled(false);
        cameraEnabled.value = false;
        error.value = 'Одновременно могут включать камеры только две участницы. Попробуйте немного позже.';
    }
}

async function connect() {
    if (joining.value || connected.value)
        return;
    joining.value = true;
    loading.value = true;
    error.value = '';
    try {
        const token = String(route.params.token ?? '');
        const { data } = await api.post(`/live-guests/${token}/join`);
        const connection = data.data;
        hostName.value = connection.host_name;
        hostIdentity = String(connection.host_identity);
        canPublish.value = Boolean(connection.can_publish);
        room = new Room({ adaptiveStream: true, dynacast: true, disconnectOnPageLeave: true });
        room.on(RoomEvent.TrackSubscribed, (track, publication, participant) => {
            attach(track, participant);
            updateParticipants();
        });
        room.on(RoomEvent.TrackUnsubscribed, (track) => {
            detach(track);
            updateParticipants();
        });
        room.on(RoomEvent.TrackMuted, () => {
            enforceCameraLimit();
            updateParticipants();
        });
        room.on(RoomEvent.TrackUnmuted, () => {
            enforceCameraLimit();
            updateParticipants();
        });
        room.on(RoomEvent.ParticipantConnected, () => {
            enforceCameraLimit();
            updateParticipants();
        });
        room.on(RoomEvent.ParticipantDisconnected, () => {
            enforceCameraLimit();
            updateParticipants();
        });
        room.on(RoomEvent.ConnectionStateChanged, (state) => {
            connected.value = state === ConnectionState.Connected || state === ConnectionState.Reconnecting;
        });
        room.on(RoomEvent.Disconnected, () => {
            if (connected.value)
                error.value = 'Соединение с эфиром прервано. Нажмите «Подключиться», чтобы повторить попытку.';
            connected.value = false;
        });
        await room.connect(liveKitUrl(connection.url), connection.token, { autoSubscribe: true });
        connected.value = true;
        attachExistingTracks();
        updateParticipants();
        // On mobile browsers sound must be started by a user gesture, so it is
        // intentionally enabled by the separate button below.
    }
    catch (requestError) {
        room?.disconnect();
        room = null;
        error.value = requestError.response?.status === 404
            ? 'Эта гостевая ссылка недействительна, отключена тренером или эфир уже завершён.'
            : requestError.response?.data?.message ?? 'Не удалось подключиться к эфиру. Проверьте интернет и повторите попытку.';
    }
    finally {
        loading.value = false;
        joining.value = false;
    }
}

async function enableAudio() {
    try {
        await room?.startAudio();
        audioEnabled.value = true;
    }
    catch {
        error.value = 'Разрешите воспроизведение звука в настройках браузера и повторите попытку.';
    }
}

async function toggleMicrophone() {
    if (!room || !canPublish.value)
        return;
    try {
        await room.localParticipant.setMicrophoneEnabled(!room.localParticipant.isMicrophoneEnabled);
        microphoneEnabled.value = room.localParticipant.isMicrophoneEnabled;
        updateParticipants();
    }
    catch {
        error.value = 'Не удалось включить микрофон. Проверьте разрешение браузера.';
    }
}

async function toggleCamera() {
    if (!room || !canPublish.value)
        return;
    try {
        await room.localParticipant.setCameraEnabled(!room.localParticipant.isCameraEnabled);
        cameraEnabled.value = room.localParticipant.isCameraEnabled;
        updateParticipants();
        await nextTick();
        attachLocalCamera();
        await enforceCameraLimit();
    }
    catch {
        error.value = 'Не удалось включить камеру. Проверьте разрешение браузера.';
    }
}

onBeforeUnmount(() => {
    audioElements.forEach((element) => element.remove());
    audioElements.clear();
    room?.disconnect();
});
</script>

<template>
  <main class="grid min-h-screen place-items-center bg-[#120d1b] p-4 text-white">
    <section class="w-full max-w-5xl overflow-hidden rounded-[28px] border border-white/10 bg-[#1a1423] shadow-2xl">
      <header class="flex items-center justify-between gap-3 border-b border-white/10 px-5 py-4">
        <span class="rounded-lg bg-red-500 px-3 py-1 text-xs font-extrabold uppercase">Live</span>
        <div class="min-w-0 flex-1"><h1 class="font-extrabold">Прямой эфир</h1><p class="text-xs text-white/60">Ведёт {{ hostName }}</p></div>
        <div v-if="connected" class="relative">
          <button class="inline-flex h-10 items-center gap-1 rounded-xl border border-white/15 px-3 text-xs font-extrabold" type="button" :aria-expanded="participantsOpen" @click="participantsOpen = !participantsOpen">
            <span class="material-symbols-outlined text-[19px]">groups</span>{{ participants.length }}
          </button>
          <div v-if="participantsOpen" class="absolute right-0 top-12 z-20 max-h-72 w-64 overflow-y-auto rounded-2xl border border-white/15 bg-[#211e25] p-2 shadow-2xl">
            <p v-if="!participants.length" class="px-3 py-4 text-center text-xs text-white/60">Пока никто не подключился</p>
            <div v-for="participant in participants" :key="participant.identity" class="flex items-center gap-3 rounded-xl px-3 py-2 text-sm">
              <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-primary/15 font-extrabold text-primary">{{ participant.name.slice(0, 1).toUpperCase() }}</span>
              <span class="min-w-0 flex-1 truncate">{{ participant.name }}</span>
              <span class="material-symbols-outlined text-[18px]" :class="participant.microphoneEnabled ? 'text-emerald-300' : 'text-white/40'" :title="participant.microphoneEnabled ? 'Микрофон включён' : 'Микрофон выключен'">{{ participant.microphoneEnabled ? 'mic' : 'mic_off' }}</span>
              <span class="material-symbols-outlined text-[18px]" :class="participant.cameraEnabled ? 'text-emerald-300' : 'text-white/40'">{{ participant.cameraEnabled ? 'videocam' : 'videocam_off' }}</span>
            </div>
          </div>
        </div>
      </header>
      <div class="relative aspect-video bg-black">
        <video ref="video" class="h-full w-full object-contain" autoplay playsinline />
        <video v-if="cameraEnabled" ref="localVideo" class="absolute bottom-3 left-3 h-28 w-20 rounded-xl border border-white/30 bg-black object-cover" autoplay muted playsinline />
        <div v-if="loading" class="absolute inset-0 grid place-items-center bg-black/60 text-sm font-bold">Подключаемся к эфиру…</div>
        <div v-else-if="!connected" class="absolute inset-0 grid place-items-center bg-black/40 p-6 text-center">
          <button class="rounded-xl bg-primary px-5 py-3 text-sm font-extrabold text-white" type="button" :disabled="joining" @click="connect">{{ joinLabel }}</button>
        </div>
      </div>
      <div class="flex flex-wrap gap-2 px-5 pt-4">
        <button v-if="connected && !audioEnabled" class="rounded-xl border border-primary/40 bg-primary/15 px-4 py-2 text-sm font-bold text-primary" type="button" @click="enableAudio">Включить звук</button>
        <template v-if="connected && canPublish">
          <button class="rounded-xl border border-white/15 px-4 py-2 text-sm font-bold" type="button" @click="toggleMicrophone">{{ microphoneEnabled ? 'Выключить микрофон' : 'Включить микрофон' }}</button>
          <button class="rounded-xl border border-white/15 px-4 py-2 text-sm font-bold" type="button" @click="toggleCamera">{{ cameraEnabled ? 'Выключить камеру' : 'Включить камеру' }}</button>
        </template>
      </div>
      <p v-if="error" class="mx-5 mt-4 rounded-xl border border-red-400/30 bg-red-500/10 px-4 py-3 text-sm font-semibold text-red-200">{{ error }}</p>
      <p class="px-5 py-4 text-sm text-white/60">Гостевой доступ: регистрация не требуется. Не передавайте ссылку посторонним — подключившиеся могут включать микрофон и камеру.</p>
    </section>
  </main>
</template>
