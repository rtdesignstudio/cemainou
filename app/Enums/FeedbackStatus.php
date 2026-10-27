<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Starea unui feedback: deschis (de văzut) sau rezolvat. */
enum FeedbackStatus: string implements HasColor, HasLabel
{
    case Open = 'open';
    case Resolved = 'resolved';

    public function getLabel(): string
    {
        return $this === self::Open ? __('Open feedback') : __('Resolved');
    }

    public function getColor(): string
    {
        return $this === self::Open ? 'primary' : 'gray';                    // roșul rămâne doar pentru blocaje
    }
}
