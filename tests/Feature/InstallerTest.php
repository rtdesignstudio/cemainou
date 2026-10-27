<?php

namespace Tests\Feature;

use App\Models\ProjectTemplate;
use App\Models\User;
use App\Support\Installer;
use App\Support\Settings;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Instalarea pe un server nou: totul trimite la /install până există un admin; o singură dată; .env și semnul de instalare scrise. */
class InstallerTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/wsa-install-'.uniqid();
        File::ensureDirectoryExists($this->dir);
        File::put("{$this->dir}/.env", "APP_KEY=base64:abc\nAPP_URL=\n");
        config(['cemainou.installer_test' => true, 'cemainou.installed_lock' => "{$this->dir}/installed", 'cemainou.installer_env' => "{$this->dir}/.env"]);
        Installer::forget();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        Installer::forget();
        parent::tearDown();
    }

    /** O aplicație nouă pe alt folder, fără să înlocuiască aplicația testului (instanța globală). */
    private function freshApp(string $base): Application
    {
        $app = new Application($base);
        Container::setInstance($this->app);

        return $app;
    }

    private function form(array $extra = []): array
    {
        return $extra + ['url' => 'https://cemainou.rt.test', 'locale' => 'ro', 'timezone' => 'Europe/Chisinau', 'name' => 'Radu', 'email' => 'Radu@RT.test',
            'password' => 'o-parola-lunga-1', 'password_confirmation' => 'o-parola-lunga-1', 'database' => 'sqlite', 'feedback_to_rt' => '1'];
    }

    public function test_before_the_installation_every_page_leads_to_install_and_agents_get_503(): void
    {
        $this->get('/login')->assertRedirect('/install');
        $this->get('/')->assertRedirect('/install');
        $this->postJson('/mcp', [])->assertStatus(503);
        $this->get('/install')->assertOk()->assertSee(['Install', 'PHP 8.4+', 'The first admin']);
        $this->get('/install?lang=ro')->assertOk()->assertSee('Primul admin');
        $this->assertDoesNotMatchRegularExpression('/name="feedback_to_rt" value="1"\s+checked/', $this->get('/install')->getContent());   // feedback-ul spre RT: nebifat implicit, omul alege
    }

    public function test_the_installation_creates_the_admin_saves_the_team_defaults_and_writes_env(): void
    {
        $this->post('/install', $this->form())->assertRedirect('/');

        $admin = User::sole();
        $this->assertSame(['radu@rt.test', 'admin', 'ro'], [$admin->email, $admin->role->value, $admin->locale]);
        $this->assertTrue(Hash::check('o-parola-lunga-1', $admin->password));
        $this->assertTrue(Auth::user()->is($admin));                                                             // intră direct
        $this->assertSame(['ro', 'Europe/Chisinau'], [Settings::get('locale'), Settings::get('timezone')]);
        $this->assertStringContainsString('APP_URL=https://cemainou.rt.test', File::get("{$this->dir}/.env"));
        $this->assertStringContainsString('APP_KEY=base64:abc', File::get("{$this->dir}/.env"));               // restul rămâne
        $this->assertFileExists("{$this->dir}/installed");
        $this->assertTrue(Settings::get('feedback_to_rt'));                                                     // bifat de om în formular (implicit e nebifat)
        $this->assertContains('Lună contabilă', ProjectTemplate::pluck('name')->all());                         // șabloanele de pornire, în limba aleasă

        $this->get('/install')->assertNotFound();                                                                // o singură dată
        $this->post('/install', $this->form(['email' => 'altul@rt.test']))->assertNotFound();
        $this->assertSame(1, User::count());
    }

    public function test_bad_input_and_an_unreachable_mysql_change_nothing(): void
    {
        $this->post('/install', $this->form(['password' => 'scurta', 'password_confirmation' => 'scurta']))->assertSessionHasErrors('password');
        $this->post('/install', $this->form(['database' => 'mysql', 'db_host' => '127.0.0.1', 'db_port' => 1, 'db_name' => 'x', 'db_user' => 'x']))
            ->assertSessionHasErrors('install');

        $this->assertSame(0, User::count());
        $this->assertFileDoesNotExist("{$this->dir}/installed");
    }

    public function test_an_app_installed_before_the_installer_is_recognised_by_its_admin(): void
    {
        User::factory()->create(['role' => 'admin']);

        $this->get('/install')->assertNotFound();
        $this->assertFileExists("{$this->dir}/installed");
    }

    public function test_the_update_command_migrates_and_clears_the_caches(): void
    {
        $this->artisan('cemainou:update', ['--no-backup' => true])->assertSuccessful()->expectsOutputToContain('update applied');
    }

    public function test_on_a_fresh_server_the_data_folder_gets_the_env_and_the_storage(): void
    {
        $base = "{$this->dir}/server";
        File::ensureDirectoryExists("{$base}/data");
        $app = $this->freshApp($base);

        Installer::paths($app);

        $env = File::get("{$base}/data/.env");
        $this->assertMatchesRegularExpression('/^APP_KEY=base64:.{44}$/m', $env);                                // o cheie nouă, a serverului
        $this->assertStringContainsString('APP_ENV=production', $env);
        $this->assertSame('0600', substr(sprintf('%o', fileperms("{$base}/data/.env")), -4));
        $this->assertSame([$base.'/data', $base.'/data/storage'], [$app->environmentPath(), $app->storagePath()]);
        foreach (['storage/framework/sessions', 'storage/logs', 'files', 'backups', 'database.sqlite'] as $path) {
            $this->assertFileExists("{$base}/data/{$path}");
        }

        $before = File::get("{$base}/data/.env");
        Installer::paths($this->freshApp($base));                                                                // a doua pornire: nu se rescrie
        $this->assertSame($before, File::get("{$base}/data/.env"));
    }

    public function test_with_an_env_in_the_code_folder_nothing_moves(): void                            // dezvoltarea locală
    {
        $base = "{$this->dir}/local";
        File::ensureDirectoryExists("{$base}/data");
        File::put("{$base}/.env", 'APP_KEY=x');
        $app = $this->freshApp($base);

        Installer::paths($app);

        $this->assertFileDoesNotExist("{$base}/data/.env");
        $this->assertSame($base, $app->environmentPath());
    }
}
