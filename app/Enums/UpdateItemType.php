<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Felul unui rând dintr-un update: ce s-a făcut, ce e în lucru, ce blochează, ce urmează, ce s-a decis. */
enum UpdateItemType: string implements HasColor, HasLabel
{
    case Done = 'done';
    case Doing = 'doing';
    case Blocked = 'blocked';
    case Next = 'next';
    case Decision = 'decision';

    public function getLabel(): string
    {
        return match ($this) {
            self::Done => __('Done'),
            self::Doing => __('In progress'),
            self::Blocked => __('Blocked'),
            self::Next => __('Next'),
            self::Decision => __('Decision'),
        };
    }

    /** Titlul secțiunii de pe pagina unui update (la plural acolo unde contează: „Decisions"). */
    public function sectionLabel(): string
    {
        return $this === self::Decision ? __('Decisions') : $this->getLabel();
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Done => 'success',
            self::Doing => 'info',
            self::Blocked => 'danger',
            self::Next => 'gray',
            self::Decision => 'warning',
        };
    }
}
