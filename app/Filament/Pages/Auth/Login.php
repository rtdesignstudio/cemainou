<?php

namespace App\Filament\Pages\Auth;

use App\Support\Branding;
use App\Support\Demo;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Schemas\Components\Component;
use SensitiveParameter;

/**
 * Login-ul panoului, sigur pe telefon: emailul se compară cu litere mici (SQLite ține cont de majuscule, iar tastaturile de Android scriu
 * „Radu@..." singure), iar în câmpuri tastatura nu corectează și nu pune majuscule de la sine - parola rămâne exact cum o tastează omul.
 */
class Login extends BaseLogin
{
    /** Cu logo-ul companiei sus, numele cemainou coboară mic, sub formular (company-logo / auth-brand). */
    public function hasLogo(): bool
    {
        return ! Branding::logoUrl();
    }

    /** fără corectare, fără majuscule automate, fără sugestii */
    public const PLAIN_TYPING = ['autocapitalize' => 'none', 'autocorrect' => 'off', 'spellcheck' => 'false'];

    protected function getEmailFormComponent(): Component
    {
        return parent::getEmailFormComponent()->extraInputAttributes(self::PLAIN_TYPING + ['inputmode' => 'email']);
    }

    protected function getPasswordFormComponent(): Component
    {
        return parent::getPasswordFormComponent()->extraInputAttributes(self::PLAIN_TYPING + ['type' => 'password']);   // parolă din prima clipă, nu doar după ce pornește Alpine
    }

    /**
     * „Ține-mă minte" bifat de la început: aplicația de pe telefon stă deschisă ore întregi, iar fără el sesiunea expira după 2 ore fără activitate
     * și omul era scos din cont la următoarea deschidere (feedback #27). Pe un calculator străin se debifează.
     */
    protected function getRememberFormComponent(): Component
    {
        return parent::getRememberFormComponent()->default(true);
    }

    /** În demo: fără email și parolă, doar oamenii inventați cu care intri dintr-un clic (demo-login). */
    public function getFormContentComponent(): Component
    {
        return parent::getFormContentComponent()->visible(fn (): bool => ! Demo::on() && blank($this->userUndertakingMultiFactorAuthentication));
    }

    protected function getCredentialsFromFormData(#[SensitiveParameter] array $data): array
    {
        return ['email' => mb_strtolower(trim((string) $data['email'])), 'password' => $data['password']];
    }
}
