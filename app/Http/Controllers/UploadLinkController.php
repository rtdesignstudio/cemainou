<?php

namespace App\Http\Controllers;

use App\Enums\Source;
use App\Models\UploadLink;
use App\Support\Files;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Pagina unui link de încărcare (/u/{token}): fără logare, ca pe telefon să meargă din orice browser. Arată doar unde ajung fișierele;
 * după ce omul le trimite, linkul nu mai merge. Fișierele apar pe numele celui care a cerut linkul.
 */
class UploadLinkController extends Controller
{
    public function show(string $token): View
    {
        $link = UploadLink::findUsable($token);

        return view('upload-link', ['link' => $link, 'token' => $token, 'done' => session(self::doneKey($token))]);
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        // două câmpuri (galeria și fișierele): unul lăsat gol trimite o intrare goală, care nu e un fișier
        $request->files->set('files', array_values(array_filter((array) $request->file('files'))));
        $request->validate([
            'files' => 'required|array|min:1|max:'.UploadLink::MAX_FILES,
            'files.*' => ['file', 'max:'.Files::maxKb(), 'extensions:'.implode(',', Files::extensions())],
        ], [
            'files.required' => __('Choose at least one file.'),
            'files.max' => __('At most :count files.', ['count' => UploadLink::MAX_FILES]),
            'files.*.extensions' => __('This kind of file is not accepted. Images, PDF, HTML, text, video, Office and design files are.'),
            'files.*.max' => __('A file is larger than :size MB.', ['size' => intdiv(Files::maxKb(), 1024)]),
        ]);

        $count = DB::transaction(function () use ($token, $request) {
            $link = UploadLink::findUsable($token);                                  // încă bun? (o a doua trimitere găsește linkul folosit)
            abort_unless($link, 410);
            $link->forceFill(['used_at' => now()])->save();
            foreach ($request->file('files') as $file) {
                Files::attach($link->project, $link->user, $file, $file->getClientOriginalName(), null, Source::Web, $link->task);
            }

            return count($request->file('files'));
        });

        return redirect()->route('upload-link.show', $token)->with(self::doneKey($token), $count);
    }

    /** Aceeași urcare, pentru un agent cu terminal (curl -F "files[]=@..."): fără sesiune și CSRF, răspunde JSON; linkul se consumă la fel. */
    public function api(Request $request, string $token): JsonResponse
    {
        $request->files->set('files', array_values(array_filter((array) $request->file('files'))));
        $validator = validator($request->all() + ['files' => $request->file('files')], [
            'files' => 'required|array|min:1|max:'.UploadLink::MAX_FILES,
            'files.*' => ['file', 'max:'.Files::maxKb(), 'extensions:'.implode(',', Files::extensions())],
        ]);
        if ($validator->fails()) {
            return response()->json(['ok' => false, 'error' => $validator->errors()->first(), 'max_file_mb' => intdiv(Files::maxKb(), 1024)], 422);
        }
        $saved = DB::transaction(function () use ($token, $request) {
            $link = UploadLink::findUsable($token);
            if (! $link) {
                return null;
            }
            $link->forceFill(['used_at' => now()])->save();

            return collect($request->file('files'))->map(fn ($file) => Files::attach($link->project, $link->user, $file, $file->getClientOriginalName(), null, Source::fromRequest($request), $link->task))
                ->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'size' => $a->size])->all();
        });
        if ($saved === null) {
            return response()->json(['ok' => false, 'error' => 'This upload link was already used or has expired: ask for a new one with upload_link.'], 410);
        }

        return response()->json(['ok' => true, 'files' => $saved]);
    }

    /** „Adăugat" doar pe pagina linkului folosit, nu pe alt link deschis în același browser. */
    private static function doneKey(string $token): string
    {
        return 'upload-link.done.'.hash('sha256', $token);
    }
}
