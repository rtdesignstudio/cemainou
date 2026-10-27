<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Support\Backups;
use App\Support\Updater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Actualizarea fără shell: după ce s-a urcat codul nou, adminul face backup și actualizează baza din browser; până atunci ceilalți așteaptă. */
class UpdaterTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $member;

    private string $migrations;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cemainou.updater_test' => true]);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->member = User::factory()->create();
        $this->migrations = sys_get_temp_dir().'/cemainou-migrations-'.uniqid();
        File::ensureDirectoryExists($this->migrations);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->migrations);
        parent::tearDown();
    }

    /** O versiune nouă de cod, cu o migrare încă nerulată. */
    private function uploadNewCode(): void
    {
        File::put($this->migrations.'/2099_01_01_000000_create_update_probe.php', <<<'MIGRATION'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;
            return new class extends Migration {
                public function up(): void { Schema::create('update_probe', fn (Blueprint $table) => $table->id()); }
                public function down(): void { Schema::dropIfExists('update_probe'); }
            };
            MIGRATION);
        app('migrator')->path($this->migrations);
    }

    /** O cerere de agent (cheia lui, fără sesiune); după ea, testul revine la oamenii de pe web. */
    private function agent()
    {
        $this->app['auth']->forgetGuards();
        $response = $this->withToken($this->member->createToken('laptop')->plainTextToken)->withHeaders(['Accept' => 'application/json, text/event-stream'])
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => (object) []]);
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');

        return $response;
    }

    public function test_a_pending_migration_sends_the_admin_to_the_update_and_makes_the_others_wait(): void
    {
        Updater::remember();
        $this->uploadNewCode();

        $this->actingAs($this->member)->get('/')->assertStatus(503)->assertSee('Being updated');
        $this->agent()->assertStatus(503)->assertJsonPath('message', 'cemainou is being updated. Try again in a few minutes.');
        $this->actingAs($this->admin)->get('/')->assertRedirect(route('update'));
        $this->actingAs($this->admin)->get('/update')->assertOk()->assertSee(['New version', 'Back up and update', 'The database gets 1 change.']);

        $this->actingAs($this->admin)->post('/update')->assertOk()->assertSee(['Updated', 'cemainou-']);
        $this->assertTrue(Schema::hasTable('update_probe'));
        $this->assertCount(1, Backups::all());
        $this->actingAs($this->member)->get('/')->assertOk();
        $this->agent()->assertOk();
    }

    public function test_a_new_version_without_migrations_only_asks_the_admin(): void
    {
        Setting::create(['key' => Updater::VERSION_KEY, 'value' => '0.0.9']);

        $this->actingAs($this->member)->get('/')->assertOk();
        $this->agent()->assertOk();
        $this->actingAs($this->admin)->get('/update')->assertOk()->assertSee(['0.0.9 → '.config('cemainou.version'), 'The database needs no change.']);

        $this->actingAs($this->admin)->post('/update')->assertOk()->assertSee('Updated');
        $this->assertSame(config('cemainou.version'), Setting::find(Updater::VERSION_KEY)->value);
        $this->actingAs($this->admin)->get('/')->assertOk();
    }

    public function test_when_the_backup_fails_nothing_changes(): void
    {
        Updater::remember();
        $this->uploadNewCode();
        File::put($this->migrations.'/not-a-folder', '');
        config(['cemainou.backup_path' => $this->migrations.'/not-a-folder/backups']);

        $this->actingAs($this->admin)->post('/update')->assertOk()->assertSee(['The update stopped', 'Nothing was changed.']);
        $this->assertFalse(Schema::hasTable('update_probe'));
        $this->actingAs($this->member)->get('/')->assertStatus(503);
    }

    public function test_an_install_that_is_up_to_date_is_left_alone(): void
    {
        $this->actingAs($this->member)->get('/')->assertOk();
        $this->assertSame(config('cemainou.version'), Setting::find(Updater::VERSION_KEY)->value);   // notată acum, fără nicio întrebare
        $this->actingAs($this->admin)->get('/update')->assertOk()->assertSee(['Up to date', 'Upload and install']);   // fără baza în urmă: locul unde se încarcă o versiune nouă
        $this->actingAs($this->member)->get('/update')->assertForbidden();
    }

    public function test_the_notes_are_the_changelog_sections_between_the_two_versions(): void
    {
        $this->assertStringStartsWith('## 0.1.0 - ', Updater::notes('0.0.1', '0.1.0'));
        $this->assertSame('', Updater::notes('0.1.0', '0.1.0'));
    }
}
