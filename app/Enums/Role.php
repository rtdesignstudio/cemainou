<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Rolul unui om în echipă: cine vede și cine administrează ce (tabelul „Cine vede ce" din plan). */
enum Role: string implements HasColor, HasLabel
{
    case Admin = 'admin';
    case Lead = 'lead';
    case Member = 'member';
    case Collaborator = 'collaborator';                      // freelancer, extern: lucrează ca un membru, dar vede doar proiectele pe care e adăugat
    case Client = 'client';                                  // un om de la client: vede doar proiectele firmei lui pornite ca vizibile

    public function getLabel(): string
    {
        return match ($this) {
            self::Admin => __('Admin'),
            self::Lead => __('Lead'),
            self::Member => __('Member'),
            self::Collaborator => __('Collaborator'),
            self::Client => __('Client'),
        };
    }

    public function getColor(): string
    {
        return 'gray';                                      // negrul e singurul accent; roșul rămâne pentru blocaje
    }
}
