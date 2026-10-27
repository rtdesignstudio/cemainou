<?php

namespace App\Models;

use App\Enums\FeedbackStatus;
use App\Models\Concerns\StoresTimesInUtc;
use App\Support\Demo;
use App\Support\FeedbackUpstream;
use App\Support\Inbox;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Un feedback despre cemainou: ce a observat omul, unde (pagina), pe ce ecran și browser, cu fișierele lui. Fișierele stau pe discul data
 * (data/files/feedback/{id}/), în `files`: list<array{path, name, mime, size}>; se servesc doar adminului și autorului (route feedback.file).
 */
#[Fillable(['user_id', 'body', 'url', 'viewport', 'screen', 'user_agent', 'app_version', 'files', 'status', 'resolution', 'resolved_at', 'resolved_by', 'installation', 'sent_to_rt_at'])]
class Feedback extends Model
{
    use StoresTimesInUtc;

    public const DISK = 'data';

    protected $table = 'feedback';

    /** Aceleași valori implicite ca în baza de date, ca un model proaspăt creat să le aibă și în memorie. */
    protected $attributes = ['url' => null, 'viewport' => null, 'screen' => null, 'user_agent' => null, 'app_version' => null, 'files' => null,
        'status' => 'open', 'resolution' => null, 'resolved_at' => null, 'resolved_by' => null, 'installation' => null, 'sent_to_rt_at' => null];

    protected static function booted(): void
    {
        static::deleting(fn (Feedback $feedback) => Storage::disk(self::DISK)->deleteDirectory("feedback/{$feedback->id}"));
    }

    /** Capturi, video și PDF: ce se trimite de obicei de pe telefon (fără HTML sau SVG). */
    public const EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'heic', 'heif', 'mp4', 'mov', 'pdf', 'txt'];

    public const MAX_FILES = 6;

    /**
     * Păstrează feedback-ul și fișierele lui; adminii (în afară de autor) îl primesc în clopoțel.
     *
     * La RT, feedback-ul primit de la o instalare (FeedbackUpstream) vine fără om ($by = null), cu numărul instalării și versiunea ei în $context.
     *
     * @param  array{url?: ?string, viewport?: ?string, screen?: ?string, installation?: ?string, version?: ?string}  $context
     * @param  list<UploadedFile>  $files
     */
    public static function submit(?User $by, string $body, array $context, array $files = [], ?string $userAgent = null): self
    {
        $feedback = self::create([
            'user_id' => $by?->id, 'body' => trim($body), 'installation' => Str::limit((string) ($context['installation'] ?? ''), 40, '') ?: null,
            'url' => preg_match('#^https?://#i', (string) ($context['url'] ?? '')) ? Str::limit((string) $context['url'], 2000, '') : null,   // doar http(s): un link „javascript:" ar rula la admin
            'viewport' => Str::limit((string) ($context['viewport'] ?? ''), 40, '') ?: null,
            'screen' => Str::limit((string) ($context['screen'] ?? ''), 60, '') ?: null,
            'user_agent' => Str::limit((string) $userAgent, 500, '') ?: null,
            'app_version' => Str::limit((string) ($context['version'] ?? config('cemainou.version')), 20, ''),
        ]);
        $stored = [];
        foreach (array_slice(Demo::on() ? [] : $files, 0, self::MAX_FILES) as $file) {   // demo: fără fișiere
            $name = trim(preg_replace('/[\x00-\x1f\x7f\/\\\\]+/u', ' ', basename($file->getClientOriginalName()))) ?: 'file';
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $path = $file->storeAs("feedback/{$feedback->id}", strtolower((string) Str::ulid()).'.'.$ext, self::DISK);
            $stored[] = ['path' => $path, 'name' => Str::limit($name, 200, ''), 'mime' => $file->getMimeType(), 'size' => $file->getSize()];
        }
        if ($stored) {
            $feedback->forceFill(['files' => $stored])->save();
        }
        Inbox::feedback($feedback);
        FeedbackUpstream::queue($feedback);                                     // și la RT, dacă instalarea a ales (Setări)

        return $feedback;
    }

    /** Rezolvat (cu ce s-a făcut, dacă e ceva de spus) sau redeschis; autorul află când e rezolvat. */
    public function resolve(User $by, ?string $resolution = null): void
    {
        $this->forceFill(['status' => FeedbackStatus::Resolved, 'resolution' => trim((string) $resolution) ?: null, 'resolved_at' => now(), 'resolved_by' => $by->id])->save();
        Inbox::feedbackResolved($this, $by);
    }

    public function reopen(): void
    {
        $this->forceFill(['status' => FeedbackStatus::Open, 'resolved_at' => null, 'resolved_by' => null])->save();
    }

    public function isImage(int $index): bool
    {
        return in_array(strtolower(pathinfo($this->files[$index]['path'] ?? '', PATHINFO_EXTENSION)), ['png', 'jpg', 'jpeg', 'gif', 'webp'], true);
    }

    /** Cine l-a trimis, pentru liste: omul, sau (la RT) instalarea de unde a venit. */
    public function from(): ?string
    {
        return $this->author?->name ?? ($this->installation && $this->user_id === null ? __('Installation :id', ['id' => substr($this->installation, 0, 8)]) : null);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function isOpen(): bool
    {
        return $this->status === FeedbackStatus::Open;
    }

    protected function casts(): array
    {
        return ['status' => FeedbackStatus::class, 'files' => 'array', 'resolved_at' => 'datetime', 'sent_to_rt_at' => 'datetime'];
    }
}
