<?php

namespace App\Http\Controllers;

use App\Enums\Source;
use App\Mail\DownloadLinkMail;
use App\Models\DownloadRequest;
use App\Models\Project;
use App\Models\Task;
use App\Support\TeamMail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * La RT: descărcările de pe pagina publică (rt.md/cemainou). Omul își lasă numele și emailul și primește pe loc linkul ultimei versiuni (în
 * răspuns și pe email, în limba paginii); datele se păstrează la Descărcări (DownloadRequest), cu alegerea lui despre vești. O întrebare
 * (mesajul) devine și sarcină pe proiectul ales în config (cemainou.access_requests), dată liderului. Fără cont, cu limită pe adresă și o
 * capcană pentru roboți (câmpul „website", ascuns pe pagină); CORS-ul îl pune Laravel pe api/*. Pe celelalte instalări: 404.
 */
class AccessRequestController
{
    public function __invoke(Request $request): JsonResponse
    {
        $project = filled(config('cemainou.access_requests')) ? Project::where('slug', config('cemainou.access_requests'))->first() : null;
        abort_unless($project && $project->lead, 404);
        $answer = ['ok' => true, 'download' => DownloadRequest::latestUrl(), 'version' => config('cemainou.version')];
        if (filled($request->input('website'))) {
            return response()->json($answer, 201);                              // un robot: pare că a mers, nu se păstrează nimic
        }
        $validator = validator($request->all(), [
            'name' => ['nullable', 'string', 'max:120'],                          // doar emailul e obligatoriu
            'email' => ['required', 'email', 'max:190'],
            'company' => ['nullable', 'string', 'max:160'],
            'team' => ['nullable', 'string', 'max:40'],
            'message' => ['nullable', 'string', 'max:3000'],
            'updates' => ['nullable', 'boolean'],
            'lang' => ['nullable', 'in:ro,en'],
        ]);
        if ($validator->fails()) {
            return response()->json(['ok' => false, 'errors' => $validator->errors()], 422);
        }
        $data = $validator->validated();
        $download = DownloadRequest::create([
            'name' => trim($data['name'] ?? ''), 'email' => mb_strtolower(trim($data['email'])), 'company' => $data['company'] ?? null, 'team' => $data['team'] ?? null,
            'wants_updates' => (bool) ($data['updates'] ?? false), 'lang' => $data['lang'] ?? 'ro', 'version' => config('cemainou.version'),
            'message' => filled($data['message'] ?? null) ? trim($data['message']) : null,
        ]);
        if ($download->message !== null) {                                      // o întrebare (licență, ajutor): și sarcină pentru lider
            $who = $download->company ?: ($download->name ?: $download->email);
            $notes = collect([$download->name !== '' ? 'Nume: '.$download->name : null, 'Email: '.$download->email, $download->company ? 'Firma: '.$download->company : null,
                $download->team ? 'Echipa: '.$download->team : null, 'Limba paginii: '.$download->lang, "\n".$download->message])->filter()->join("\n");
            Task::add($project, $project->lead, Str::limit('Întrebare de pe pagina cemainou: '.$who, 180, ''), $notes, $project->lead, Source::Web);
        }
        if (TeamMail::ready()) {
            defer(function () use ($download) {                                  // după răspuns: linkul e deja pe pagină, emailul nu ține pe nimeni
                try {
                    Mail::to($download->email)->send((new DownloadLinkMail($download))->locale($download->lang));
                } catch (Throwable $e) {
                    report($e);
                }
            });
        }

        return response()->json($answer, 201);
    }
}
