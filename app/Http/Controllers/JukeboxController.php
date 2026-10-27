<?php

namespace App\Http\Controllers;

use App\Support\Jukebox;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Pagina biroului (Muzica biroului): deschisă pe calculatorul din birou sau al oricui din echipă; cântă doar la cel care a apăsat ultimul „Pornește” coada cu playerul YouTube (App\Support\Jukebox). */
class JukeboxController extends Controller
{
    public function office(Request $request): View
    {
        abort_unless(Jukebox::enabledFor($request->user()), 404);

        return view('music.office', ['state' => Jukebox::forOffice(), 'canPlay' => Jukebox::canPlay($request->user())]);
    }

    /** Semnul paginii, la câteva secunde: unde a ajuns clipul; răspunsul spune ce trebuie să cânte. */
    public function tick(Request $request): JsonResponse
    {
        abort_unless(Jukebox::enabledFor($request->user()), 404);
        $data = $request->validate(['token' => ['required', 'string', 'max:64'], 'video' => ['nullable', 'string', 'max:20'], 'position' => ['nullable', 'numeric', 'min:0'], 'duration' => ['nullable', 'numeric', 'min:0'], 'seek' => ['nullable', 'integer']]);

        return response()->json(Jukebox::tick($data['token'], $data['video'] ?? null, (float) ($data['position'] ?? 0), (float) ($data['duration'] ?? 0), isset($data['seek']) ? (int) $data['seek'] : null));
    }

    /** „Pornește" / „Preia aici": muzica se mută pe calculatorul ăsta. */
    public function claim(Request $request): JsonResponse
    {
        abort_unless(Jukebox::enabledFor($request->user()), 404);
        abort_unless(Jukebox::canPlay($request->user()), 403);                       // pornesc muzica la ei doar adminii și cei aleși în Setări
        $data = $request->validate(['token' => ['required', 'string', 'max:64']]);

        return response()->json(Jukebox::claim($data['token'], $request->user()));
    }

    /** Butoanele mini-playerului (și ✕, care oprește muzica pe calculatorul ăsta). */
    public function control(Request $request): JsonResponse
    {
        abort_unless(Jukebox::enabledFor($request->user()), 404);
        $data = $request->validate(['token' => ['required', 'string', 'max:64'], 'what' => ['required', 'in:play,pause,next,previous,shuffle,repeat,release']]);
        $data['what'] === 'release' ? Jukebox::release($data['token']) : Jukebox::control($data['what']);

        return response()->json(Jukebox::forOffice($data['token']));
    }

    /** Clipul s-a terminat, sau YouTube nu-l lasă redat aici (erorile 100, 101, 150). */
    public function ended(Request $request): JsonResponse
    {
        abort_unless(Jukebox::enabledFor($request->user()), 404);
        $data = $request->validate(['token' => ['required', 'string', 'max:64'], 'video' => ['required', 'string', 'max:20'], 'error' => ['nullable', 'integer']]);

        return response()->json(Jukebox::ended($data['token'], $data['video'], in_array((int) ($data['error'] ?? 0), [100, 101, 150], true)));
    }
}
