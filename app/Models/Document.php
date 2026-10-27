<?php

namespace App\Models;

use App\Enums\Source;
use App\Exceptions\DocumentConflict;
use App\Models\Concerns\StoresTimesInUtc;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Un document Markdown al unui proiect (brief, concept, decizii, stare). Fiecare salvare e o versiune, cu autor și dată; nimeni nu suprascrie
 * versiunea altcuiva: salvarea pleacă de la versiunea pe care ai citit-o (base) și, dacă între timp a salvat altcineva, e refuzată (DocumentConflict).
 * Versiunea 0 = creat, încă nescris. Titlul se poate schimba; adresa (slug) rămâne.
 */
#[Fillable(['project_id', 'client_visible', 'is_context', 'title', 'slug', 'user_id'])]
class Document extends Model
{
    protected static function booted(): void
    {
        static::creating(function (Document $document) {              // scris de client: se vede de el (și de echipă)
            if (User::find($document->user_id)?->isClient()) {
                $document->client_visible = true;
            }
        });
    }

    use StoresTimesInUtc;

    public const MAX_BODY = 200000;

    /** Aceleași valori implicite ca în baza de date, ca un model proaspăt creat să le aibă și în memorie. */
    protected $attributes = ['client_visible' => false, 'is_context' => false, 'body' => '', 'version' => 0, 'user_id' => null, 'updated_by' => null, 'archived_at' => null];

    /** Un document nou pe proiect, cu primul text (versiunea 1) sau gol, de scris pe pagina lui. */
    public static function start(Project $project, User $by, string $title, ?string $body = null, Source $source = Source::Web, ?string $note = null): self
    {
        $title = self::cleanTitle($title);
        $base = Str::limit(Str::slug($title), 130, '') ?: 'document';   // coloana are 140: loc și pentru „-N"; slug-ul poate ieși mai lung decât titlul
        $slug = $base;
        for ($i = 2; $project->documents()->where('slug', $slug)->exists(); $i++) {     // și printre cele arhivate: adresa unui document nu se refolosește
            $slug = "{$base}-{$i}";
        }

        $document = $project->documents()->create(['title' => $title, 'slug' => $slug, 'user_id' => $by->id]);
        if (trim((string) $body) !== '') {
            $document->write($by, $body, 0, $source, $note);
        }

        return $document;
    }

    /** Contextul nou al proiectului (/ctx): titlul în limba echipei, fixat primul printre documente. Unul singur pe proiect. */
    public static function startContext(Project $project, User $by, string $body, Source $source = Source::Web, ?string $note = null): self
    {
        $document = self::start($project, $by, __('Project context', [], Settings::get('locale')));
        $document->forceFill(['is_context' => true])->save();
        $document->write($by, $body, 0, $source, $note);

        return $document->fresh();
    }

    /** A citit versiunea de acum (agentul lui, din project_context sau la pornirea sesiunii). */
    public function markRead(User $user): void
    {
        DB::table('document_reads')->updateOrInsert(['document_id' => $this->id, 'user_id' => $user->id], ['version' => $this->version, 'read_at' => now()]);
    }

    /** Ultima versiune citită de om, sau null. */
    public function readBy(User $user): ?int
    {
        $version = DB::table('document_reads')->where('document_id', $this->id)->where('user_id', $user->id)->value('version');

        return $version === null ? null : (int) $version;
    }

    /** Documentul activ al proiectului cu titlul sau adresa date (fără diferență de litere mari / mici). */
    public static function findIn(Project $project, string $title): ?self
    {
        $title = self::cleanTitle($title);

        return $project->documents()->active()->where(fn ($q) => $q->where('slug', Str::slug($title))->orWhere('slug', $title)
            ->orWhereRaw('lower(title) = ?', [mb_strtolower($title)]))->first();
    }

    public static function cleanTitle(string $title): string
    {
        return Str::limit(trim(preg_replace('/\s+/u', ' ', $title)), 120, '');
    }

    /**
     * O versiune nouă, pornind de la versiunea citită ($base). Dacă între timp a salvat altcineva, nu scrie nimic (DocumentConflict).
     * Același text și același titlu: nicio versiune nouă (null).
     */
    public function write(User $by, string $body, int $base, Source $source = Source::Web, ?string $note = null, ?string $title = null): ?DocumentVersion
    {
        $body = str_replace("\r\n", "\n", $body);
        $title = $title !== null ? self::cleanTitle($title) : $this->title;
        if ($base === $this->version && $body === $this->body && $title === $this->title && $this->version > 0) {
            return null;
        }

        return DB::transaction(function () use ($by, $body, $base, $source, $note, $title) {
            $saved = static::whereKey($this->id)->whereNull('archived_at')->where('version', $base)     // atomic: doar dacă nimeni n-a salvat între timp
                ->update(['title' => $title, 'body' => $body, 'version' => $base + 1, 'updated_by' => $by->id, 'updated_at' => now()]);
            if (! $saved) {
                throw new DocumentConflict($this->fresh());
            }
            $this->refresh();

            return $this->versions()->create([
                'version' => $this->version, 'title' => $title, 'body' => $body, 'user_id' => $by->id, 'source' => $source,
                'note' => $note !== null && trim($note) !== '' ? Str::limit(trim($note), 200, '') : null,
            ]);
        });
    }

    public function archive(): void
    {
        $this->forceFill(['archived_at' => now()])->save();
    }

    public function scopeContext(Builder $query): void
    {
        $query->where('is_context', true)->whereNull('archived_at');
    }

    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class)->orderByDesc('version');
    }

    protected function casts(): array
    {
        return ['client_visible' => 'boolean', 'is_context' => 'boolean', 'version' => 'integer', 'archived_at' => 'datetime'];
    }
}
