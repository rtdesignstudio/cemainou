<?php

namespace App\Http\Controllers;

use App\Models\Badge;
use App\Support\Badges\Badges;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Badge-urile din pagină: „le-am văzut" (după animație sau ecranul de pornire) și „bravo" de la un coleg. */
class BadgeController extends Controller
{
    public function seen(Request $request): JsonResponse
    {
        $ids = collect($request->validate(['ids' => ['required', 'array', 'max:50'], 'ids.*' => ['integer']])['ids'])->map(fn ($id) => (int) $id)->all();
        Badges::markSeen($request->user(), $ids);

        return response()->json(['ok' => true]);
    }

    public function kudos(Request $request, Badge $badge): JsonResponse
    {
        $note = $request->validate(['note' => ['nullable', 'string', 'max:120']])['note'] ?? null;
        abort_unless(Badges::kudos($badge, $request->user(), $note), 403);

        return response()->json(['ok' => true, 'count' => $badge->kudos()->count()]);
    }
}
