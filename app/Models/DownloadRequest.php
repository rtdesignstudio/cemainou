<?php

namespace App\Models;

use App\Models\Concerns\StoresTimesInUtc;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** O descărcare de pe pagina publică: cine, de la ce firmă, câți sunt, ce versiune a primit și dacă vrea vești despre versiunile noi. */
#[Fillable(['name', 'email', 'company', 'team', 'wants_updates', 'lang', 'version', 'message'])]
class DownloadRequest extends Model
{
    use StoresTimesInUtc;

    protected $attributes = ['company' => null, 'team' => null, 'wants_updates' => false, 'lang' => 'ro', 'version' => null, 'message' => null];

    /** Arhiva versiunii de acum, de pe rt.md (instanța RT e mereu la ultima versiune publicată). */
    public static function latestUrl(): string
    {
        return 'https://rt.md/cemainou/releases/cemainou-'.config('cemainou.version').'.zip';
    }

    protected function casts(): array
    {
        return ['wants_updates' => 'boolean'];
    }
}
