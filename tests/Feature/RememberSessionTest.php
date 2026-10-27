<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/** „Ține-mă minte": după ce sesiunea expiră (aplicația de pe telefon, redeschisă după ore), cookie-ul de revenire te ține în cont. */
class RememberSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_expired_session_comes_back_from_the_remember_cookie(): void
    {
        $user = User::factory()->create(['remember_token' => null]);
        $guard = Auth::guard('web');
        $user->setRememberToken($token = 'tok-'.str_repeat('x', 56));
        $user->save();
        $recaller = $user->id.'|'.$token.'|'.$user->getAuthPassword();

        $this->flushSession();
        $this->withCookie($guard->getRecallerName(), $recaller)->get('/')->assertOk();
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_session_lasts_a_month_so_a_logout_on_another_device_does_not_end_the_phone_one(): void
    {
        $this->assertStringContainsString("env('SESSION_LIFETIME', 43200)", file_get_contents(config_path('session.php')));   // implicit, pe orice instalare
        $this->assertStringContainsString('SESSION_LIFETIME=43200', file_get_contents(base_path('.env.example')));
    }
}
