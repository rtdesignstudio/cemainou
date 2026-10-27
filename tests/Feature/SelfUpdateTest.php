<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Backups;
use App\Support\ReleaseManifest;
use App\Support\Releases;
use App\Support\SelfUpdate;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

/**
 * Versiunile noi: anunțul din lista de pe rt.md și arhiva încărcată de admin, care se instalează doar cu semnătura RT și cu fiecare fișier neschimbat,
 * după un backup, fără să atingă data/, .env sau ce a pus hostingul. Aplicația „de aici" e un folder temporar (cemainou.self_update_root).
 */
class SelfUpdateTest extends TestCase
{
    use RefreshDatabase;

    private string $secret;

    private string $tmp;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $pair = sodium_crypto_sign_keypair();
        $this->secret = sodium_crypto_sign_secretkey($pair);
        $this->tmp = sys_get_temp_dir().'/wsa-self-update-'.uniqid();
        $this->root = "{$this->tmp}/app";
        config(['cemainou.release_key' => base64_encode(sodium_crypto_sign_publickey($pair)), 'cemainou.self_update_root' => $this->root,
            'cemainou.release_feed' => 'https://rt.md/cemainou/releases.json']);

        foreach (['artisan' => '<?php', 'vendor/autoload.php' => '<?php // vechi', 'public/index.php' => 'vechi', 'app/Old.php' => '<?php', '.env' => 'APP_KEY=secret',
            'config/cemainou.php' => "<?php return ['version' => '0.2.0'];", 'data/database.sqlite' => 'DATELE ECHIPEI', 'data/files/1/poza.png' => 'poza',
            'public/.well-known/acme-challenge/token' => 'acme', 'data/storage/framework/.gitignore' => ''] as $path => $content) {
            File::ensureDirectoryExists(dirname("{$this->root}/{$path}"));
            File::put("{$this->root}/{$path}", $content);
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    /** O arhivă de versiune, semnată ca de scripts/release.sh; $change o strică după semnare. */
    private function release(string $version, ?callable $change = null, ?string $secret = null): string
    {
        $dir = "{$this->tmp}/build-{$version}-".uniqid();
        foreach (['artisan' => '<?php', 'vendor/autoload.php' => '<?php // nou', 'public/index.php' => 'nou', 'app/New.php' => '<?php',
            'config/cemainou.php' => "<?php return ['version' => '{$version}'];", 'data/.htaccess' => 'Require all denied', '.env.example' => 'APP_KEY='] as $path => $content) {
            File::ensureDirectoryExists(dirname("{$dir}/{$path}"));
            File::put("{$dir}/{$path}", $content);
        }
        ReleaseManifest::build($dir, $version, $secret ?? $this->secret);
        if ($change) {
            $change($dir);
        }

        $zip = "{$dir}.zip";
        $archive = new ZipArchive;
        $archive->open($zip, ZipArchive::CREATE);
        foreach (File::allFiles($dir, true) as $file) {
            $archive->addFile($file->getPathname(), $file->getRelativePathname());
        }
        $archive->close();

        return $zip;
    }

    private function assertUntouched(): void
    {
        $this->assertFileExists("{$this->root}/app/Old.php");
        $this->assertFileDoesNotExist("{$this->root}/app/New.php");
        $this->assertSame('vechi', File::get("{$this->root}/public/index.php"));
        $this->assertSame('DATELE ECHIPEI', File::get("{$this->root}/data/database.sqlite"));
        $this->assertSame([], glob("{$this->root}/data/updates/stage-*") ?: []);
    }

    public function test_a_signed_newer_archive_replaces_the_code_and_keeps_the_data(): void
    {
        $this->assertSame('9.9.9', SelfUpdate::here()->install($this->release('9.9.9')));

        $this->assertFileExists("{$this->root}/app/New.php");
        $this->assertFileDoesNotExist("{$this->root}/app/Old.php");
        $this->assertSame('nou', File::get("{$this->root}/public/index.php"));
        $this->assertSame('DATELE ECHIPEI', File::get("{$this->root}/data/database.sqlite"));          // datele, neatinse
        $this->assertSame('poza', File::get("{$this->root}/data/files/1/poza.png"));
        $this->assertSame('APP_KEY=secret', File::get("{$this->root}/.env"));
        $this->assertSame('acme', File::get("{$this->root}/public/.well-known/acme-challenge/token"));   // ce pusese hostingul
        $this->assertFileExists("{$this->root}/data/updates/previous-0.2.0/app/Old.php");               // codul vechi, pentru o revenire de mână
        $this->assertSame([], glob("{$this->root}/data/updates/stage-*") ?: []);
    }

    public function test_an_archive_changed_after_signing_or_signed_by_someone_else_changes_nothing(): void
    {
        $refused = [
            'changed file' => $this->release('9.9.9', fn (string $dir) => File::put("{$dir}/app/New.php", '<?php system($_GET["x"]);')),
            'extra file' => $this->release('9.9.9', fn (string $dir) => File::put("{$dir}/public/shell.php", '<?php')),
            'missing file' => $this->release('9.9.9', fn (string $dir) => File::delete("{$dir}/app/New.php")),
            'other key' => $this->release('9.9.9', secret: sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())),
            'no signature' => $this->release('9.9.9', fn (string $dir) => File::delete("{$dir}/release.sig")),
        ];
        foreach ($refused as $case => $zip) {
            try {
                SelfUpdate::here()->install($zip);
                $this->fail("{$case}: installed");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('nothing was changed', $e->getMessage(), $case);
            }
            $this->assertUntouched();
        }
    }

