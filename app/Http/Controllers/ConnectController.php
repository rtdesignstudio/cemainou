<?php

namespace App\Http\Controllers;

use App\Support\ConnectCode;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Schimbul codului de conectare pe cheia agentului (scriptul de instalare): textul cheii, simplu, ca shell-ul să-l citească fără JSON. */
class ConnectController
{
    public function __invoke(Request $request): Response
    {
        $code = (string) $request->input('code');
        $token = str_starts_with($code, 'wsc_') ? ConnectCode::redeem($code) : null;

        return $token
            ? response($token->plainTextToken, 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store, private'])
            : response('The connect code expired or was already used. Create a new one from your profile.', 410, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
