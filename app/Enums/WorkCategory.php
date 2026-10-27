<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Categoria unei munci (pentru orele adăugate manual și rapoarte pe categorii). */
enum WorkCategory: string implements HasLabel
{
    case Development = 'development';
    case Design = 'design';
    case Content = 'content';
    case Consulting = 'consulting';
    case Meeting = 'meeting';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Development => __('Development'),
            self::Design => __('Design'),
            self::Content => __('Content'),
            self::Consulting => __('Consulting'),
            self::Meeting => __('Meeting'),
            self::Other => __('Other'),
        };
    }
}
