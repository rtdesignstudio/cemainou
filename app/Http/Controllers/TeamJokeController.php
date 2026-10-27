<?php

namespace App\Http\Controllers;

use App\Models\TeamJoke;
use App\Support\Jokes;
use App\Support\Jukebox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Glumele echipei de pe Azi: oricine din echipă pune una scurtă; autorul sau un admin o șterge (App\Support\Jokes). */
class TeamJokeController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        abort_unless(Jokes::showFor($request->user()), 403);
        $data = $request->validate(['text' => ['required', 'string', 'min:5', 'max:'.TeamJoke::MAX]]);
        $joke = TeamJoke::create(['user_id' => $request->user()->id, 'text' => trim(preg_replace('/\s+/u', ' ', $data['text']))]);

        return response()->json(['text' => $joke->text, 'by' => Jukebox::initials($request->user()), 'name' => $request->user()->name, 'id' => $joke->id, 'mine' => true]);
    }

    public function destroy(Request $request, TeamJoke $joke): Response
    {
        abort_unless($joke->user_id === $request->user()->id || $request->user()->isAdmin(), 403);
        $joke->delete();

        return response()->noContent();
    }
}
