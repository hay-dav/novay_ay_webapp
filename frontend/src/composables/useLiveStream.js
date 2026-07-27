import {
    ConnectionState,
    Room,
    RoomEvent,
    Track,
} from 'livekit-client';
import { computed, nextTick, onBeforeUnmount, ref } from 'vue';
import { api } from '@/services/api';

const roomOptions = {
    adaptiveStream: true,
    dynacast: true,
    disconnectOnPageLeave: true,
    publishDefaults: {
        simulcast: true,
    },
};

export function useLiveStream() {
    const activeStream = ref(null);
    const liveModalOpen = ref(false);
    const liveLoading = ref(false);
    const liveError = ref('');
    const connectionState = ref('idle');
    const connectionQuality = ref('unknown');
    const playbackMuted = ref(true);
    const microphoneEnabled = ref(false);
    const cameraEnabled = ref(false);
    const recordingSaving = ref(false);
    const cameraFacingMode = ref('user');
    const cameraSwitching = ref(false);
    const viewerCount = ref(0);
    const localVideo = ref(null);
    const remoteVideo = ref(null);
    const liveKitRoom = ref(null);
    const isHosting = computed(() => Boolean(
        activeStream.value
        && liveKitRoom.value?.state === ConnectionState.Connected
        && liveKitRoom.value.localParticipant.isCameraEnabled,
    ));

    const remoteAudioElements = new Set();
    let mediaRecorder = null;
    let recordingChunks = [];
    let recordingStartedAt = 0;
    let activeTimer;
    let hostSession = false;

    async function refreshActive() {
        const { data } = await api.get('/live-streams/active');
        activeStream.value = data.data;
        if (!activeStream.value && liveModalOpen.value && !hostSession) {
            closeLiveModal();
            liveError.value = 'Прямой эфир завершен.';
        }
    }

    function startActivePolling() {
        refreshActive().catch(() => undefined);
        window.clearInterval(activeTimer);
        activeTimer = window.setInterval(() => {
            refreshActive().catch(() => undefined);
            sendHostHeartbeat().catch(() => undefined);
        }, 5000);
    }

    async function sendHostHeartbeat() {
        if (hostSession && activeStream.value)
            await api.post(`/live-streams/${activeStream.value.id}/heartbeat`);
    }

    function createRoom() {
        const room = new Room(roomOptions);
        room.on(RoomEvent.TrackSubscribed, (track, publication, participant) => attachRemoteTrack(track, participant));
        room.on(RoomEvent.TrackUnsubscribed, detachRemoteTrack);
        room.on(RoomEvent.ParticipantConnected, updateViewerCount);
        room.on(RoomEvent.ParticipantDisconnected, updateViewerCount);
        room.on(RoomEvent.ConnectionStateChanged, (state) => {
            connectionState.value = state === ConnectionState.Connected
                ? 'connected'
                : state === ConnectionState.Connecting || state === ConnectionState.Reconnecting
                    ? 'connecting'
                    : 'idle';
        });
        room.on(RoomEvent.ConnectionQualityChanged, (quality, participant) => {
            if (participant.isLocal)
                connectionQuality.value = String(quality).toLowerCase();
        });
        room.on(RoomEvent.Disconnected, () => {
            connectionState.value = 'idle';
            if (liveModalOpen.value && !recordingSaving.value)
                liveError.value = 'Соединение с эфиром завершено.';
        });
        room.on(RoomEvent.MediaDevicesError, () => {
            liveError.value = 'Камера или микрофон недоступны. Проверьте разрешения браузера.';
        });
        liveKitRoom.value = room;
        return room;
    }

    async function getConnection(stream) {
        const { data } = await api.post(`/live-streams/${stream.id}/token`);
        return data.data;
    }

    function getBrowserReachableLiveKitUrl(url) {
        // In local Docker, localhost points to the computer. On a phone it
        // points to the phone, so use the same host as the opened application.
        return url.replace(/^(wss?):\/\/(?:localhost|127\.0\.0\.1)(?=[:/]|$)/, (_, protocol) => `${protocol}://${window.location.hostname}`);
    }

    async function startBroadcast() {
        liveLoading.value = true;
        liveError.value = '';
        hostSession = true;
        try {
            if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia)
                throw new DOMException('Media devices unavailable', 'SecurityError');
            if (typeof MediaRecorder === 'undefined')
                throw new DOMException('Recording unavailable', 'NotSupportedError');

            const { data } = await api.post('/live-streams/start');
            activeStream.value = data.data;
            const connection = await getConnection(activeStream.value);
            const room = createRoom();
            connectionState.value = 'connecting';
            await room.startAudio().catch(() => undefined);
            playbackMuted.value = false;
            await room.connect(getBrowserReachableLiveKitUrl(connection.url), connection.token, { autoSubscribe: true });
            await room.localParticipant.enableCameraAndMicrophone();
            microphoneEnabled.value = room.localParticipant.isMicrophoneEnabled;
            cameraEnabled.value = room.localParticipant.isCameraEnabled;
            cameraFacingMode.value = room.localParticipant
                .getTrackPublication(Track.Source.Camera)
                ?.videoTrack
                ?.mediaStreamTrack
                ?.getSettings()
                ?.facingMode ?? 'user';

            liveModalOpen.value = true;
            await nextTick();
            attachLocalCamera();
            startRecording();
            await sendHostHeartbeat();
            updateViewerCount();
        }
        catch (error) {
            if (activeStream.value)
                await api.patch(`/live-streams/${activeStream.value.id}/end`).catch(() => undefined);
            disconnectRoom();
            activeStream.value = null;
            hostSession = false;
            const messages = {
                NotAllowedError: 'Доступ к камере и микрофону запрещен. Разрешите оба устройства в браузере.',
                NotFoundError: 'Камера или микрофон не найдены.',
                NotReadableError: 'Камера или микрофон заняты другим приложением.',
                SecurityError: 'Камера доступна только через localhost или HTTPS.',
                NotSupportedError: 'Браузер не поддерживает запись эфира. Используйте актуальный Chrome или Edge.',
            };
            liveError.value = messages[error.name]
                ?? error.response?.data?.message
                ?? 'Не удалось запустить трансляцию.';
        }
        finally {
            liveLoading.value = false;
        }
    }

    async function watchBroadcast() {
        if (!activeStream.value)
            return;
        liveLoading.value = true;
        liveError.value = '';
        hostSession = false;
        playbackMuted.value = true;
        try {
            const room = createRoom();
            const connection = await getConnection(activeStream.value);
            liveModalOpen.value = true;
            connectionState.value = 'connecting';
            await nextTick();
            await room.connect(getBrowserReachableLiveKitUrl(connection.url), connection.token, { autoSubscribe: true });
            attachExistingRemoteTracks(room);
            await room.startAudio()
                .then(() => {
                    playbackMuted.value = false;
                })
                .catch(() => {
                    playbackMuted.value = true;
                });
            updateViewerCount();
        }
        catch (error) {
            disconnectRoom();
            liveModalOpen.value = false;
            liveError.value = error.response?.data?.message
                ?? 'Не удалось подключиться к эфиру. Попробуйте ещё раз.';
        }
        finally {
            liveLoading.value = false;
        }
    }

    function attachLocalCamera() {
        const cameraTrack = liveKitRoom.value?.localParticipant
            .getTrackPublication(Track.Source.Camera)
            ?.videoTrack;
        if (cameraTrack && localVideo.value)
            cameraTrack.attach(localVideo.value);
    }

    function attachExistingRemoteTracks(room) {
        room.remoteParticipants.forEach((participant) => {
            participant.trackPublications.forEach((publication) => {
                if (publication.track)
                    attachRemoteTrack(publication.track, participant);
            });
        });
    }

    function attachRemoteTrack(track, participant) {
        if (!isHostParticipant(participant))
            return;
        if (track.kind === Track.Kind.Video && remoteVideo.value) {
            const element = remoteVideo.value;
            element.muted = true;
            element.autoplay = true;
            element.playsInline = true;
            track.attach(element);
            element.play().catch(() => {
                // A remote video track contains no audio in this UI. Keep it muted
                // and retry on the next frame so browser autoplay policies cannot
                // leave a subscribed stream silently paused.
                window.requestAnimationFrame(() => element.play().catch(() => undefined));
            });
            return;
        }
        if (track.kind === Track.Kind.Audio) {
            const element = track.attach();
            element.autoplay = true;
            element.style.display = 'none';
            document.body.appendChild(element);
            remoteAudioElements.add(element);
            element.play()
                .then(() => {
                    playbackMuted.value = false;
                })
                .catch(() => {
                    playbackMuted.value = true;
                });
        }
    }

    function isHostParticipant(participant) {
        const hostId = activeStream.value?.host?.id ?? activeStream.value?.host_id;
        return hostId !== undefined && String(participant?.identity) === String(hostId);
    }

    function detachRemoteTrack(track) {
        track.detach().forEach((element) => {
            remoteAudioElements.delete(element);
            element.remove();
        });
    }

    async function enablePlayback() {
        if (!liveKitRoom.value)
            return;
        await liveKitRoom.value.startAudio();
        await Promise.all([...remoteAudioElements].map((element) => element.play()));
        playbackMuted.value = false;
    }

    async function toggleMicrophone() {
        const participant = liveKitRoom.value?.localParticipant;
        if (!participant)
            return;
        try {
            await participant.setMicrophoneEnabled(!participant.isMicrophoneEnabled);
            microphoneEnabled.value = participant.isMicrophoneEnabled;
        }
        catch (error) {
            liveError.value = error.message ?? 'Не удалось включить микрофон. Проверьте разрешение браузера.';
        }
    }

    async function toggleCamera() {
        const participant = liveKitRoom.value?.localParticipant;
        if (!participant)
            return;

        try {
            await participant.setCameraEnabled(!participant.isCameraEnabled);
            cameraEnabled.value = participant.isCameraEnabled;
            if (cameraEnabled.value) {
                await nextTick();
                attachLocalCamera();
                cameraFacingMode.value = participant
                    .getTrackPublication(Track.Source.Camera)
                    ?.videoTrack
                    ?.mediaStreamTrack
                    ?.getSettings()
                    ?.facingMode ?? 'user';
            }
        }
        catch (error) {
            liveError.value = error.message ?? 'Не удалось включить камеру. Проверьте разрешение браузера.';
        }
    }

    async function switchCamera() {
        if (cameraSwitching.value)
            return;
        const participant = liveKitRoom.value?.localParticipant;
        if (!participant)
            return;
        if (!participant.isCameraEnabled)
            await toggleCamera();

        const cameraTrack = participant
            .getTrackPublication(Track.Source.Camera)
            ?.videoTrack;
        if (!cameraTrack)
            return;

        cameraSwitching.value = true;
        liveError.value = '';
        const nextFacingMode = cameraFacingMode.value === 'environment' ? 'user' : 'environment';
        try {
            await cameraTrack.restartTrack({
                facingMode: { exact: nextFacingMode },
                width: { ideal: 1280 },
                height: { ideal: 720 },
                frameRate: { ideal: 30, max: 30 },
            });
            cameraFacingMode.value = cameraTrack.mediaStreamTrack.getSettings().facingMode ?? nextFacingMode;
            attachLocalCamera();
        }
        catch {
            liveError.value = 'Не удалось переключить камеру. Возможно, на устройстве доступна только одна камера.';
        }
        finally {
            cameraSwitching.value = false;
        }
    }

    function updateViewerCount() {
        viewerCount.value = liveKitRoom.value?.remoteParticipants.size ?? 0;
    }

    function startRecording() {
        const participant = liveKitRoom.value?.localParticipant;
        if (!participant)
            return;
        const tracks = [
            participant.getTrackPublication(Track.Source.Camera)?.videoTrack?.mediaStreamTrack,
            participant.getTrackPublication(Track.Source.Microphone)?.audioTrack?.mediaStreamTrack,
        ].filter(Boolean);
        const stream = new MediaStream(tracks);
        const mimeType = ['video/webm;codecs=vp8,opus', 'video/webm;codecs=vp9,opus', 'video/webm']
            .find((type) => MediaRecorder.isTypeSupported(type));
        mediaRecorder = new MediaRecorder(stream, {
            ...(mimeType ? { mimeType } : {}),
            videoBitsPerSecond: 2_500_000,
            audioBitsPerSecond: 96_000,
        });
        recordingChunks = [];
        mediaRecorder.ondataavailable = (event) => {
            if (event.data.size)
                recordingChunks.push(event.data);
        };
        mediaRecorder.onerror = (event) => {
            liveError.value = `Ошибка записи эфира: ${event.error?.message ?? 'запись остановлена браузером'}`;
        };
        recordingStartedAt = Date.now();
        mediaRecorder.start(1000);
    }

    function stopRecording() {
        return new Promise((resolve, reject) => {
            const recorder = mediaRecorder;
            if (!recorder) {
                resolve(null);
                return;
            }
            const finish = () => {
                const blob = new Blob(recordingChunks, { type: recorder.mimeType || 'video/webm' });
                mediaRecorder = null;
                recordingChunks = [];
                resolve(blob.size ? blob : null);
            };
            if (recorder.state === 'inactive') {
                finish();
                return;
            }
            recorder.addEventListener('stop', finish, { once: true });
            recorder.addEventListener('error', (event) => reject(event.error ?? new Error('MediaRecorder error')), { once: true });
            recorder.requestData();
            recorder.stop();
        });
    }

    async function saveRecording(stream, recording, durationSeconds) {
        const form = new FormData();
        const extension = recording.type.includes('mp4') ? 'mp4' : 'webm';
        form.append('video', recording, `live-${stream.id}.${extension}`);
        form.append('duration_seconds', String(durationSeconds));

        let lastError;
        for (let attempt = 0; attempt < 3; attempt += 1) {
            try {
                const { data } = await api.post(`/live-streams/${stream.id}/recording`, form);
                return data.data;
            }
            catch (error) {
                lastError = error;
                if (error.response?.status >= 400 && error.response?.status < 500)
                    throw error;
                await new Promise((resolve) => window.setTimeout(resolve, 800));
            }
        }
        throw lastError;
    }

    async function stopBroadcast() {
        const stream = activeStream.value;
        if (!stream)
            return null;
        recordingSaving.value = true;
        let savedWorkout = null;
        try {
            const recording = await stopRecording();
            const durationSeconds = Math.max(1, Math.round((Date.now() - recordingStartedAt) / 1000));
            if (!recording?.size)
                throw new Error('Recording is empty');
            savedWorkout = await saveRecording(stream, recording, durationSeconds);
        }
        catch (error) {
            liveError.value = `Эфир завершён, но запись не удалось сохранить: ${error.response?.data?.message ?? error.message}`;
            await api.patch(`/live-streams/${stream.id}/end`).catch(() => undefined);
        }
        finally {
            recordingSaving.value = false;
            activeStream.value = null;
            hostSession = false;
            closeLiveModal();
        }
        return savedWorkout;
    }

    function disconnectRoom() {
        remoteAudioElements.forEach((element) => element.remove());
        remoteAudioElements.clear();
        if (localVideo.value)
            localVideo.value.srcObject = null;
        if (remoteVideo.value)
            remoteVideo.value.srcObject = null;
        liveKitRoom.value?.disconnect();
        liveKitRoom.value = null;
        viewerCount.value = 0;
        connectionQuality.value = 'unknown';
        connectionState.value = 'idle';
        microphoneEnabled.value = false;
        cameraEnabled.value = false;
    }

    function closeLiveModal() {
        liveModalOpen.value = false;
        playbackMuted.value = true;
        disconnectRoom();
    }

    onBeforeUnmount(() => {
        window.clearInterval(activeTimer);
        if (activeStream.value && hostSession)
            api.patch(`/live-streams/${activeStream.value.id}/end`).catch(() => undefined);
        if (mediaRecorder?.state === 'recording')
            mediaRecorder.stop();
        disconnectRoom();
    });

    return {
        activeStream,
        isHosting,
        liveModalOpen,
        liveLoading,
        liveError,
        connectionState,
        connectionQuality,
        playbackMuted,
        microphoneEnabled,
        cameraEnabled,
        recordingSaving,
        cameraFacingMode,
        cameraSwitching,
        viewerCount,
        localVideo,
        remoteVideo,
        startActivePolling,
        startBroadcast,
        stopBroadcast,
        watchBroadcast,
        enablePlayback,
        toggleMicrophone,
        toggleCamera,
        switchCamera,
        closeLiveModal,
    };
}
