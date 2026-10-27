<?php

namespace App\Support;

use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Turul „cum funcționează cemainou" (public/wsa/tour.js): echipa vede 9 pași, clientul 6, în limba lui.
 * Textele sunt în script, în T("..."); aici se citesc din fișier (o dată pe versiunea lui) și se traduc cu __(), deci un text nou din script
 * ajunge singur în lista de tradus (lang/ro.json). Turul nu pornește singur: din Ghid, din meniul contului sau din cardul de început de pe Azi.
 */
class Tour
{
    public const SCRIPT = 'wsa/tour.js';

    /** @return list<string> textele din script, în engleză */
    public static function strings(): array
    {
        $file = public_path(self::SCRIPT);

        return Cache::rememberForever('cemainou.tour.strings.'.@filemtime($file), function () use ($file) {
            preg_match_all('/\bT\("((?:[^"\\\\]|\\\\.)*)"\)/u', (string) @file_get_contents($file), $m);

            return array_values(array_unique(array_map('stripcslashes', $m[1])));
        });
    }

    /** @return array<string, mixed> ce primește scriptul (window.wsaTourData) */
    public static function data(User $user): array
    {
        $client = $user->isClient();
        $project = $client ? Project::visibleTo($user)->active()->orderBy('name')->first() : null;

        return [
            'variant' => $client ? 'client' : 'team',
            'dict' => collect(self::strings())->mapWithKeys(fn (string $s) => [$s => __($s)])->filter(fn ($t, $s) => $t !== $s)->all(),
            'font' => asset('wsa/InterTight-Variable.ttf'),
            'seen' => $user->tour_seen_at !== null,
            'seenUrl' => route('tour.seen'),
            'ctaUrl' => $client ? ($project ? ProjectResource::getUrl('view', ['record' => $project]) : Dashboard::getUrl()) : EditProfile::getUrl().'?connect=1',
        ];
    }

    public static function scriptUrl(): string
    {
        return asset(self::SCRIPT).'?v='.config('cemainou.version');
    }
}
