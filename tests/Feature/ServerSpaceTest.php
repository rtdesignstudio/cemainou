<?php

namespace Tests\Feature;

use App\Filament\Pages\Storage as ServerSpace;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\User;
use App\Support\Backups;
use App\Support\DiskUsage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** Spațiul pe server: doar adminul îl vede; părțile cu mărimile lor, cele mai mari fișiere și ce se poate elibera. */
class ServerSpaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_the_admin_sees_it_and_it_lists_the_parts_and_the_biggest_files(): void
    {
        Storage::fake(Attachment::DISK);
        $admin = User::factory()->create(['role' => 'admin']);
        $project = Project::factory()->create(['name' => 'Site']);
        Storage::disk(Attachment::DISK)->put('files/video.mp4', str_repeat('x', 5000));
        Attachment::create(['project_id' => $project->id, 'user_id' => $admin->id, 'kind' => 'file', 'title' => 'Video lansare', 'path' => 'files/video.mp4', 'name' => 'video.mp4', 'size' => 5000, 'source' => 'web']);

        $this->actingAs(User::factory()->create(['role' => 'lead']))->get(ServerSpace::getUrl())->assertForbidden();
        DiskUsage::measure(fresh: true);
        Cache::forget(DiskUsage::CACHE_KEY);
        Livewire::actingAs($admin)->test(ServerSpace::class)
            ->assertSee('Measuring the folders on the server')                         // se deschide pe loc; măsurarea vine după
            ->call('measure')
            ->assertSee(['Team files', 'Backups', 'Database', 'Logs', 'Application code', 'Video lansare', '5 KB']);
    }

    public function test_one_pass_puts_every_file_in_its_part(): void
    {
        $old = base_path('data/updates/previous-test-'.uniqid());
        File::ensureDirectoryExists($old);
        File::put("{$old}/a.php", str_repeat('x', 3000));
        $usage = DiskUsage::measure(fresh: true);
        File::deleteDirectory($old);
        $areas = collect($usage['areas'])->keyBy('key');

        $this->assertGreaterThanOrEqual(3000, $areas['updates']['size']);
        $this->assertSame($usage['app'], (int) $areas->sum('size') - (str_starts_with(Backups::dir(), base_path()) ? 0 : $areas['backups']['size']));   // fiecare octet, într-o singură parte
        $this->assertGreaterThan(0, $areas['code']['size']);
    }

    public function test_old_code_copies_and_old_logs_can_be_freed(): void
    {
        $old = base_path('data/updates/previous-test-'.uniqid());
        File::ensureDirectoryExists($old);
        File::put("{$old}/a.php", str_repeat('x', 2048));
        $log = storage_path('logs/laravel-2020-01-01.log');
        File::put($log, str_repeat('y', 1024));
        $today = storage_path('logs/laravel-'.now()->toDateString().'.log');
        $hadToday = is_file($today);
        File::append($today, '');

        $this->assertSame(2048, DiskUsage::deleteOldCode() >= 2048 ? 2048 : 0);
        $this->assertDirectoryDoesNotExist($old);
        $this->assertGreaterThanOrEqual(1024, DiskUsage::deleteOldLogs());
        $this->assertFileDoesNotExist($log);
        $this->assertFileExists($today);                                            // jurnalul de azi rămâne
        if (! $hadToday) {
            File::delete($today);
        }
    }
}
