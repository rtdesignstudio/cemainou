<?php

namespace App\Models;

use App\Enums\AttachmentKind;
use App\Enums\AttachmentRole;
use App\Enums\Source;
use App\Models\Concerns\StoresTimesInUtc;
use App\Support\LinkInfo;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Un atașament de proiect: un fișier (imagine, PDF, prezentare HTML, machetă; păstrat în data/files, servit doar celor logați)
 * sau un link extern (Figma, Drive, Behance). Ce tipuri se primesc și cum se servesc: App\Support\Files.
 */
#[Fillable(['project_id', 'task_id', 'client_visible', 'user_id', 'kind', 'role', 'title', 'url', 'path', 'name', 'mime', 'size', 'source'])]
class Attachment extends Model
{
    use StoresTimesInUtc;

    public const DISK = 'data';

    /** Aceleași valori implicite ca în baza de date, ca un model proaspăt creat să le aibă și în memorie. */
    protected $attributes = ['task_id' => null, 'role' => null, 'client_visible' => false, 'user_id' => null, 'url' => null, 'path' => null, 'name' => null, 'mime' => null, 'size' => null, 'source' => 'web'];

    protected static function booted(): void
    {
        static::creating(function (Attachment $attachment) {          // adăugat de client: se vede de el (și de echipă), fără bifa „Vizibil clientului"
            if (! $attachment->task_id && User::find($attachment->user_id)?->isClient()) {
                $attachment->client_visible = true;
            }
        });
        static::deleted(function (Attachment $attachment) {          // fișierul pleacă odată cu rândul
            if ($attachment->path) {
                Storage::disk(self::DISK)->delete($attachment->path);
            }
        });
    }

    public function isFile(): bool
    {
        return $this->kind === AttachmentKind::File;
    }

    /** Extensia fișierului, după numele original (pentru iconiță și felul în care se servește). */
    public function extension(): string
    {
        return strtolower(pathinfo((string) ($this->name ?? $this->path), PATHINFO_EXTENSION));
    }

    /** O imagine încărcată (se deschide în galeria pe tot ecranul, cu miniatură în listă). */
    public function isImage(): bool
    {
        return $this->isFile() && in_array($this->extension(), ['png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'svg'], true);
    }

    /** Iconița de linie după fel: link, imagine, PDF, prezentare HTML, video, altceva. */
    public function icon(): Heroicon
    {
        return match (true) {
            ! $this->isFile() => Heroicon::OutlinedLink,
            in_array($this->extension(), ['png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'svg'], true) => Heroicon::OutlinedPhoto,
            in_array($this->extension(), ['html', 'htm', 'key', 'pptx'], true) => Heroicon::OutlinedPresentationChartBar,
            in_array($this->extension(), ['mp4', 'mov'], true) => Heroicon::OutlinedFilm,
            in_array($this->extension(), ['pdf', 'md', 'txt', 'docx', 'pages'], true) => Heroicon::OutlinedDocument,
            default => Heroicon::OutlinedPaperClip,
        };
    }

    /** Unde duce: fișierul (servit de aplicație) sau linkul extern. */
    public function href(): string
    {
        return $this->isFile() ? route('files.show', $this) : (string) $this->url;
    }

    /** Pentru un link: serviciul cunoscut (Figma, Google Drive, Loom...), altfel null. */
    public function service(): ?string
    {
        return $this->url ? LinkInfo::service($this->url) : null;
    }

    /** Pentru un link: doar domeniul (figma.com), ca să se vadă unde duce. */
    public function host(): ?string
    {
        return $this->url ? Str::after((string) parse_url($this->url, PHP_URL_HOST), 'www.') : null;
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    protected function casts(): array
    {
        return ['client_visible' => 'boolean', 'kind' => AttachmentKind::class, 'role' => AttachmentRole::class, 'source' => Source::class, 'size' => 'integer'];
    }
}
