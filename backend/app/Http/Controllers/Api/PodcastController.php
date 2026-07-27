<?php

namespace App\Http\Controllers\Api;

use App\Models\Podcast;
use App\Models\User;
use App\Services\MediaStorage;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\URL;

class PodcastController extends Controller
{
    public function index(Request $request)
    {
        $isStaff = in_array($request->user()->role->value, ['admin', 'curator', 'trainer'], true);
        $isPaid = $isStaff || $request->user()->access_status === 'paid';

        $podcasts = Podcast::query()
            ->when(! $isPaid, fn ($query) => $query->where('access_level', 'free'))
            ->latest()
            ->get();

        $media = app(MediaStorage::class);
        $podcasts->each(function (Podcast $podcast) use ($request, $media): void {
            $podcast->setAttribute('cover_path', $media->publicUrl($podcast->cover_path));
            $podcast->setAttribute('audio_url', URL::temporarySignedRoute(
                'podcasts.stream',
                now()->addHours(6),
                ['podcast' => $podcast->id, 'user' => $request->user()->id],
            ));
        });

        return response()->json(['data' => $podcasts]);
    }

    public function store(Request $request, MediaStorage $media)
    {
        abort_unless($request->user()->role->value === 'admin', 403);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'cover' => ['nullable', 'image', 'max:10240'],
            'audio' => ['required', 'file', 'mimes:mp3,m4a,aac,ogg,wav,mp4', 'max:512000'],
            'access_level' => ['required', 'in:free,paid'],
        ]);

        $coverPath = null;
        if ($request->hasFile('cover')) {
            $coverPath = $media->store($request->file('cover'), 'podcasts/covers', true);
        }

        $audioPath = $media->store($request->file('audio'), 'podcasts/audio');

        $podcast = Podcast::query()->create([
            'author_id' => $request->user()->id,
            'title' => $validated['title'],
            'description' => $validated['description'],
            'cover_path' => $coverPath,
            'audio_path' => $audioPath,
            'access_level' => $validated['access_level'],
        ]);

        return response()->json(['data' => $podcast], 201);
    }

    public function stream(Request $request, Podcast $podcast, MediaStorage $media)
    {
        $user = User::query()->findOrFail($request->integer('user'));
        $isStaff = in_array($user->role->value, ['admin', 'curator', 'trainer'], true);
        abort_if(! $isStaff && $podcast->access_level === 'paid' && $user->access_status !== 'paid', 403);

        return redirect()->away($media->secureCdnUrl($podcast->audio_path, 3600));
    }

    public function destroy(Request $request, Podcast $podcast, MediaStorage $media)
    {
        abort_unless(in_array($request->user()->role->value, ['admin', 'curator'], true), 403);

        $media->delete($podcast->audio_path);
        $media->delete($podcast->cover_path);
        $podcast->delete();

        return response()->noContent();
    }
}
