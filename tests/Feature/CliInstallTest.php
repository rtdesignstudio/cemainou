<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Installer;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Instalarea din terminal: aceleași date și aceeași logică ca /install; parolele nu vin din argumente. */
class CliInstallTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/wsa-cli-install-'.uniqid();
        File::ensureDirectoryExists($this->dir);
        File::put("{$this->dir}/.env", "APP_KEY=base64:abc\nAPP_URL=\n");
        config(['cemainou.installer_test' => true, 'cemainou.installed_lock' => "{$this->dir}/installed", 'cemainou.installer_env' => "{$this->dir}/.env"]);
        Installer::forget();
    }

    protected function tearDown(): void
    {
        putenv('CEMAINOU_ADMIN_PASSWORD');
        File::deleteDirectory($this->dir);
        Installer::forget();
        parent::tearDown();
    }

    public function test_a_non_interactive_install_with_the_password_from_the_environment(): void
    {
        putenv('CEMAINOU_ADMIN_PASSWORD=o-parola-lunga-1');

        $this->artisan('cemainou:install', ['--url' => 'https://cemainou.rt.test', '--email' => 'Radu@RT.test', '--name' => 'Radu', '--locale' => 'en', '--no-interaction' => true])
            ->expectsOutputToContain('cemainou e instalat')
            ->assertSuccessful();

        $admin = User::sole();
        $this->assertSame(['radu@rt.test', 'admin', 'en'], [$admin->email, $admin->role->value, $admin->locale]);
        $this->assertTrue(Hash::check('o-parola-lunga-1', $admin->password));
        $this->assertSame('en', Settings::get('locale'));
        $this->assertFalse(Settings::get('feedback_to_rt'));
        $this->assertStringContainsString('APP_URL=https://cemainou.rt.test', File::get("{$this->dir}/.env"));
        $this->assertFileExists("{$this->dir}/installed");
    }

    public function test_a_generated_password_is_shown_once_and_works(): void
    {
        $this->artisan('cemainou:install', ['--url' => 'https://cemainou.rt.test', '--email' => 'a@rt.test', '--name' => 'Ana', '--generate-password' => true, '--feedback-to-rt' => true])
            ->expectsOutputToContain('Parola adminului (afișată doar acum')
            ->assertSuccessful();

        $this->assertTrue(Settings::get('feedback_to_rt'));
        $this->assertNotEmpty(User::sole()->password);
    }

    public function test_it_asks_for_what_is_missing_and_checks_the_password_twice(): void
    {
        $this->artisan('cemainou:install', ['--url' => 'https://cemainou.rt.test'])
            ->expectsQuestion('Emailul primului admin', 'a@rt.test')
            ->expectsQuestion('Numele primului admin', 'Ana')
            ->expectsQuestion('Parola adminului (cel puțin 12 caractere)', 'o-parola-lunga-1')
            ->expectsQuestion('Încă o dată parola', 'alta-parola-lunga')
            ->expectsOutput('Parolele nu se potrivesc.')
            ->assertFailed();

        $this->assertSame(0, User::count());
        $this->assertFileDoesNotExist("{$this->dir}/installed");
    }

    public function test_bad_data_stops_before_anything_is_written(): void
    {
        putenv('CEMAINOU_ADMIN_PASSWORD=scurta');

        $this->artisan('cemainou:install', ['--url' => 'nu-e-adresa', '--email' => 'a@rt.test', '--name' => 'Ana', '--no-interaction' => true])->assertFailed();

        $this->assertSame(0, User::count());
        $this->assertStringContainsString('APP_URL=', File::get("{$this->dir}/.env"));
        $this->assertStringNotContainsString('nu-e-adresa', File::get("{$this->dir}/.env"));
    }

    public function test_an_installed_app_refuses_a_second_install(): void
    {
        File::put("{$this->dir}/installed", 'x');

        $this->artisan('cemainou:install', ['--no-interaction' => true])
            ->expectsOutputToContain('deja instalat')
            ->assertFailed();
    }
}
