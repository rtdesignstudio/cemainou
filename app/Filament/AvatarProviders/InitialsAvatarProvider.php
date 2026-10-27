<?php

namespace App\Filament\AvatarProviders;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Avatarul: inițialele negre într-un pătrat gri deschis (negrul plin ieșea prea mult în față), ca SVG în pagină. Nimic nu pleacă spre un serviciu extern (implicit Filament
 * cerea imaginea de la ui-avatars.com, cu numele omului), merge și în LAN, și e pătrat, ca restul interfeței.
 */
class InitialsAvatarProvider implements AvatarProvider
{
    public function get(Model|Authenticatable $record): string
    {
        $initials = str(Filament::getNameForDefaultAvatar($record))->trim()->explode(' ')
            ->map(fn (string $part) => mb_substr(preg_replace('/^[^\p{L}\p{N}]+/u', '', $part), 0, 1))
            ->filter()->take(2)->join('');

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" fill="#ededed"/>'
            .'<text x="32" y="32" dy=".36em" text-anchor="middle" font-family="Inter Tight, Inter, Helvetica, Arial, sans-serif" font-size="26" font-weight="500" fill="#0a0a0a">'
            .e(mb_strtoupper($initials ?: '?')).'</text></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
