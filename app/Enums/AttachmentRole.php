<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Ce e un fișier sau un link pe proiect. Demo-ul stă fixat sus pe pagina proiectului și pe card („Vezi demo"). */
enum AttachmentRole: string implements HasLabel
{
    case Demo = 'demo';
    case Brief = 'brief';
    case Design = 'design';
    case Presentation = 'presentation';
    case Document = 'document';
    case Contract = 'contract';
    case Source = 'source';
    case Media = 'media';

    public function getLabel(): string
    {
        return match ($this) {
            self::Demo => __('Demo'),
            self::Brief => __('Brief'),
            self::Design => __('Mockup'),
            self::Presentation => __('Presentation'),
            self::Document => __('Document'),
            self::Contract => __('Contracts and papers'),
            self::Source => __('Source files'),
            self::Media => __('Photo and video'),
        };
    }

    /** @return array<string, string> pentru select-uri */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $role) => [$role->value => $role->getLabel()])->all();
    }
}
