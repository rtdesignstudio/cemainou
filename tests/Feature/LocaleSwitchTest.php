<?php

namespace Tests\Feature;

use App\Http\Middleware\SetLocale;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Limba aleasă înainte de cont: pe pagina de intrare și pe Confidențialitate se schimbă dintr-un clic, iar pagina „se actualizează" o păstrează. */
class LocaleSwitchTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_sign_in_page_switches_language_and_privacy_follows(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Settings::save(['locale' => 'en', 'privacy_public' => true], $admin);

        $this->get('/login')->assertOk()->assertSee(['English', 'Română', route('locale.switch', 'ro')], false);
        $this->from('/login')->get(route('locale.switch', 'ro'))->assertRedirect('/login')->assertCookie(SetLocale::COOKIE, 'ro', false);
        $this->get('/login')->assertOk()->assertSee('Română')->assertSee(route('locale.switch', 'en'), false);

        $this->get(route('privacy'))->assertOk()->assertSee('<html lang="ro">', false)->assertSee(route('locale.switch', 'en'), false);
        $this->from(route('privacy'))->get(route('locale.switch', 'en'))->assertRedirect(route('privacy'));
        $this->get(route('privacy'))->assertSee('<html lang="en">', false);
    }

    public function test_the_maintenance_page_speaks_the_chosen_language(): void
    {
        $this->assertSame('ro', SetLocale::guess(request()->duplicate(cookies: [SetLocale::COOKIE => 'ro'])));
        $request = request()->duplicate();
        $request->headers->set('Accept-Language', 'ro-RO,ro;q=0.9,en;q=0.5');
        $this->assertSame('ro', SetLocale::guess($request));

        $this->app->instance('request', request()->duplicate(cookies: [SetLocale::COOKIE => 'ro']));
        $html = view('errors.503')->render();
        $this->assertStringContainsString('<html lang="ro">', $html);
        $this->assertStringContainsString('Se actualizează', $html);
    }
}
