<?php

namespace Tests\Feature;

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\SystemSettings;
use App\Filament\Widgets\SupportNudge;
use App\Models\User;
use App\Support\License;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * cemainou e gratuit pentru orice echipă: nimic nu se oprește fără cheie, nici actualizările. Cheia de susținător, semnată de RT, spune doar
 * „Susținător". Adminii unei echipe de peste 5 oameni, după 30 de zile, sunt întrebați blând, cel mult o dată pe lună.
 */
class LicenseTest extends TestCase
{
    use RefreshDatabase;

    private string $secret;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $pair = sodium_crypto_sign_keypair();
        $this->secret = sodium_crypto_sign_secretkey($pair);
        config(['cemainou.license_public_key' => base64_encode(sodium_crypto_sign_publickey($pair))]);
        $this->admin = User::factory()->create(['role' => 'admin', 'created_at' => now()->subDays(40)]);
    }

    private function team(int $more): void
    {
        User::factory()->count($more)->create(['role' => 'member']);
    }

    public function test_any_team_is_free_and_updates_never_wait_for_a_key(): void
    {
        $this->team(30);

        $this->assertSame(['free', 31], [License::status()['state'], License::status()['people']]);
        $this->assertFalse(License::blocksUpdates());
        $this->actingAs($this->admin)->get(route('update'))->assertOk()->assertSee('name="archive"', false);
    }

    public function test_a_bigger_team_is_asked_gently_once_a_month_and_only_the_admin(): void
    {
        $this->team(3);
        $this->actingAs($this->admin)->get(Dashboard::getUrl())->assertOk()->assertDontSee('Support cemainou');   // 4 oameni: nimic

        $this->team(3);
        $this->actingAs($this->admin)->get(Dashboard::getUrl())->assertOk()->assertSee(['Your team of 7', 'It is free and stays free', 'Support cemainou']);
        $this->actingAs(User::factory()->create(['role' => 'member']))->get(Dashboard::getUrl())->assertOk()->assertDontSee('It is free and stays free');

        Livewire::actingAs($this->admin)->test(SupportNudge::class)->call('later');
        $this->actingAs($this->admin)->get(Dashboard::getUrl())->assertOk()->assertDontSee('It is free and stays free');
        $this->travel(31)->days();
        $this->actingAs($this->admin)->get(Dashboard::getUrl())->assertOk()->assertSee('It is free and stays free');
    }

    public function test_a_new_installation_is_not_asked_in_its_first_month(): void
    {
        $this->admin->forceFill(['created_at' => now()->subDays(5)])->save();
        $this->team(10);

        $this->actingAs($this->admin)->get(Dashboard::getUrl())->assertOk()->assertDontSee('It is free and stays free');
    }

    public function test_a_supporter_key_for_this_installation_says_thank_you_and_stops_the_question(): void
    {
        $this->team(10);
        $here = License::installation();
        $key = License::issue('L-1', 'Firma Demo SRL', $here, now()->addYear()->toDateString(), $this->secret);

        Livewire::actingAs($this->admin)->test(SystemSettings::class)->assertSee($here)->assertSeeHtml('https://rt.md/cemainou/license/')
            ->set('data.license_key', 'CMN1.stricat.x')->call('save')->assertHasErrors(['data.license_key']);
        $this->assertNull(License::read(License::issue('L-2', 'Altcineva', $here, '2030-01-01', sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()))));   // nesemnată de RT

        Livewire::actingAs($this->admin)->test(SystemSettings::class)->set('data.license_key', $key)->call('save')->assertHasNoErrors()
            ->assertSee('Supporter: Firma Demo SRL');
        $this->assertTrue(License::supporter());
        $this->actingAs($this->admin)->get(Dashboard::getUrl())->assertOk()->assertDontSee('It is free and stays free');

        Settings::save(['license_key' => License::issue('L-3', 'Firma Demo SRL', '7c9e6679-7425-40de-944b-e07fc1f90ae7', now()->addYear()->toDateString(), $this->secret)], $this->admin);
        $this->assertSame('other', License::status()['state']);                     // cheia altei instalări
        Livewire::actingAs($this->admin)->test(SystemSettings::class)->assertSee(['belongs to another installation', $here]);

        Settings::save(['license_key' => License::issue('L-4', 'Firma Demo SRL', $here, now()->subDay()->toDateString(), $this->secret)], $this->admin);
        $this->assertSame('expired', License::status()['state']);
        $this->assertFalse(License::blocksUpdates());
        Livewire::actingAs($this->admin)->test(SystemSettings::class)->assertSee('Your support ended on');
    }
}
