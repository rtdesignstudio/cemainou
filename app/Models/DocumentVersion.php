<?php

namespace App\Models;

use App\Enums\Source;
use App\Models\Concerns\StoresTimesInUtc;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** O versiune salvată a unui document: textul întreg de atunci, cine și când, și ce s-a schimbat în cuvintele lui. Nu se mai modifică. */
#[Fillable(['document_id', 'version', 'title', 'body', 'note', 'user_id', 'source'])]
class DocumentVersion extends Model
{
    use StoresTimesInUtc;

    public const UPDATED_AT = null;

    /** Aceleași valori implicite ca în baza de date, ca un model proaspăt creat să le aibă și în memorie. */
    protected $attributes = ['note' => null, 'user_id' => null, 'source' => 'web'];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Versiunea dinainte (pentru „ce s-a schimbat"); null la prima. */
    public function previous(): ?self
    {
        return $this->version > 1 ? static::where('document_id', $this->document_id)->where('version', $this->version - 1)->first() : null;
    }

    protected function casts(): array
    {
        return ['version' => 'integer', 'source' => Source::class];
    }
}
