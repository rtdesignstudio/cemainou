<?php

namespace App\Http\Controllers;

use App\Support\Files;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * „Share" din orice aplicație de pe telefon spre cemainou (aplicația instalată): textul, linkul și fișierele se pun deoparte (o zi), apoi omul
 * alege pe pagina „Din Share" ce devin - idee, sarcină sau notiță - și pe ce proiect. Nu se salvează nimic fără el.
 */
class ShareController extends Controller
{
    public const DISK = 'data';

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user() && ! $request->user()->isClient(), 403);
        $id = (string) Str::uuid();
        $files = [];
        foreach (array_slice((array) $request->file('files'), 0, 6) as $file) {
            if (! $file || ! $file->isValid() || $file->getSize() > Files::maxKb() * 1024 || ! Files::allowed($file->getClientOriginalName())) {
                continue;                                                                   // ce nu se poate păstra, rămâne pe dinafară
            }
            $files[] = ['path' => $file->store("shared/{$id}", self::DISK), 'name' => $file->getClientOriginalName(), 'size' => $file->getSize(), 'mime' => $file->getMimeType()];
        }
        session()->put("shared.{$id}", [
            'title' => Str::limit(trim((string) $request->input('title')), 200, ''),
            'text' => Str::limit(trim((string) $request->input('text')), 5000, ''),
            'url' => filter_var($request->input('url'), FILTER_VALIDATE_URL) ? (string) $request->input('url') : null,
            'files' => $files,
            'at' => now()->timestamp,
        ]);

        return redirect()->route('filament.admin.pages.shared', ['id' => $id]);
    }

    /** Previzualizarea unei imagini puse deoparte: doar din sesiunea omului (id-ul vine din ea), doar imagini. */
    public function file(string $id, int $index): StreamedResponse
    {
        $file = session("shared.{$id}.files.{$index}");
        abort_unless(is_array($file) && str_starts_with((string) ($file['mime'] ?? ''), 'image/') && Storage::disk(self::DISK)->exists($file['path']), 404);

        return Storage::disk(self::DISK)->response($file['path'], $file['name'], ['Content-Type' => $file['mime'], 'Cache-Control' => 'private, max-age=600', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** Fișierele puse deoparte și nefolosite, mai vechi de o zi (curățenia zilnică). */
    public static function cleanup(): void
    {
        $disk = Storage::disk(self::DISK);
        foreach ($disk->directories('shared') as $dir) {
            $files = $disk->files($dir);
            if ($files === [] || collect($files)->every(fn (string $f) => $disk->lastModified($f) < now()->subDay()->timestamp)) {
                $disk->deleteDirectory($dir);
            }
        }
    }
}
