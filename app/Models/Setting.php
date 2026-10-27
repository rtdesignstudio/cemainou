<?php

namespace App\Models;

use App\Models\Concerns\StoresTimesInUtc;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** O setare a sistemului (cheie → valoare JSON). Se citește prin App\Support\Settings, care știe și valorile implicite și regulile. */
#[Fillable(['key', 'value', 'updated_by'])]
class Setting extends Model
{
    use StoresTimesInUtc;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    /** Aceleași valori implicite ca în baza de date, ca un model proaspăt creat să le aibă și în memorie. */
    protected $attributes = ['value' => null, 'updated_by' => null];

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    protected function casts(): array
    {
        return ['value' => 'json'];
    }
}
