<?php

namespace Tests\Feature;

use App\Filament\Pages\Downloads;
use App\Models\IssuedLicense;
use App\Models\KnownInstallation;
use App\Models\Setting;
use App\Models\User;
use App\Support\FeedbackUpstream;
use App\Support\KeepInTouch;
use App\Support\License;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * RT ca casă-mamă: pagina „cemainou" și „Ține-mă la curent" doar unde sunt pornite din .env (implicit oprite, și în arhiva publică);
 * o instalare nu trimite nimic decât dacă adminul ei pornește „Ține-mă la curent".
 */
class CemainouHomeTest extends TestCase
{
    use RefreshDatabase;

    public function test_off_by_default_nothing_shows_and_the_endpoints_do_not_exist(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        config(['cemainou.access_requests' => null, 'cemainou.feedback_inbox' => false]);
        $this->actingAs($admin)->get('/cemainou')->assertForbidden();
        $this->postJson('/api/installations/hello', ['installation' => (string) Str::uuid(), 'email' => 'a@b.md'])->assertNotFound();
    }

    public function test_an_installation_sends_nothing_until_its_admin_turns_on_keep_me_posted(): void
    {
        Http::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $before = KeepInTouch::state();
        Settings::save(['update_check' => true], $admin);
        $this->assertNull(KeepInTouch::sync($before));
        Http::assertNothingSent();

        $before = KeepInTouch::state();
        Settings::save(['keep_in_touch' => true, 'keep_in_touch_email' => 'it@firma.test', 'keep_in_touch_company' => 'Firma SRL'], $admin);
        KeepInTouch::sync($before);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/installations/hello') && $r['email'] === 'it@firma.test' && $r['installation'] === FeedbackUpstream::installation()
            && ! isset($r['people']) && ! isset($r['domain']));

        $before = KeepInTouch::state();
        Settings::save(['keep_in_touch' => false], $admin);
        KeepInTouch::sync($before);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/installations/bye'));
    }

    public function test_rt_keeps_introductions_licenses_and_the_estimate(): void
    {
        config(['cemainou.feedback_inbox' => true, 'cemainou.access_requests' => 'cemainou-cereri-de-acces']);
        $pair = sodium_crypto_sign_keypair();
        config(['cemainou.license_public_key' => base64_encode(sodium_crypto_sign_publickey($pair))]);
        $id = (string) Str::uuid();

        $this->postJson('/api/installations/hello', ['installation' => $id, 'email' => 'it@firma.test', 'company' => 'Firma SRL', 'version' => '0.1.300'])->assertOk();
        $this->assertSame('Firma SRL', KnownInstallation::sole()->company);
        $this->postJson('/api/installations/bye', ['installation' => $id])->assertOk();
        $this->assertNotNull(KnownInstallation::sole()->left_at);

        $admin = User::factory()->create(['role' => 'admin']);
        $key = License::issue('RT-0009', 'Firma SRL', $id, '2027-10-21', sodium_crypto_sign_secretkey($pair));
        Livewire::actingAs($admin)->test(Downloads::class)->set('tab', 'licenses')->set('licenseKey', 'CMN1.stricat.x')->call('addLicense')->assertHasErrors('licenseKey')
            ->set('licenseKey', $key)->set('licenseNote', 'factura 12')->call('addLicense')->assertHasNoErrors()->assertSee(['Firma SRL', 'RT-0009', 'factura 12']);
        $this->assertSame(hash('sha256', $key), IssuedLicense::sole()->key_hash);                          // cheia nu se păstrează

        Setting::create(['key' => 'install_estimate', 'value' => ['max' => 12, 'typical' => 9, 'days' => 30]]);
        Livewire::actingAs($admin)->test(Downloads::class)->set('tab', 'installations')->assertSee(['About 12 check for new versions', 'Firma SRL', 'stopped']);
    }

    public function test_the_estimate_counts_distinct_addresses_per_day_from_the_logs_and_keeps_no_address(): void
    {
        $dir = sys_get_temp_dir().'/cmn-logs-'.uniqid();
        mkdir($dir);
        $day = now()->subDay()->format('d/M/Y');
        $line = fn (string $ip, string $agent) => $ip.' - - ['.$day.':10:00:00 +0300] "GET /cemainou/releases.json HTTP/1.1" 200 512 "-" "'.$agent.'"';
        file_put_contents($dir.'/access.log', implode("\n", [$line('1.1.1.1', 'cemainou/0.1.300'), $line('1.1.1.1', 'cemainou/0.1.300'), $line('2.2.2.2', 'whatsupai/0.1.200'), $line('3.3.3.3', 'Mozilla/5.0')])."\n");
        exec('cd '.escapeshellarg($dir).' && tar czf Oct-2026.demo.tar.gz access.log');
        config(['cemainou.install_logs' => $dir]);

        $this->artisan('cemainou:install-estimate')->assertSuccessful();
        $estimate = Setting::where('key', 'install_estimate')->value('value');
        $this->assertSame(2, $estimate['max']);                                                              // două instalări; browserul nu se numără
        $this->assertStringNotContainsString('1.1.1.1', json_encode($estimate));
        exec('rm -rf '.escapeshellarg($dir));
    }

    public function test_an_issued_key_sent_by_the_script_is_kept_only_at_rt(): void
    {
        $pair = sodium_crypto_sign_keypair();
        config(['cemainou.license_public_key' => base64_encode(sodium_crypto_sign_publickey($pair)), 'cemainou.feedback_inbox' => false, 'cemainou.access_requests' => null]);
        $this->artisan('cemainou:license-keep')->expectsOutputToContain('Not the RT instance')->assertFailed();   // altă instalare: refuză înainte să citească cheia

        $key = License::issue('L-1', 'Firma SRL', null, now()->addYear()->toDateString(), sodium_crypto_sign_secretkey($pair));
        $this->assertSame('Firma SRL', IssuedLicense::keep($key, 'factura 7')->licensee);                    // ce face comanda la RT
        IssuedLicense::keep($key, 'factura 7');                                                               // a doua oară: aceeași, nu dublată
        $this->assertSame(1, IssuedLicense::count());
        $this->assertNull(IssuedLicense::keep('CMN1.stricat.x'));
    }
}
