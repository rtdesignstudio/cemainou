<?php

namespace App\Http\Controllers;

use App\Enums\ActivityKind;
use App\Enums\Source;
use App\Models\Activity;
use App\Models\Project;
use App\Models\Task;
use App\Models\Update;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Timpul din aplicație: cât timp cemainou e deschis în față și omul lucrează în el (clic, scris, derulare în ultimele 2 minute), browserul
 * trimite un semnal pe minut. Semnalul intră în același calcul ca ale agenților (WorkTime): pe proiectul paginii (proiect, sarcină, update),
 * iar paginile generale (Azi, Săptămâna, Ore) rămân timp al zilei, fără proiect. Clienții nu sunt măsurați; fiecare îl poate opri din profil.
 */
class AppPresenceController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || $user->isClient() || ! $user->is_active || ! $user->track_app) {
            return response()->json(['ok' => false]);
        }
        if (! Cache::add('cemainou.app.beat.'.$user->id, 1, 50)) {           // cel mult un semnal pe minut, oricâte taburi
            return response()->json(['ok' => true]);
        }
        Activity::create(['user_id' => $user->id, 'project_id' => self::projectFor($user, (string) $request->input('path'))?->id,
            'kind' => ActivityKind::App, 'source' => Source::Web, 'occurred_at' => now()]);

        return response()->json(['ok' => true]);
    }

    /** Proiectul paginii: /projects/12…, /tasks/34, /updates/56; altfel niciunul (Azi, Săptămâna, Ore…). Doar unul pe care omul îl vede. */
    public static function projectFor(User $user, string $path): ?Project
    {
        $project = match (true) {
            (bool) preg_match('#^/projects/(\d+)#', $path, $m) => Project::find($m[1]),
            (bool) preg_match('#^/tasks/(\d+)#', $path, $m) => Task::find($m[1])?->project,
            (bool) preg_match('#^/updates/(\d+)#', $path, $m) => Update::find($m[1])?->project,
            default => null,
        };

        return $project && $user->canSeeProject($project) ? $project : null;
    }
}
