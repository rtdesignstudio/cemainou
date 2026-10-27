<?php

namespace App\Filament\Pages\Auth;

use App\Models\User;
use App\Support\Branding;
use App\Support\Invitations;
use Filament\Actions\Action;
use Filament\Auth\Http\Responses\Contracts\PasswordResetResponse;
use Filament\Auth\Pages\PasswordReset\ResetPassword;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Support\Htmlable;
use SensitiveParameter;

/**
 * Pagina din invitație: omul își alege parola și intră direct. Linkul (/invitation/{email}/{token}) merge 7 zile și o singură dată;
 * aceeași formă ca resetarea parolei, cu tokenul invitației (păstrat doar ca hash pe cont).
 */
class AcceptInvitation extends ResetPassword
{
    /** Cu logo-ul companiei sus, numele cemainou coboară mic, sub formular (company-logo / auth-brand). */
    public function hasLogo(): bool
    {
        return ! Branding::logoUrl();
    }

    public function mount(?string $email = null, #[SensitiveParameter] ?string $token = null): void
    {
        if (Filament::auth()->check()) {
            redirect()->intended(Filament::getUrl());

            return;
        }

        parent::mount($email, $token);
        if (! Invitations::valid($this->person(), $this->token)) {
            Notification::make()->title(__('This invitation is no longer valid.'))->body(__('Ask an admin for a new link.'))->danger()->persistent()->send();
            $this->redirect(Filament::getLoginUrl());
        }
    }

    public function resetPassword(): ?PasswordResetResponse
    {
        $person = $this->person();
        if (! Invitations::valid($person, $this->token)) {
            Notification::make()->title(__('This invitation is no longer valid.'))->body(__('Ask an admin for a new link.'))->danger()->send();

            return null;
        }

        $data = $this->form->getState();
        Invitations::accept($person, $data['password']);
        Filament::auth()->login($person);
        session()->regenerate();
        $this->redirect(Filament::getUrl());

        return null;
    }

    private function person(): ?User
    {
        return $this->email ? User::where('email', mb_strtolower($this->email))->first() : null;
    }

    public function getTitle(): string|Htmlable
    {
        return __('Welcome to cemainou');
    }

    public function getHeading(): string|Htmlable|null
    {
        return __('Welcome to cemainou');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('Choose a password; next time you sign in with your email and this password.');
    }

    public function getResetPasswordFormAction(): Action
    {
        return parent::getResetPasswordFormAction()->label(__('Sign in'));
    }
}
