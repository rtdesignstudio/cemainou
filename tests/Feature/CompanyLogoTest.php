<?php

namespace Tests\Feature;

use App\Filament\Pages\SystemSettings;
use App\Models\User;
use App\Support\Branding;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** Setări → Companie: adminul încarcă logo-ul (SVG / PNG); pe Azi apare în locul butonului „Sarcină nouă". */
class CompanyLogoTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_admin_uploads_the_logo_and_today_shows_it_instead_of_new_task(): void
    {
        Storage::fake('data');
        $admin = User::factory()->create(['role' => 'admin']);
        $buttons = fn () => substr_count($this->actingAs($admin)->get('/')->getContent(), 'New task');   // apare și în primii pași (New tasks, comments)
        $before = $buttons();

        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10"/></svg>');
        Livewire::actingAs($admin)->test(SystemSettings::class)->set('data.company_name', 'RT Design Studio')->set('data.company_logo', [$svg])->call('save')->assertHasNoErrors();

        $path = Settings::get('company_logo');
        $this->assertStringStartsWith('branding/', $path);
        Storage::disk('data')->assertExists($path);
        $this->actingAs($admin)->get('/')->assertSee(Branding::logoUrl(), false)->assertSee('alt="RT Design Studio"', false);
        $this->assertSame($before - 1, $buttons());
        auth()->logout();
        $this->get('/login')->assertOk()->assertSee(['wsa-company-logo is-auth', 'wsa-auth-brand'], false);   // și la intrare: logo-ul sus, cemainou mic jos
        $this->actingAs($admin);
        $this->get(route('brand.logo'))->assertOk()->assertHeader('Content-Type', 'image/svg+xml')->assertHeader('Content-Security-Policy');

        Livewire::actingAs($admin)->test(SystemSettings::class)->set('data.company_logo', [])->call('save')->assertHasNoErrors();   // scos: înapoi la buton
        Storage::disk('data')->assertMissing($path);
        $this->assertSame($before, $buttons());
        $this->get(route('brand.logo'))->assertNotFound();
    }

    public function test_only_svg_or_png_and_only_admins(): void
    {
        Storage::fake('data');
        Livewire::actingAs(User::factory()->create(['role' => 'admin']))->test(SystemSettings::class)
            ->set('data.company_logo', [UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf')])->call('save')->assertHasErrors('data.company_logo');
        $this->assertNull(Settings::get('company_logo'));
        $this->assertSame([], Storage::disk('data')->allFiles());
        $this->actingAs(User::factory()->create(['role' => 'lead']))->get(SystemSettings::getUrl())->assertForbidden();
    }
}
