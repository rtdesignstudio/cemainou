<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Filament\Pages\ErrorLog as ErrorLogPage;
use App\Filament\Resources\Tasks\Pages\ViewTask;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Resources\Updates\UpdateResource;
use App\Models\Client;
use App\Models\ClientView;
use App\Models\Project;
use App\Models\Task;
use App\Models\Update;
use App\Models\User;
use App\Support\DiskUsage;
use App\Support\ErrorLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

/** „Văzut de client", răspunsul clientului care deblochează, erorile în clopoțelul adminilor și copia veche a codului care se șterge singură. */
class ClientSeenAndAnswersTest extends TestCase
{
    use RefreshDatabase;

    private User $mihai;

    private User $elena;

    private Project $site;

    protected function setUp(): void
    {
        parent::setUp();
        $vita = Client::factory()->create(['name' => 'Clinica Vita']);
        $this->mihai = User::factory()->create(['name' => 'Mihai Rusu', 'role' => 'member']);
        $this->elena = User::factory()->create(['name' => 'Elena Popa', 'role' => 'client', 'client_id' => $vita->id]);
        $this->site = Project::factory()->create(['name' => 'Site nou', 'client_id' => $vita->id, 'client_visible' => true]);
    }

    public function test_the_team_sees_when_the_client_opened_a_task_or_an_update(): void
    {
        $task = Task::add($this->site, $this->mihai, 'Textele pentru pagina Despre', assignee: $this->mihai);
        $update = Update::factory()->for($this->mihai, 'user')->for($this->site)->create(['summary' => 'Galeria']);
        $internal = Update::factory()->for($this->mihai, 'user')->for($this->site)->create(['summary' => 'Intern', 'is_internal' => true]);

        $this->actingAs($this->mihai)->get(TaskResource::getUrl('view', ['record' => $task]))->assertSee('the client has not seen it yet');
        $this->actingAs($this->elena)->get(TaskResource::getUrl('view', ['record' => $task]))->assertOk()->assertDontSee('the client has not seen it yet');
        $this->actingAs($this->elena)->get(UpdateResource::getUrl('view', ['record' => $update]))->assertOk();
        $this->assertSame(2, ClientView::count());

        $this->actingAs($this->mihai)->get(TaskResource::getUrl('view', ['record' => $task]))->assertSee('seen by Elena Popa');
        $this->actingAs($this->mihai)->get(UpdateResource::getUrl('view', ['record' => $update]))->assertSee('seen by Elena Popa');
        $this->actingAs($this->mihai)->get(UpdateResource::getUrl('view', ['record' => $internal]))->assertDontSee('the client has not seen it yet');   // nu i se arată
    }

    public function test_a_client_answer_on_a_blocked_task_offers_to_unblock_it(): void
    {
        $task = Task::add($this->site, $this->mihai, 'Textele pentru pagina Despre', assignee: $this->mihai);
        $task->moveTo(TaskStatus::Blocked, $this->mihai, 'textele de la client');
        $task->comments()->create(['user_id' => $this->elena->id, 'body' => 'Le-am pus în fișierul atașat.']);

        $bell = $this->mihai->notifications()->latest()->first();
        $this->assertSame('Elena Popa answered · unblock it?', $bell->data['title']);
        $this->assertStringContainsString('?unblock=1', $bell->data['viewData']['wsa']['url']);

        $this->actingAs($this->mihai)->get(TaskResource::getUrl('view', ['record' => $task]).'?unblock=1')->assertSee('The client answered.');
        Livewire::actingAs($this->mihai)->withQueryParams(['unblock' => 1])->test(ViewTask::class, ['record' => $task->getRouteKey()])
            ->set('askUnblock', true)->call('unblock')->assertSet('askUnblock', false);
        $this->assertSame(TaskStatus::Doing, $task->fresh()->status);
    }

    public function test_yesterdays_errors_reach_the_admins_bell_grouped(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $file = storage_path('logs/laravel-'.now()->subDay()->toDateString().'.log');
        $had = is_file($file) ? File::get($file) : null;
        File::put($file, implode("\n", [
            '['.now()->subDay()->format('Y-m-d').' 09:00:01] production.ERROR: Undefined array key 12 {"page":"GET filament.admin.pages.dashboard","exception":"[object] (ErrorException(code: 0): Undefined array key 12 at '.base_path('app/Support/MyWeek.php').':88)',
            '[stacktrace]', '#0 ...',
            '['.now()->subDay()->format('Y-m-d').' 10:00:01] production.ERROR: Undefined array key 7 {"page":"GET filament.admin.pages.dashboard","exception":"[object] (ErrorException(code: 0): Undefined array key 7 at '.base_path('app/Support/MyWeek.php').':88)',
            '['.now()->subDay()->format('Y-m-d').' 11:00:01] production.WARNING: doar un avertisment',
        ])."\n");
        try {
            $groups = ErrorLog::day(now()->subDay()->toDateString());
            $this->assertCount(1, $groups);
            $this->assertSame(2, $groups[0]['count']);
            $this->assertSame('app/Support/MyWeek.php:88', $groups[0]['where']);
            $this->assertSame(['GET filament.admin.pages.dashboard'], $groups[0]['pages']);

            $this->assertSame(2, ErrorLog::morning());
            $this->assertSame('2 errors on the server yesterday', $admin->notifications()->sole()->data['title']);
            $this->assertSame(0, $this->mihai->notifications()->count());
            $this->actingAs($admin)->get(ErrorLogPage::getUrl())->assertOk()->assertSee(['×2', 'Undefined array key', 'MyWeek.php:88']);
            $this->actingAs($this->mihai)->get(ErrorLogPage::getUrl())->assertForbidden();
        } finally {
            $had === null ? File::delete($file) : File::put($file, $had);
        }
    }

    public function test_the_old_code_copy_goes_after_a_week(): void
    {
        $old = base_path('data/updates/previous-test-old-'.uniqid());
        $new = base_path('data/updates/previous-test-new-'.uniqid());
        File::ensureDirectoryExists($old);
        File::ensureDirectoryExists($new);
        File::put("{$old}/.replaced_at", now()->subDays(8)->toIso8601String());
        File::put("{$new}/.replaced_at", now()->subDay()->toIso8601String());
        File::put("{$old}/a.php", str_repeat('x', 100));
        try {
            $this->assertGreaterThanOrEqual(100, DiskUsage::pruneOldCode());
            $this->assertDirectoryDoesNotExist($old);
            $this->assertDirectoryExists($new);
        } finally {
            File::deleteDirectory($old);
            File::deleteDirectory($new);
        }
    }
}
