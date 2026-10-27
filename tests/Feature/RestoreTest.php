<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Setting;
use App\Models\Task;
use App\Models\User;
use App\Support\Backups;
use App\Support\Restore;
use App\Support\Updater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

/** Restaurarea: datele din backup înlocuiesc baza de acum (din SQLite sau din JSON-ul MySQL, pe orice motor), cu fișierele, pe aceeași versiune. */
class RestoreTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/wsa-restore-'.uniqid();
        File::ensureDirectoryExists($this->dir);
        config(['cemainou.backup_path' => $this->dir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    /** O arhivă cu tabelele în JSON, rând pe linie, ca backup-ul de pe MySQL. */
    private function jsonBackup(array $tables, array $extra = [], ?string $version = null): string
    {
        $path = $this->dir.'/cemainou-2026-10-22-120000.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($tables as $table => $rows) {
            $zip->addFromString("database/{$table}.json", "[\n".implode(",\n", array_map('json_encode', $rows))."\n]\n");
        }
        foreach ($extra as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->addFromString('manifest.json', json_encode(['app' => 'cemainou', 'version' => $version ?? config('cemainou.version'), 'created_at' => now()->toIso8601String(), 'database' => 'mysql']));
        $zip->close();

        return $path;
    }

    public function test_a_backup_brings_back_the_data_as_it_was(): void
    {
        $ana = User::factory()->create(['name' => 'Ana', 'role' => 'admin']);
        $project = Project::factory()->create(['name' => 'Auto Nord']);
        Task::factory()->create(['project_id' => $project->id, 'title' => 'Pagina de start', 'created_by' => $ana->id]);
        $backup = Backups::make();

        Task::query()->delete();
        $project->update(['name' => 'Schimbat']);
        User::factory()->create(['name' => 'Ion']);

        $this->artisan('cemainou:restore', ['file' => $backup, '--force' => true, '--no-backup' => true])->assertSuccessful();

        $this->assertSame(['Ana'], User::pluck('name')->all());
        $this->assertSame('Auto Nord', $project->fresh()->name);
        $this->assertSame(['Pagina de start'], Task::pluck('title')->all());
    }

    public function test_after_a_restore_the_app_is_not_waiting_for_an_update(): void
    {
        $path = $this->jsonBackup(['settings' => [['key' => Updater::VERSION_KEY, 'value' => '0.1.300', 'updated_by' => null, 'created_at' => null, 'updated_at' => null]]]);

        Restore::run($path, $this->dir.'/files');

        $this->assertSame(config('cemainou.version'), Setting::where('key', Updater::VERSION_KEY)->sole()->value);
    }

    public function test_a_mysql_json_backup_restores_on_any_engine_line_by_line(): void
    {
        $old = User::factory()->create(['name' => 'De sters']);
        $path = $this->jsonBackup(['users' => [
            ['id' => 7, 'name' => 'Ana Ș.', 'email' => 'ana@rt.test', 'password' => 'x', 'role' => 'admin', 'is_active' => 1, 'timezone' => 'Europe/Chisinau', 'created_at' => '2026-10-01 10:00:00', 'updated_at' => '2026-10-01 10:00:00', 'coloana_veche' => 'ignorată'],
            ['id' => 8, 'name' => 'Ion', 'email' => 'ion@rt.test', 'password' => 'x', 'role' => 'member', 'is_active' => 1, 'timezone' => 'Europe/Chisinau', 'created_at' => '2026-10-01 10:00:00', 'updated_at' => '2026-10-01 10:00:00'],
        ]]);

        $done = Restore::run($path, $this->dir.'/files');

        $this->assertSame(['tables' => 1, 'rows' => 2], array_intersect_key($done, ['tables' => 0, 'rows' => 0]));
        $this->assertSame(['Ana Ș.', 'Ion'], User::orderBy('id')->pluck('name')->all());
        $this->assertNull(User::find($old->id));
        $this->assertGreaterThan(8, User::factory()->create()->id);                        // numărătoarea continuă după rândurile puse (MySQL nu coboară contorul)
    }

    public function test_an_old_single_line_json_backup_still_restores(): void
    {
        $path = $this->dir.'/cemainou-2026-10-22-120000.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('database/projects.json', json_encode([['id' => 3, 'name' => 'Vechi', 'slug' => 'vechi', 'created_at' => '2026-10-01 10:00:00', 'updated_at' => '2026-10-01 10:00:00']]));
        $zip->addFromString('manifest.json', json_encode(['app' => 'whatsupai', 'version' => config('cemainou.version'), 'created_at' => 'x', 'database' => 'mysql']));
        $zip->close();

        Restore::run($path, $this->dir.'/files');

        $this->assertSame(['Vechi'], Project::pluck('name')->all());
    }

    public function test_another_version_is_refused_and_nothing_changes(): void
    {
        User::factory()->create(['name' => 'Ana']);
        $path = $this->jsonBackup(['users' => []], version: '0.1.1');

        $this->expectException(RuntimeException::class);
        try {
            Restore::run($path);
        } finally {
            $this->assertSame(['Ana'], User::pluck('name')->all());
        }
    }

    public function test_a_broken_row_rolls_everything_back(): void
    {
        User::factory()->create(['name' => 'Ana']);
        $path = $this->jsonBackup(['projects' => [['id' => 1, 'name' => null, 'slug' => 'x']]]);   // name e obligatoriu

        try {
            Restore::run($path, $this->dir.'/files');
            $this->fail('trebuia să se oprească');
        } catch (\Throwable) {
        }

        $this->assertSame(['Ana'], User::pluck('name')->all());
        $this->assertSame(1, DB::table('users')->count());
    }

    public function test_files_come_back_but_never_outside_the_files_folder(): void
    {
        $path = $this->jsonBackup([], ['files/2026/10/deck.html' => '<h1>x</h1>', 'files/../../evil.php' => '<?php', 'files//etc/x' => 'x']);

        $done = Restore::run($path, $this->dir.'/files');

        $this->assertSame(1, $done['files']);
        $this->assertSame('<h1>x</h1>', File::get($this->dir.'/files/2026/10/deck.html'));
        $this->assertFileDoesNotExist($this->dir.'/evil.php');
        $this->assertFileDoesNotExist(dirname($this->dir).'/evil.php');
    }

    public function test_the_command_finds_backups_by_name_and_refuses_strangers(): void
    {
        $this->artisan('cemainou:restore', ['file' => 'nu-exista.zip', '--force' => true])->assertFailed();
        File::put($this->dir.'/cemainou-2026-10-22-130000.zip', 'nu e zip');
        $this->artisan('cemainou:restore', ['file' => 'cemainou-2026-10-22-130000.zip', '--force' => true])->assertFailed();
    }
}
