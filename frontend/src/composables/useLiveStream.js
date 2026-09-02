import {
    ConnectionState,
    Room,
    RoomEvent,
    Track,
} from 'livekit-client';
import { computed, markRaw, nextTick, onBeforeUnmount, ref, shallowRef } from 'vue';
import { api } from '@/services/api';

const roomOptions = {
    adaptiveStream: true,
    dynacast: true,
    disconnectOnPageLeave: true,
    publishDefaults: {
        simulcast: true,
    },
};

export function useLiveStream(options = {}) {
    const section = options.section ?? 'workouts';
    const activeStream = ref(null);
    const liveModalOpen = ref(false);
    const liveLoading = ref(false);
    const liveError = ref('');
    const connectionState = ref('idle');
    const connectionQuality = ref('unknown');
    const playbackMuted = ref(true);
    const microphoneEnabled = ref(false);
    const cameraEnabled = ref(false);
    const hostCameraEnabled = ref(false);
    const recordingSaving = ref(false);
    const cameraFacingMode = ref('user');
    const cameraSwitching = ref(false);
    const viewerCount = ref(0);
    const viewerNames = ref([]);
    const viewerParticipants = ref([]);
    const localVideo = ref(null);
    const remoteVideo = ref(null);
    const conferenceParticipants = ref([]);
    // LiveKit instances contain browser media objects. They must not be made
    // reactive: Vue proxies make their device constraints non-cloneable on iOS.
    const liveKitRoom = shallowRef(null);
    const isHosting = computed(() => Boolean(
        activeStream.value
        && liveKitRoom.value?.state === ConnectionState.Connected
        && liveKitRoom.value.localParticipant.isCameraEnabled,
    ));

    const remoteAudioElements = new Set();
    const participantVideoElements = new Map();
    let activeTimer;
    let hostSession = false;
    let viewerReconnectInProgress = false;

    async function refreshActive() {
        const { data } = await api.get('/live-streams/active', { params: { section } });
        const previousConferenceState = activeStream.value?.participants_enabled;
        activeStream.value = data.data;
        if (!hostSession
            && liveKitRoom.value
            && liveModalOpen.value
            && previousConferenceState !== undefined
            && previousConferenceState !== activeStream.value?.participants_enabled) {
            await reconnectViewerForConference();
        }
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
        room.on(RoomEvent.TrackSubscribed, (track, publication, participant) => {
            if (track.kind === Track.Kind.Video && publication.source === Track.Source.Camera && isHostParticipant(participant))
                hostCameraEnabled.value = true;
            attachRemoteTrack(track, participant);
            updateViewerCount();
        });
        room.on(RoomEvent.TrackUnsubscribed, (track, publication, participant) => {
            if (track.kind === Track.Kind.Video && publication.source === Track.Source.Camera && isHostParticipant(participant))
                hostCameraEnabled.value = false;
            detachRemoteTrack(track, participant);
            updateViewerCount();
        });
        room.on(RoomEvent.TrackMuted, (publication, participant) => {
            if (publication.source === Track.Source.Camera && participant) {
                if (isHostParticipant(participant))
                    hostCameraEnabled.value = false;
                else
                    removeConferenceParticipant(participant.identity, publication.track);
            }
            updateViewerCount();
        });
        room.on(RoomEvent.TrackUnmuted, (publication, participant) => {
            if (publication.source === Track.Source.Camera && publication.track && participant) {
                if (isHostParticipant(participant))
                    hostCameraEnabled.value = true;
                else
                    attachRemoteTrack(publication.track, participant);
            }
            updateViewerCount();
        });
        room.on(RoomEvent.TrackPublished, updateViewerCount);
        room.on(RoomEvent.TrackUnpublished, updateViewerCount);
        room.on(RoomEvent.LocalTrackPublished, updateViewerCount);
        room.on(RoomEvent.LocalTrackUnpublished, updateViewerCount);
        room.on(RoomEvent.ParticipantNameChanged, updateViewerCount);
        room.on(RoomEvent.ParticipantMetadataChanged, updateViewerCount);
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
        liveKitRoom.value = markRaw(room);
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

    async function startBroadcast(recordingDetails = {}) {
        liveLoading.value = true;
        liveError.value = '';
        hostSession = true;
        try {
            const { data } = await api.post('/live-streams/start', { ...recordingDetails, section });
            activeStream.value = data.data;
            if (recordingDetails.title && data.data?.recording_title !== recordingDetails.title)
                throw new Error('Сервер не подтвердил сохранение названия записи.');
            if (recordingDetails.description && data.data?.recording_description !== recordingDetails.description)
                throw new Error('Сервер не подтвердил сохранение описания записи.');
            const connection = await getConnection(activeStream.value);
            const room = createRoom();
            connectionState.value = 'connecting';
            await room.startAudio().catch(() => undefined);
            playbackMuted.value = false;
            await room.connect(getBrowserReachableLiveKitUrl(connection.url), connection.token, { autoSubscribe: true });
            // The host starts the room with both devices disabled. The camera
            // and microphone remain available through the existing controls.
            microphoneEnabled.value = false;
            cameraEnabled.value = false;
            cameraFacingMode.value = 'user';

            liveModalOpen.value = true;
            await nextTick();
            attachLocalCamera();
            await sendHostHeartbeat();
            updateViewerCount();
            return true;
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
                ?? error.message
                ?? 'Не удалось запустить трансляцию.';
            return false;
        }
        finally {
            liveLoading.value = false;
        }
    }

    async function startServerRecording() {
        if (!hostSession || !activeStream.value || activeStream.value.egress_id)
            return;
        const { data } = await api.post(`/live-streams/${activeStream.value.id}/recording/start`);
        activeStream.value = data.data;
    }

    async function reconnectViewerForConference() {
        if (viewerReconnectInProgress || hostSession || !activeStream.value)
            return;
        viewerReconnectInProgress = true;
        try {
            // LiveKit publication permission is encoded in the join token.
            // Rejoin when the host changes conference mode so already connected
            // participants receive a fresh token immediately.
            disconnectRoom();
            await watchBroadcast();
        }
        finally {
            viewerReconnectInProgress = false;
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
            attachVideoTrack(cameraTrack, localVideo.value);
    }

    function attachHostVideo() {
        const host = [...(liveKitRoom.value?.remoteParticipants.values() ?? [])]
            .find((participant) => isHostParticipant(participant));
        const hostTrack = host
            ?.getTrackPublication(Track.Source.Camera)
            ?.videoTrack;
        if (hostTrack && remoteVideo.value)
            attachVideoTrack(hostTrack, remoteVideo.value);
    }

    function attachVideoTrack(track, element) {
        if (!track || !element)
            return;
        const mediaStreamTrackId = track.mediaStreamTrack?.id;
        const alreadyAttached = Boolean(mediaStreamTrackId
            && element.srcObject?.getVideoTracks?.()
                .some((mediaTrack) => mediaTrack.id === mediaStreamTrackId));
        // A track may be rendered in the stage and a thumbnail at the same
        // time. Globally detaching it here races Vue's conditional rendering
        // on iOS and can leave the stage black.
        // Vue function refs run again after reactive updates. Keep the existing
        // MediaStream attached when it already contains this LiveKit track;
        // replacing srcObject here produces a visible flash on every poll.
        if (!alreadyAttached && element.srcObject)
            element.srcObject = null;
        element.muted = true;
        element.autoplay = true;
        element.playsInline = true;
        if (!alreadyAttached)
            track.attach(element);
        element.play().catch(() => {
            window.requestAnimationFrame(() => element.play().catch(() => undefined));
        });
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
        if (track.kind === Track.Kind.Video) {
            if (isHostParticipant(participant)) {
                hostCameraEnabled.value = true;
                nextTick(attachHostVideo);
            } else {
                const entry = { identity: participant.identity, name: participant.name || 'Участник', track: markRaw(track) };
                const index = conferenceParticipants.value.findIndex((item) => item.identity === participant.identity);
                if (index === -1)
                    conferenceParticipants.value.push(entry);
                else
                    conferenceParticipants.value[index] = entry;
                nextTick(() => attachParticipantVideo(participant.identity, participantVideoElements.get(participant.identity)));
                enforceParticipantCameraLimit();
            }
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

    function attachParticipantVideo(identity, element) {
        const participantIdentity = String(identity);
        const previousElement = participantVideoElements.get(participantIdentity);
        if (!element) {
            // Vue may deliver ref(null) for the old thumbnail after the new
            // stage element has already been registered. Never detach that
            // newer, connected element: doing so leaves the mobile stage black.
            if (previousElement && !previousElement.isConnected) {
                const participant = conferenceParticipants.value
                    .find((item) => item.identity === participantIdentity);
                participant?.track?.detach(previousElement);
                previousElement.srcObject = null;
                participantVideoElements.delete(participantIdentity);
            }
            return;
        }
        if (previousElement && previousElement !== element) {
            const participant = conferenceParticipants.value
                .find((item) => item.identity === participantIdentity);
            participant?.track?.detach(previousElement);
            previousElement.srcObject = null;
        }
        participantVideoElements.set(participantIdentity, element);
        const participant = conferenceParticipants.value
            .find((item) => item.identity === participantIdentity);
        if (!participant)
            return;
        attachVideoTrack(participant.track, element);
    }

    function setLocalVideoElement(element) {
        if (!element) {
            if (localVideo.value && !localVideo.value.isConnected)
                localVideo.value = null;
            return;
        }
        if (localVideo.value === element)
            return;
        localVideo.value = element;
        attachLocalCamera();
    }

    function setRemoteVideoElement(element) {
        if (!element) {
            if (remoteVideo.value && !remoteVideo.value.isConnected)
                remoteVideo.value = null;
            return;
        }
        if (remoteVideo.value === element)
            return;
        remoteVideo.value = element;
        attachHostVideo();
    }

    function isHostParticipant(participant) {
        const hostId = activeStream.value?.host?.id ?? activeStream.value?.host_id;
        return hostId !== undefined && String(participant?.identity) === String(hostId);
    }

    function removeConferenceParticipant(identity, track = null) {
        const participantIdentity = String(identity);
        const element = participantVideoElements.get(participantIdentity);
        if (element) {
            element.srcObject = null;
            participantVideoElements.delete(participantIdentity);
        }
        conferenceParticipants.value = conferenceParticipants.value
            .filter((item) => item.identity !== participantIdentity);
        track?.detach().forEach((element) => {
            element.srcObject = null;
        });
    }

    function detachRemoteTrack(track, participant) {
        if (track.kind === Track.Kind.Video && participant) {
            if (isHostParticipant(participant))
                hostCameraEnabled.value = false;
            else
                removeConferenceParticipant(participant.identity);
        }
        track.detach().forEach((element) => {
            remoteAudioElements.delete(element);
            element.srcObject = null;
            if (track.kind === Track.Kind.Audio)
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
        if (!hostSession && !activeStream.value?.participants_enabled) {
            liveError.value = 'Администратор пока не включил видеоконференцию для участниц.';
            return;
        }
        try {
            await participant.setMicrophoneEnabled(!participant.isMicrophoneEnabled);
            microphoneEnabled.value = participant.isMicrophoneEnabled;
            updateViewerCount();
        }
        catch (error) {
            liveError.value = error.message ?? 'Не удалось включить микрофон. Проверьте разрешение браузера.';
        }
    }

    async function toggleCamera() {
        const participant = liveKitRoom.value?.localParticipant;
        if (!participant)
            return;
        if (!hostSession && !activeStream.value?.participants_enabled) {
            liveError.value = 'Администратор пока не включил видеоконференцию для участниц.';
            return;
        }
        if (!hostSession && !participant.isCameraEnabled && conferenceParticipants.value.length >= 2) {
            liveError.value = 'Одновременно камеры могут включить только две участницы.';
            return;
        }

        try {
            await participant.setCameraEnabled(!participant.isCameraEnabled);
            cameraEnabled.value = participant.isCameraEnabled;
            if (cameraEnabled.value) {
                await startServerRecording();
                await nextTick();
                attachLocalCamera();
                cameraFacingMode.value = participant
                    .getTrackPublication(Track.Source.Camera)
                    ?.videoTrack
                    ?.mediaStreamTrack
                    ?.getSettings()
                    ?.facingMode ?? 'user';
                enforceParticipantCameraLimit();
            }
            updateViewerCount();
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

    async function selectRecordingStage(identity = null) {
        const participant = liveKitRoom.value?.localParticipant;
        if (!hostSession || !participant)
            return;
        const payload = new TextEncoder().encode(JSON.stringify({
            type: 'stage-selection',
            identity: identity ? String(identity) : null,
        }));
        await participant.publishData(payload, {
            reliable: true,
            topic: 'stage-selection',
        }).catch(() => undefined);
    }

    function updateViewerCount() {
        const room = liveKitRoom.value;
        const participants = [...(room?.remoteParticipants.values() ?? [])]
            .filter((participant) => !isHostParticipant(participant));
        const localParticipant = room?.localParticipant;
        const visibleParticipants = !hostSession && localParticipant
            ? [localParticipant, ...participants]
            : participants;

        viewerCount.value = visibleParticipants.length;
        viewerParticipants.value = visibleParticipants.map((participant) => {
            const cameraPublication = participant.getTrackPublication(Track.Source.Camera);
            const microphonePublication = participant.getTrackPublication(Track.Source.Microphone);
            return {
                identity: participant.identity,
                name: participant.isLocal ? 'Вы' : (participant.name || participant.identity),
                cameraEnabled: Boolean(cameraPublication?.track && !cameraPublication.isMuted),
                microphoneEnabled: Boolean(microphonePublication?.track && !microphonePublication.isMuted),
            };
        });
        viewerNames.value = viewerParticipants.value.map((participant) => participant.name);
    }

    function enforceParticipantCameraLimit() {
        if (hostSession)
            return;
        const participant = liveKitRoom.value?.localParticipant;
        if (!participant?.isCameraEnabled)
            return;

        const allowedIdentities = [
            String(participant.identity),
            ...conferenceParticipants.value.map((item) => String(item.identity)),
        ].sort().slice(0, 2);

        if (!allowedIdentities.includes(String(participant.identity))) {
            participant.setCameraEnabled(false).then(() => {
                cameraEnabled.value = false;
                liveError.value = 'Лимит камер достигнут: одновременно могут быть видны только две участницы.';
            }).catch(() => undefined);
        }
    }

    async function stopBroadcast() {
        const stream = activeStream.value;
        if (!stream)
            return null;
        recordingSaving.value = true;
        try {
            // The server-side LiveKit Egress owns the recording. Ending the
            // room must not wait for encoding, S3 upload, or watermarking.
            await api.patch(`/live-streams/${stream.id}/end`);
            return null;
        }
        catch (error) {
            liveError.value = error.response?.data?.message
                ?? 'Не удалось завершить эфир. Проверьте подключение и повторите попытку.';
            return null;
        }
        finally {
            hostSession = false;
            activeStream.value = null;
            closeLiveModal();
            recordingSaving.value = false;
        }
    }

    function disconnectRoom() {
        remoteAudioElements.forEach((element) => element.remove());
        remoteAudioElements.clear();
        participantVideoElements.clear();
        conferenceParticipants.value = [];
        if (localVideo.value)
            localVideo.value.srcObject = null;
        if (remoteVideo.value)
            remoteVideo.value.srcObject = null;
        liveKitRoom.value?.disconnect();
        liveKitRoom.value = null;
        viewerCount.value = 0;
        viewerNames.value = [];
        viewerParticipants.value = [];
        connectionQuality.value = 'unknown';
        connectionState.value = 'idle';
        microphoneEnabled.value = false;
        cameraEnabled.value = false;
        hostCameraEnabled.value = false;
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
        hostCameraEnabled,
        recordingSaving,
        cameraFacingMode,
        cameraSwitching,
        viewerCount,
        viewerNames,
        viewerParticipants,
        localVideo,
        remoteVideo,
        conferenceParticipants,
        attachLocalCamera,
        attachHostVideo,
        attachParticipantVideo,
        setLocalVideoElement,
        setRemoteVideoElement,
        startActivePolling,
        startBroadcast,
        stopBroadcast,
        watchBroadcast,
        enablePlayback,
        toggleMicrophone,
        toggleCamera,
        switchCamera,
        selectRecordingStage,
        closeLiveModal,
    };
}
