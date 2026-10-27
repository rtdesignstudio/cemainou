<?php

namespace App\Support;

use App\Enums\AttachmentKind;
use App\Enums\Source;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Fișierele atașate proiectelor: ce tipuri se primesc, unde se păstrează (data/files/{proiect}/) și cum se servesc.
 * Tipul servit vine din extensie, nu din conținut. HTML și SVG se deschid izolat (CSP sandbox): scripturile unei prezentări merg,
 * dar într-o origine opacă, fără acces la sesiunea sau datele aplicației. Ce nu se poate arăta în browser se descarcă.
 */
class Files
{
    /** Plafonul absolut (încărcarea temporară a lui Livewire); limita reală e cea din Setări. */
    public const CEILING_KB = 102400;

    public static function maxKb(): int
    {
        return Settings::get('max_upload_mb') * 1024;
    }

    /** Se deschid în browser, cu tipul de aici. */
    public const INLINE = [
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp', 'avif' => 'image/avif',
        'svg' => 'image/svg+xml', 'pdf' => 'application/pdf', 'html' => 'text/html', 'htm' => 'text/html',
        'md' => 'text/plain', 'txt' => 'text/plain', 'csv' => 'text/plain', 'mp4' => 'video/mp4', 'mov' => 'video/quicktime',
    ];

    /** Se descarcă (documente Office, surse de design, arhive). */
    public const DOWNLOAD = ['docx', 'xlsx', 'pptx', 'key', 'pages', 'numbers', 'psd', 'ai', 'eps', 'sketch', 'fig', 'zip'];

    /** Pot rula scripturi: se servesc doar izolat. */
    public const SANDBOXED = ['html', 'htm', 'svg'];

    /** Cât primește PHP-ul serverului într-un fișier (cel mai mic dintre upload_max_filesize și post_max_size), în MB. */
    public static function phpLimitMb(): int
    {
        $bytes = fn (string $value) => (int) $value * match (strtoupper(substr(trim($value), -1))) {
            'G' => 1073741824, 'M' => 1048576, 'K' => 1024, default => 1
        };

        return intdiv(min($bytes(ini_get('upload_max_filesize') ?: '2M'), $bytes(ini_get('post_max_size') ?: '8M')), 1048576);
    }

    /** @return list<string> ce se poate urca acum (fără HTML și SVG, dacă adminul le-a oprit din Setări) */
    public static function extensions(): array
    {
        $all = [...array_keys(self::INLINE), ...self::DOWNLOAD];

        return Settings::get('allow_html') ? $all : array_values(array_diff($all, self::SANDBOXED));
    }

    public static function allowed(string $name): bool
    {
        return in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::extensions(), true);
    }

    /** Păstrează un fișier (urcat pe web sau trimis de agent) și îl atașează proiectului. */
    public static function attach(Project $project, User $by, UploadedFile|string $file, string $name, ?string $title = null, Source $source = Source::Web, ?Task $task = null): Attachment
    {
        abort_if(Demo::on(), 403, __('Adding files is turned off in the demo.'));   // demo-ul public nu găzduiește fișierele nimănui
        $name = self::cleanName($name);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $path = $project->id.'/'.strtolower((string) Str::ulid()).'.'.$ext;
        $disk = Storage::disk(Attachment::DISK);
        $file instanceof UploadedFile ? $file->storeAs((string) $project->id, basename($path), Attachment::DISK) : $disk->put($path, $file);

        return ($task ? $task->attachments() : $project->attachments())->create([
            'project_id' => $project->id,
            'user_id' => $by->id, 'kind' => AttachmentKind::File, 'title' => Str::limit(trim((string) $title) ?: pathinfo($name, PATHINFO_FILENAME), 160, ''),
            'path' => $path, 'name' => $name, 'mime' => $disk->mimeType($path) ?: null, 'size' => $disk->size($path), 'source' => $source,
        ]);
    }

    /** Răspunsul pentru /files/{id}: tipul din extensie, izolat dacă poate rula scripturi, descărcat dacă browserul nu-l poate arăta. */
    public static function response(Attachment $attachment): BinaryFileResponse
    {
        $ext = $attachment->extension();
        $inline = self::INLINE[$ext] ?? null;
        $headers = [
            'Content-Type' => $inline ? $inline.(str_starts_with($inline, 'text/') ? '; charset=UTF-8' : '') : 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ];
        if (in_array($ext, self::SANDBOXED, true)) {
            $headers['Content-Security-Policy'] = "sandbox allow-scripts allow-popups allow-modals; frame-ancestors 'none'";
        }

        $response = response()->file(Storage::disk(Attachment::DISK)->path($attachment->path), $headers);
        $response->setContentDisposition($inline ? 'inline' : 'attachment', $attachment->name ?? basename($attachment->path), Str::ascii($attachment->name ?? 'file'));

        return $response;
    }

    public static function size(?int $bytes): string
    {
        return match (true) {
            $bytes === null => '',
            $bytes < 1024 => "{$bytes} B",
            $bytes < 1048576 => round($bytes / 1024).' KB',
            default => round($bytes / 1048576, 1).' MB',
        };
    }

    /** Numele original, fără căi și caractere de control. */
    private static function cleanName(string $name): string
    {
        $name = trim(preg_replace('/[\x00-\x1f\x7f\/\\\\]+/u', ' ', basename(str_replace('\\', '/', $name))));

        return Str::limit($name, 200, '') ?: 'file';
    }
}
