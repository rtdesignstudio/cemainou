<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\AcceptInvitation;
use App\Filament\Pages\Auth\RequestPasswordReset;
use App\Filament\Pages\SystemSettings;
use App\Filament\Resources\Users\InvitationActions;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Mail\Invitation;
use App\Mail\TestMail;
use App\Models\Setting;
use App\Models\User;
use App\Support\Invitations;
use App\Support\Settings;
use App\Support\TeamMail;
use Filament\Actions\Testing\TestAction;
use Filament\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/** Emailul din Setări, invitațiile în echipă (link de 7 zile, o singură dată) și „Ai uitat parola?" cu și fără email. */
class InvitationsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['name' => 'Radu', 'role' => 'admin']);
    }

    private function mailOn(): void
    {
        Settings::save(['mail_mailer' => 'smtp', 'mail_host' => 'smtp.rt.test', 'mail_port' => 587, 'mail_secure' => 'starttls',
            'mail_username' => 'wsa', 'mail_password' => 'parola-smtp', 'mail_from_address' => 'cemainou@rt.test', 'mail_from_name' => 'cemainou'], $this->admin);
    }

    /** Linkul din invitație → [email, token]. */
    private function linkParts(string $url): array
    {
        preg_match('#/invitation/([^/]+)/([^/?]+)#', $url, $m);

        return [urldecode($m[1]), $m[2]];
    }

    public function test_the_smtp_password_is_kept_encrypted_never_shown_and_kept_when_left_empty(): void
    {
        Livewire::actingAs($this->admin)->test(SystemSettings::class)
            ->set('data.mail_mailer', 'smtp')->set('data.mail_host', 'smtp.rt.test')->set('data.mail_from_address', 'cemainou@rt.test')
            ->set('data.mail_password', 'parola-smtp')->call('save')->assertHasNoErrors()
            ->assertSet('data.mail_password', '')->assertDontSee('parola-smtp');

        $this->assertNotSame('parola-smtp', Setting::find('mail_password')->value);                          // criptată în baza de date
        $this->assertSame('parola-smtp', Settings::get('mail_password'));

        Livewire::actingAs($this->admin)->test(SystemSettings::class)->set('data.mail_port', '465')->call('save');
        $this->assertSame('parola-smtp', Settings::get('mail_password'));                                     // câmpul gol n-a șters-o
        $this->assertTrue(TeamMail::ready());
    }

    public function test_the_mail_server_from_settings_is_used_when_sending_and_the_test_email_leaves(): void
    {
        $this->mailOn();
        app('mail.manager');                                                                                  // se aplică abia când e nevoie de email
        $this->assertSame(['smtp', 'smtp.rt.test', 'parola-smtp'], [config('mail.default'), config('mail.mailers.smtp.host'), config('mail.mailers.smtp.password')]);

        Mail::fake();
        Livewire::actingAs($this->admin)->test(SystemSettings::class)->callAction(TestAction::make('testMail')->schemaComponent('mailActions'))
            ->assertNotified();
        Mail::assertSent(TestMail::class, fn ($mail) => $mail->hasTo($this->admin->email));
    }

    public function test_the_test_email_uses_what_is_typed_in_the_form_even_before_saving(): void
    {
        Mail::fake();
        Livewire::actingAs($this->admin)->test(SystemSettings::class)                                         // nimic salvat: SMTP oprit
            ->set('data.mail_mailer', 'smtp')->set('data.mail_host', 'mail.rt.test')->set('data.mail_port', '465')->set('data.mail_secure', 'ssl')
            ->set('data.mail_from_address', 'cemainou@rt.test')
            ->callAction(TestAction::make('testMail')->schemaComponent('mailActions'))
            ->assertNotified('Test email sent to '.$this->admin->email.'.');
        Mail::assertSent(TestMail::class);
        $this->assertSame('off', Settings::get('mail_mailer'));                                                // testul nu salvează
    }

    public function test_an_invitation_by_email_creates_the_account_with_the_team_defaults(): void
    {
        $this->mailOn();
        Settings::save(['locale' => 'ro', 'timezone' => 'Europe/Bucharest'], $this->admin);
        Mail::fake();

        Livewire::actingAs($this->admin)->test(ListUsers::class)
            ->callAction('invite', ['name' => 'Ana Popescu', 'email' => 'Ana@RT.test', 'role' => 'lead'])->assertHasNoActionErrors()->assertNotified('Invitation sent to ana@rt.test.');

        $ana = User::where('email', 'ana@rt.test')->sole();
        $this->assertSame(['lead', 'ro', 'Europe/Bucharest', $this->admin->id], [$ana->role->value, $ana->locale, $ana->timezone, $ana->invited_by]);
        $this->assertSame(64, strlen($ana->invite_token));                                                    // doar hash-ul
        Mail::assertSent(Invitation::class, fn (Invitation $mail) => $mail->hasTo('ana@rt.test') && str_contains($mail->url, '/invitation/'));
        $html = Mail::sent(Invitation::class)->first()->render();
        $this->assertStringContainsString('Radu', $html);
        $this->assertStringNotContainsString('email_verified_at', $html);                                    // numele, nu contul întreg
    }

    public function test_without_email_the_admin_gets_the_link_and_nothing_is_sent(): void
    {
        Mail::fake();

        Livewire::actingAs($this->admin)->test(ListUsers::class)
            ->callAction('invite', ['name' => 'Ion', 'email' => 'ion@rt.test', 'role' => 'member'])->assertNotified('The invitation link for Ion (valid 7 days):');

        Mail::assertNothingSent();
        $this->assertNotNull(User::where('email', 'ion@rt.test')->sole()->invited_at);
    }

    public function test_the_link_lets_the_person_choose_a_password_once_and_sign_in(): void
    {
        $ion = User::factory()->create(['email' => 'ion@rt.test']);
        [$url] = Invitations::send($ion, $this->admin);
        [$email, $token] = $this->linkParts($url);

        $this->get($url)->assertOk()->assertSee('Welcome to cemainou');
        Livewire::test(AcceptInvitation::class, ['email' => $email, 'token' => $token])
            ->fillForm(['password' => 'o-parola-buna-123', 'passwordConfirmation' => 'o-parola-buna-123'])->call('resetPassword')
            ->assertRedirect(filament()->getUrl());

        $this->assertTrue(Auth::user()->is($ion));
        $ion->refresh();
        $this->assertTrue(Hash::check('o-parola-buna-123', $ion->password));
        $this->assertSame([null, true], [$ion->invite_token, $ion->invite_accepted_at !== null]);

        Auth::logout();
        Livewire::test(AcceptInvitation::class, ['email' => $email, 'token' => $token])->assertRedirect(filament()->getLoginUrl());   // a doua oară: nu
    }

    public function test_an_old_or_replaced_link_does_not_work(): void
    {
        $ion = User::factory()->create(['email' => 'ion@rt.test']);
        [$first] = Invitations::send($ion, $this->admin);
        [$second] = Invitations::send($ion, $this->admin);                                      // „Trimite din nou"

        Livewire::test(AcceptInvitation::class, $this->named($first))->assertRedirect(filament()->getLoginUrl());
        Livewire::test(AcceptInvitation::class, $this->named($second))->assertNoRedirect();

        $this->travel(8)->days();
        Livewire::test(AcceptInvitation::class, $this->named($second))->assertRedirect(filament()->getLoginUrl());
    }

    private function named(string $url): array
    {
        [$email, $token] = $this->linkParts($url);

        return ['email' => $email, 'token' => $token];
    }

    public function test_only_admins_invite_and_the_person_page_offers_a_new_link_until_they_join(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'lead']))->get(ListUsers::getUrl())->assertOk()->assertDontSee(__('Invite someone'));   // lista o vede echipa, invitația rămâne a adminului

        $ion = User::factory()->create();
        Invitations::send($ion, $this->admin);
        Livewire::actingAs($this->admin)->test(ViewUser::class, ['record' => $ion->getRouteKey()])->assertActionVisible('resendInvitation');
        Invitations::accept($ion, 'o-parola-buna-123');
        Livewire::actingAs($this->admin)->test(ViewUser::class, ['record' => $ion->getRouteKey()])->assertActionHidden('resendInvitation');
    }

    public function test_the_admin_can_see_the_link_again_while_it_is_valid(): void
    {
        $ion = User::factory()->create(['name' => 'Ion']);
        [$url] = Invitations::send($ion, $this->admin);

        $this->assertNotSame($url, \DB::table('users')->where('id', $ion->id)->value('invite_link'));      // criptat în baza de date
        $this->assertArrayNotHasKey('invite_link', $ion->fresh()->toArray());
        $this->assertSame($url, Invitations::link($ion->fresh()));
        Livewire::actingAs($this->admin)->test(ViewUser::class, ['record' => $ion->getRouteKey()])
            ->mountAction('invitationLink')->assertActionDataSet(['link' => $url])->assertMountedActionModalSee(['It works once, until', 'Copy the link']);
        $this->assertStringContainsString('execCommand', InvitationActions::copyJs($url));   // copierea are rezervă pe telefon
        Livewire::actingAs($this->admin)->test(ListUsers::class)->assertTableActionVisible('invitationLink', $ion);

        $this->travel(Invitations::DAYS + 1)->days();
        $this->assertNull(Invitations::link($ion->fresh()));                                                // expirat: un link nou
        Livewire::actingAs($this->admin)->test(ViewUser::class, ['record' => $ion->getRouteKey()])->mountAction('invitationLink')->assertMountedActionModalSee('expired');

        [$fresh] = Invitations::send($ion->fresh(), $this->admin);
        Invitations::accept($ion->fresh(), 'o-parola-buna-123');
        $this->assertNull($ion->fresh()->invite_link);                                                      // a intrat: linkul se șterge
        Livewire::actingAs($this->admin)->test(ViewUser::class, ['record' => $ion->getRouteKey()])->assertActionHidden('invitationLink');
    }

    public function test_forgot_password_sends_a_link_only_when_email_is_set_up(): void
    {
        Notification::fake();
        $ion = User::factory()->create(['email' => 'ion@rt.test']);

        Livewire::test(RequestPasswordReset::class)->assertSee('ask an admin')->fillForm(['email' => 'ion@rt.test'])->call('request')
            ->assertNotified('Email is not set up yet.');
        Notification::assertNothingSent();

        $this->mailOn();
        Livewire::test(RequestPasswordReset::class)->fillForm(['email' => 'ion@rt.test'])->call('request');
        Notification::assertSentTo($ion, ResetPassword::class);
    }
}
