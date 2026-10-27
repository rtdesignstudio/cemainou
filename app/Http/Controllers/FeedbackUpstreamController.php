<?php

namespace App\Http\Controllers;

use App\Models\Feedback;
use App\Support\FeedbackUpstream;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * La RT: feedback-ul trimis de instalările care au ales (FeedbackUpstream). Fără cont, cu limită pe adresă; doar textul, pagina, ecranul,
 * browserul, versiunea, numărul instalării și fișierele (aceleași tipuri și număr ca la butonul de feedback). Pe celelalte instalări: 404.
 */
class FeedbackUpstreamController
{
    public function __invoke(Request $request): JsonResponse
    {
        abort_unless(FeedbackUpstream::inbox(), 404);
        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'installation' => ['required', 'uuid'],
            'version' => ['nullable', 'string', 'max:20', 'regex:/^[\w.+-]+$/'],
            'path' => ['nullable', 'string', 'max:500', 'starts_with:/'],
            'viewport' => ['nullable', 'string', 'max:40'],
            'screen' => ['nullable', 'string', 'max:60'],
            'user_agent' => ['nullable', 'string', 'max:500'],
            'files' => ['nullable', 'array', 'max:'.Feedback::MAX_FILES],
            'files.*' => ['file', 'max:20480', 'extensions:'.implode(',', Feedback::EXTENSIONS)],
        ]);
        $feedback = Feedback::submit(null, $data['body'], [
            'url' => $data['path'] ?? null, 'viewport' => $data['viewport'] ?? null, 'screen' => $data['screen'] ?? null,
            'installation' => $data['installation'], 'version' => $data['version'] ?? null,
        ], $request->file('files', []), $data['user_agent'] ?? null);

        return response()->json(['ok' => true, 'id' => $feedback->id], 201);
    }
}
