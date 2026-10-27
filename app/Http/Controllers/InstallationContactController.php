<?php

namespace App\Http\Controllers;

use App\Models\KnownInstallation;
use App\Support\FeedbackUpstream;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * La RT: „Ține-mă la curent" de la o instalare, doar când adminul ei l-a pornit și a scris un email (App\Support\KeepInTouch).
 * hello: instalarea, emailul, firma și versiunea; bye: se retrage. Pe celelalte instalări: 404.
 */
class InstallationContactController
{
    public function hello(Request $request): JsonResponse
    {
        abort_unless(FeedbackUpstream::inbox(), 404);
        $data = $request->validate([
            'installation' => ['required', 'uuid'], 'email' => ['required', 'email', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'], 'name' => ['nullable', 'string', 'max:255'],
            'version' => ['nullable', 'string', 'max:20', 'regex:/^[\w.+-]+$/'],
        ]);
        $known = KnownInstallation::firstOrNew(['installation' => $data['installation']]);
        $known->fill(['email' => $data['email'], 'company' => $data['company'] ?? null, 'name' => $data['name'] ?? null, 'version' => $data['version'] ?? null,
            'first_seen_at' => $known->first_seen_at ?? now(), 'last_seen_at' => now(), 'left_at' => null])->save();

        return response()->json(['ok' => true]);
    }

    public function bye(Request $request): JsonResponse
    {
        abort_unless(FeedbackUpstream::inbox(), 404);
        $data = $request->validate(['installation' => ['required', 'uuid']]);
        KnownInstallation::where('installation', $data['installation'])->update(['left_at' => now()]);

        return response()->json(['ok' => true]);
    }
}
