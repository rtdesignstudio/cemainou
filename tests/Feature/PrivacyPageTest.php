<?php

namespace Tests\Feature;

use App\Filament\Pages\SystemSettings;
use App\Models\User;
use App\Support\Privacy;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Pagina Confidențialitate: din Setări (firma, emailul, zilele), publică sau doar pentru cei logați și pentru linkul semnat din invitații. */
class PrivacyPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_page_is_filled_from_the_settings_and_can_be_only_for_people_signed_in(): void
    {
        $radu = User::factory()->create(['role' => 'admin', 'locale' => 'ro']);
        Settings::save(['legal_name' => 'RT Design Studio SRL', 'legal_address' => 'Str. Cuza-Vodă 45/15, Chișinău', 'privacy_email' => 'mail@rt.test', 'keep_raw_days' => 90], $radu);

        $this->get('/privacy')->assertOk()                                                      // publică: în limba echipei
            ->assertSee(['RT Design Studio SRL', 'Str. Cuza-Vodă 45/15, Chișinău', 'mail@rt.test', '90 days', 'does not send the text of your conversations'], false);
        $this->get('/login')->assertSee('/privacy');

        Settings::save(['privacy_public' => false], $radu);
        app()->forgetInstance(Settings::class);
        $this->get('/privacy')->assertRedirect('/login');                                      // doar pentru cei logați
        $this->get('/login')->assertDontSee('/privacy"', false);
        $this->get(Privacy::shareableUrl())->assertOk()->assertSee('RT Design Studio SRL');   // linkul semnat din invitație
        $this->actingAs($radu)->get('/privacy')->assertOk()->assertSee(['Confidențialitate', 'Nu trimite textul conversațiilor']);   // în limba omului

        Livewire::actingAs($radu)->test(SystemSettings::class)->assertSee('Denumirea juridică');
    }
}
