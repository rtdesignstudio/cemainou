<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\Login;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Login-ul de pe telefon: tastatura poate scrie emailul cu majusculă; parola rămâne exact cum e tastată. */
class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_email_is_kept_and_compared_in_lower_case_and_the_password_exactly(): void
    {
        $user = User::factory()->create(['email' => ' Ion.Rusu@Example.TEST ', 'password' => 'Parola-Cu-MAJUSCULE-1']);
        $this->assertSame('ion.rusu@example.test', $user->fresh()->email);

        Livewire::test(Login::class)->fillForm(['email' => 'parola@example.test', 'password' => 'x'])->call('authenticate')->assertHasFormErrors(['email']);
        Livewire::test(Login::class)->fillForm(['email' => 'Ion.Rusu@example.test', 'password' => 'parola-cu-majuscule-1'])->call('authenticate')->assertHasFormErrors(['email']);
        $this->assertGuest();

        Livewire::test(Login::class)->fillForm(['email' => 'Ion.Rusu@example.test', 'password' => 'Parola-Cu-MAJUSCULE-1'])->call('authenticate')->assertHasNoFormErrors();
        $this->assertAuthenticatedAs($user);
    }

    public function test_remember_me_is_on_by_default_so_the_phone_app_keeps_the_session(): void
    {
        $user = User::factory()->create(['email' => 'nutza@example.test', 'password' => 'o-parola-lunga-1', 'remember_token' => null]);

        Livewire::test(Login::class)->assertSet('data.remember', true)
            ->fillForm(['email' => 'nutza@example.test', 'password' => 'o-parola-lunga-1'])->call('authenticate')->assertHasNoFormErrors();

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->remember_token);                    // cookie-ul de revenire: sesiunea expirată se reface singură
    }

    public function test_the_phone_keyboard_does_not_correct_or_capitalize_the_fields(): void
    {
        $this->get('/login')->assertOk()->assertSee(['autocapitalize="none"', 'autocorrect="off"', 'type="password"'], false);
    }
}