    public function test_an_older_version_or_an_unsafe_path_is_refused(): void
    {
        $unsafe = "{$this->tmp}/unsafe.zip";
        $archive = new ZipArchive;
        $archive->open($unsafe, ZipArchive::CREATE);
        $archive->addFromString('../evil.php', '<?php');
        $archive->close();

        foreach ([$this->release('0.1.9'), $this->release('0.2.0'), $unsafe] as $zip) {
            try {
                SelfUpdate::here()->install($zip);
                $this->fail('installed');
            } catch (RuntimeException) {
            }
            $this->assertUntouched();
        }
        $this->assertFileDoesNotExist("{$this->tmp}/evil.php");
    }

    public function test_admins_see_the_announcement_and_upload_the_archive(): void
    {
        Http::fake(['rt.md/cemainou/releases.json' => Http::response(['releases' => [
            ['version' => '9.9.9', 'date' => '2026-12-01', 'url' => 'https://rt.md/cemainou/releases/cemainou-9.9.9.zip', 'size' => 1000, 'notes' => '### Added'."\n".'- Ceva **nou**'],
            ['version' => '0.1.0', 'url' => 'https://rt.md/cemainou/releases/cemainou-0.1.0.zip'],
            ['version' => '99.0.0', 'url' => 'http://altundeva.test/x.zip'],                                    // fără https: ignorată
        ]])]);
        $admin = User::factory()->create(['role' => 'admin']);
        $member = User::factory()->create();

        $this->assertSame('9.9.9', Releases::refresh()['version']);
        $this->actingAs($admin)->get('/')->assertSee('cemainou 9.9.9 is available (you have '.config('cemainou.version').').');
        $this->actingAs($member)->get('/')->assertDontSee('9.9.9');
        $this->actingAs($admin)->get('/update')->assertOk()->assertSee(['Download cemainou 9.9.9', 'Upload and install', 'Ceva <strong>nou</strong>'], false);

        $upload = fn () => ['archive' => new UploadedFile($this->release('9.9.9'), 'cemainou-9.9.9.zip', 'application/zip', null, true)];
        $this->actingAs($member)->post('/update/upload', $upload())->assertForbidden();
        $this->actingAs($admin)->post('/update/upload', $upload())->assertRedirect(route('update', ['continue' => 1]));
        $this->assertFileExists("{$this->root}/app/New.php");
        $this->assertCount(1, Backups::all());                                                          // backup-ul, înainte de orice
    }

    public function test_a_refused_upload_says_why_and_the_check_can_be_turned_off(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $zip = $this->release('9.9.9', fn (string $dir) => File::put("{$dir}/app/New.php", 'altceva'));
        $this->actingAs($admin)->post('/update/upload', ['archive' => new UploadedFile($zip, 'cemainou-9.9.9.zip', 'application/zip', null, true)])
            ->assertOk()->assertSee('The archive was changed after it was signed; nothing was changed.');
        $this->assertUntouched();

        Http::fake();
        Settings::save(['update_check' => false], $admin);
        $this->assertNull(Releases::refresh());
        Http::assertNothingSent();
    }
}
