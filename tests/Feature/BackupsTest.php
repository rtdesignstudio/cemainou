<?php

namespace Tests\Feature;

use App\Filament\Pages\SystemSettings;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\User;
use App\Support\Backups;
use App\Support\Files;
use App\Support\Housekeeping;
use App\Support\Settings;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

/** Backup-ul: baza de date (copie consistentă) și fișierele, într-o arhivă; zilnic sau la cerere; doar adminul îl descarcă; se păstrează ultimele N. */
class BackupsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['name' => 'Radu', 'role' => 'admin']);
    }

    public function test_a_backup_has_the_database_the_files_and_a_manifest_but_not_the_app_key(): void
    {
        Storage::fake(Attachment::DISK);
        Files::attach(Project::factory()->create(['name' => 'Auto Nord']), $this->admin, '<h1>x</h1>', 'deck.html');

        $zip = new ZipArchive;
        $zip->open(Backups::make());
        $names = collect(range(0, $zip->numFiles - 1))->map(fn ($i) => $zip->getNameIndex($i))->all();

        $driver = \DB::connection()->getDriverName();
        $this->assertContains('manifest.json', $names);
        $this->assertSame($driver, json_decode($zip->getFromName('manifest.json'), true)['database']);
        if ($driver === 'sqlite') {
            $this->assertStringStartsWith('SQLite format 3', $zip->getFromName('database.sqlite'));
        } else {
            $this->assertContains('database/users.json', $names);                                           // MySQL / MariaDB: fiecare tabel în JSON
            $this->assertSame('Radu', json_decode($zip->getFromName('database/users.json'), true)[0]['name']);
        }
        $this->assertStringNotContainsString((string) config('app.key'), implode('', array_map(fn ($n) => $zip->getFromName($n), $names)));
        $zip->close();
    }

    public function test_only_the_last_ones_are_kept_and_the_daily_one_is_made_once(): void
    {
        Settings::save(['backup_keep' => 2], $this->admin);
        foreach (range(1, 3) as $i) {
            $this->travel(1)->seconds();
            Backups::make();
        }
        $this->assertCount(2, Backups::all());

        $this->travel(1)->days();
        app(Housekeeping::class)->run();
        app(Housekeeping::class)->run();
        $this->assertCount(2, Backups::all());
        $this->assertTrue(Backups::all()[0]['at']->isToday());

        Settings::save(['backup_daily' => false], $this->admin);
        $this->travel(1)->days();
        app(Housekeeping::class)->run();
        $this->assertFalse(Backups::all()[0]['at']->isToday());
    }

    public function test_old_whatsupai_backups_are_listed_by_date_and_pruned_first(): void
    {
        Settings::save(['backup_keep' => 2], $this->admin);
        $old = Backups::dir().'/whatsupai-2026-01-01-120000.zip';             // de dinainte de 0.1.257, cu numele vechi
        File::ensureDirectoryExists(Backups::dir());
        file_put_contents($old, 'zip');
        $this->travel(1)->seconds();
        $new = basename(Backups::make());

        $this->assertStringStartsWith('cemainou-', $new);
        $this->assertSame([$new, 'whatsupai-2026-01-01-120000.zip'], array_column(Backups::all(), 'name'));
        $this->actingAs($this->admin)->get(route('backups.download', 'whatsupai-2026-01-01-120000.zip'))->assertOk();

        $this->travel(1)->seconds();
        Backups::make();
        $this->assertFileDoesNotExist($old);                                     // cel mai vechi pleacă, nu cel nou
        $this->assertCount(2, Backups::all());
    }

    public function test_only_admins_download_and_only_real_backup_names(): void
    {
        $name = basename(Backups::make());

        $this->actingAs(User::factory()->create(['role' => 'lead']))->get(route('backups.download', $name))->assertForbidden();
        $this->actingAs($this->admin)->get(route('backups.download', $name))->assertOk()->assertDownload($name);
        $this->get('/backups/..%2F.env')->assertNotFound();
        $this->get(route('backups.download', 'whatsupai-2020-01-01-000000.zip'))->assertNotFound();
    }

    public function test_the_admin_makes_one_from_the_settings(): void
    {
        Livewire::actingAs($this->admin)->test(SystemSettings::class)->callAction(TestAction::make('backupNow')->schemaComponent('backupActions'))->assertNotified();

        $this->assertCount(1, Backups::all());
    }
}
