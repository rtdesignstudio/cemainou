<?php

namespace App\Filament\Pages\Auth;

use App\Support\Branding;
use App\Support\TeamMail;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset as BaseRequestPasswordReset;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Illuminate\Contracts\Support\Htmlable;

/** „Ai uitat parola?": linkul pleacă prin email; cât timp emailul nu e configurat (Setări), pagina spune cui să ceri o parolă nouă. */
class RequestPasswordReset extends BaseRequestPasswordReset
{
    /** Cu logo-ul companiei sus, numele cemainou coboară mic, sub formular (company-logo / auth-brand). */
    public function hasLogo(): bool
    {
        return ! Branding::logoUrl();
    }

    public function request(): void
    {
        if (! TeamMail::ready()) {
            Notification::make()->title(__('Email is not set up yet.'))->body(__('Ask an admin to set a new password for you (Admin → People).'))->warning()->send();

            return;
        }

        parent::request();
    }

    protected function getEmailFormComponent(): Component
    {
        return parent::getEmailFormComponent()->extraInputAttributes(Login::PLAIN_TYPING + ['inputmode' => 'email']);
    }

    /** emailul cu litere mici, ca la login */
    protected function getCredentialsFromFormData(array $data): array
    {
        return ['email' => mb_strtolower(trim((string) $data['email']))];
    }

    public function getSubheading(): string|Htmlable|null
    {
        return TeamMail::ready() ? parent::getSubheading() : __('Email is not set up yet: ask an admin to set a new password for you (Admin → People).');
    }
}
