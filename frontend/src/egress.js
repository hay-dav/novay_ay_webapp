import { Room, RoomEvent, Track } from 'livekit-client';

const parameters = new URLSearchParams(window.location.search);
const liveKitUrl = parameters.get('url');
const token = parameters.get('token');
const hostIdentity = String(parameters.get('host') ?? '');
const primaryVideo = document.querySelector('#primary-video');
const secondaryVideo = document.querySelector('#secondary-video');
const secondaryFrame = document.querySelector('#secondary-frame');

document.documentElement.style.cssText = 'width:100%;height:100%;background:#000';
document.body.style.cssText = 'width:100%;height:100%;margin:0;overflow:hidden;background:#000';

const style = document.createElement('style');
style.textContent = `
  #recording-stage { position: relative; width: 100%; height: 100%; overflow: hidden; background: #000; }
  #primary-video { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; background: #000; }
  #secondary-frame { position: absolute; z-index: 2; left: 4%; bottom: 7%; display: none; width: 38%; height: 34%; overflow: hidden; border: 1px solid rgba(255,255,255,.45); border-radius: 20px; background: #000; box-shadow: 0 12px 32px rgba(0,0,0,.38); }
  #secondary-video { width: 100%; height: 100%; object-fit: cover; background: #000; }
`;
document.head.appendChild(style);

let room;
let selectedIdentity = hostIdentity;
let primaryTrack = null;
let secondaryTrack = null;
let recordingStarted = false;
const audioElements = new Map();

function cameraTrack(participant) {
    const publication = participant?.getTrackPublication(Track.Source.Camera);
    return publication?.track && !publication.isMuted ? publication.track : null;
}

function participant(identity) {
    if (!room)
        return null;
    if (String(room.localParticipant.identity) === String(identity))
        return room.localParticipant;
    return room.remoteParticipants.get(String(identity)) ?? null;
}

function visibleParticipants() {
    return [...(room?.remoteParticipants.values() ?? [])]
        .filter((item) => cameraTrack(item));
}

function attachVideo(track, element) {
    if (!track || !element)
        return;
    track.attach(element);
    element.play().catch(() => undefined);
}

function detachVideo(track, element) {
    if (!track || !element)
        return;
    track.detach(element);
    element.srcObject = null;
}

function signalRecordingStarted() {
    if (recordingStarted || !primaryTrack)
        return;
    recordingStarted = true;
    window.requestAnimationFrame(() => {
        window.requestAnimationFrame(() => console.log('START_RECORDING'));
    });
}

function updateComposition() {
    const host = participant(hostIdentity);
    const selected = participant(selectedIdentity);
    const mainParticipant = cameraTrack(selected) ? selected : host;
    const mainTrack = cameraTrack(mainParticipant);
    const alternateParticipant = String(mainParticipant?.identity) === hostIdentity
        ? visibleParticipants().find((item) => String(item.identity) !== hostIdentity)
        : host;
    const alternateTrack = cameraTrack(alternateParticipant);

    if (primaryTrack !== mainTrack) {
        detachVideo(primaryTrack, primaryVideo);
        primaryTrack = mainTrack;
        attachVideo(primaryTrack, primaryVideo);
    }
    if (secondaryTrack !== alternateTrack) {
        detachVideo(secondaryTrack, secondaryVideo);
        secondaryTrack = alternateTrack;
        attachVideo(secondaryTrack, secondaryVideo);
    }
    secondaryFrame.style.display = secondaryTrack ? 'block' : 'none';
    signalRecordingStarted();
}

function attachAudio(track) {
    if (audioElements.has(track.sid))
        return;
    const element = track.attach();
    element.autoplay = true;
    element.style.display = 'none';
    document.body.appendChild(element);
    audioElements.set(track.sid, element);
    element.play().catch(() => undefined);
}

function detachTrack(track) {
    if (track.kind === Track.Kind.Audio) {
        const element = audioElements.get(track.sid);
        track.detach(element);
        element?.remove();
        audioElements.delete(track.sid);
    }
    updateComposition();
}

async function start() {
    if (!liveKitUrl || !token || !hostIdentity)
        throw new Error('Egress connection parameters are incomplete.');

    room = new Room({ adaptiveStream: false, dynacast: false });
    room.on(RoomEvent.TrackSubscribed, (track) => {
        if (track.kind === Track.Kind.Audio)
            attachAudio(track);
        updateComposition();
    });
    room.on(RoomEvent.TrackUnsubscribed, detachTrack);
    room.on(RoomEvent.TrackMuted, updateComposition);
    room.on(RoomEvent.TrackUnmuted, updateComposition);
    room.on(RoomEvent.ParticipantConnected, updateComposition);
    room.on(RoomEvent.ParticipantDisconnected, updateComposition);
    room.on(RoomEvent.DataReceived, (payload, sender, kind, topic) => {
        if (topic !== 'stage-selection' || String(sender?.identity) !== hostIdentity)
            return;
        try {
            const message = JSON.parse(new TextDecoder().decode(payload));
            if (message.type !== 'stage-selection')
                return;
            selectedIdentity = message.identity ? String(message.identity) : hostIdentity;
            updateComposition();
        }
        catch {
            // Ignore malformed room data. Only the host can publish it.
        }
    });
    room.on(RoomEvent.Disconnected, () => console.log('END_RECORDING'));

    await room.connect(liveKitUrl, token, { autoSubscribe: true });
    room.remoteParticipants.forEach((item) => item.trackPublications.forEach((publication) => {
        if (!publication.track)
            return;
        if (publication.track.kind === Track.Kind.Audio)
            attachAudio(publication.track);
    }));
    updateComposition();
}

start().catch((error) => console.error('EGRESS_TEMPLATE_ERROR', error));
