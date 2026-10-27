<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\EditProfile;
use App\Http\Middleware\EnsureAdminsUseTwoFactor;
use App\Models\User;
use App\Support\Settings;
use Filament\Auth\MultiFactor\MultiFactorChallenge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Livewire\Livewire;
use Tests\TestCase;

/** Autentificarea în doi pași: implementată, dar oprită până o pornește adminul; apoi fiecare o activează din profil, iar adminilor li se poate cere. */
class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    private function middleware(User $user): mixed
    {
        $this->actingAs($user);

        return app(EnsureAdminsUseTwoFactor::class)->handle(Request::create('/'), fn () => 'next');
    }

    public function test_it_is_off_until_an_admin_turns_it_on_then_everyone_finds_it_in_the_profile(): void
    {
        Livewire::actingAs($this->admin)->test(EditProfile::class)->assertDontSee('Two-factor authentication (2FA)');

        Settings::save(['two_factor_enabled' => true], $this->admin);
        Livewire::actingAs($member = User::factory()->create())->test(EditProfile::class)->assertSee(['Two-factor authentication (2FA)', 'Authenticator app']);
        $this->assertFalse(MultiFactorChallenge::make()->hasEnabledProviders($member));
    }

    public function test_the_secret_and_the_recovery_codes_are_kept_encrypted(): void
    {
        $this->admin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $this->admin->saveAppAuthenticationRecoveryCodes(['cod-unu', 'cod-doi']);

        $raw = \DB::table('users')->where('id', $this->admin->id)->first();
        $this->assertStringNotContainsString('JBSWY3DPEHPK3PXP', $raw->app_authentication_secret);
        $this->assertStringNotContainsString('cod-unu', $raw->app_authentication_recovery_codes);
        $this->assertSame('JBSWY3DPEHPK3PXP', $this->admin->fresh()->getAppAuthenticationSecret());
    }

    public function test_when_required_only_admins_without_it_are_sent_to_set_it_up(): void
    {
        $this->assertSame('next', $this->middleware($this->admin));                                         // nu e cerută

        Settings::save(['two_factor_enabled' => true, 'two_factor_admins' => true], $this->admin);
        \Route::get('/mfa-setup', fn () => 'x')->name('filament.admin.auth.multi-factor-authentication.set-up-required');   // la pornire, Filament o înregistrează când e cerută
        app('router')->getRoutes()->refreshNameLookups();
        $this->assertNotSame('next', $this->middleware($this->admin));                                      // adminul fără 2FA: trimis s-o configureze
        $this->assertSame('next', $this->middleware(User::factory()->create(['role' => 'member'])));         // ceilalți, nu

        $this->admin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $this->assertSame('next', $this->middleware($this->admin->fresh()));
    }
}
