<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Starea unei sarcini. O sarcină liberă (fără om) e mereu „De făcut". O idee e notată, dar nu e încă de lucru: nu intră în cele deschise. */
enum TaskStatus: string implements HasColor, HasLabel
{
    case Todo = 'todo';
    case Doing = 'doing';
    case Blocked = 'blocked';
    case Done = 'done';
    case Dropped = 'dropped';
    case Idea = 'idea';

    /** @return list<string> valorile stărilor deschise, pentru interogări */
    public static function openValues(): array
    {
        return [self::Todo->value, self::Doing->value, self::Blocked->value];
    }

    public function isOpen(): bool
    {
        return in_array($this->value, self::openValues(), true);
    }

    public function getLabel(): string
    {
        return __('task_status.status.'.$this->value);                // separat de rândurile update-urilor: în română sarcina e „Gata", „Blocată"
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Doing => 'primary',
            self::Blocked => 'danger',
            default => 'gray',
        };
    }

    /** Rândul de update care corespunde mutării sarcinii; „Renunțat" nu face rând. */
    public function itemType(): ?UpdateItemType
    {
        return match ($this) {
            self::Done => UpdateItemType::Done,
            self::Doing => UpdateItemType::Doing,
            self::Blocked => UpdateItemType::Blocked,
            self::Todo => UpdateItemType::Next,
            self::Dropped, self::Idea => null,
        };
    }
}
