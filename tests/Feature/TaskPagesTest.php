<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Tasks\Pages\CreateTask;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Filament\Resources\Tasks\Pages\ViewTask;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\Inbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Js;
use Livewire\Livewire;
use Tests\TestCase;

class TaskPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_tabs_show_mine_free_and_done_tasks(): void
    {
        $ion = User::factory()->create();
        $this->actingAs($ion);
        $project = Project::factory()->create();
        $mine = Task::add($project, $ion, 'A mea', assignee: $ion);
        $free = Task::add($project, $ion, 'Liberă');
        $done = Task::add($project, $ion, 'Gata', assignee: $ion);
        $done->moveTo(TaskStatus::Done, $ion);

        Livewire::test(ListTasks::class)->assertSet('activeTab', 'mine')                                 // cine are de lucru: întâi ale lui, active și încheiate
            ->assertSee(['A mea', 'Gata'])->assertDontSee('Liberă');
        Livewire::test(ListTasks::class)->set('activeTab', 'open')->assertCanSeeTableRecords([$mine, $free])->assertCanNotSeeTableRecords([$done]);
        Livewire::test(ListTasks::class)->set('activeTab', 'free')->assertCanSeeTableRecords([$free])->assertCanNotSeeTableRecords([$mine]);
        Livewire::test(ListTasks::class)->set('activeTab', 'done')->assertCanSeeTableRecords([$done]);
    }

    public function test_take_from_the_list_and_create_a_task(): void
    {
        $ion = User::factory()->create();
        $this->actingAs($ion);
        $project = Project::factory()->create();
        $free = Task::add($project, User::factory()->create(), 'Liberă');

        Livewire::test(ListTasks::class)->set('activeTab', 'free')->callTableAction('take', $free);
        $this->assertSame($ion->id, $free->fresh()->assignee_id);

        Livewire::test(ListTasks::class)->assertActionHasUrl('newTask', TaskResource::getUrl('create'));       // o pagină, nu o fereastră
        Livewire::test(Dashboard::class)->assertActionHasUrl('newTask', TaskResource::getUrl('create'));       // și de pe Azi

        Livewire::test(CreateTask::class)->fillForm(['title' => 'Pagina nouă', 'project_id' => $project->id])->call('add')->assertHasNoErrors()
            ->assertRedirect(TaskResource::getUrl('view', ['record' => Task::where('title', 'Pagina nouă')->sole()]));
        $this->assertSame([$ion->id, null], Task::where('title', 'Pagina nouă')->get()->map(fn ($t) => [$t->created_by, $t->assignee_id])->sole());
    }

    public function test_a_new_task_page_takes_the_project_from_the_link_and_keeps_files(): void
    {
        Storage::fake('data');
        $ion = User::factory()->create();
        $this->actingAs($ion);
        $project = Project::factory()->create();

        $this->get(TaskResource::getUrl('create', ['project' => $project->id]))->assertOk()->assertSee('Add screenshots, a video or a file');
        Livewire::withQueryParams(['project' => $project->id])->test(CreateTask::class)->assertSchemaStateSet(['project_id' => $project->id])
            ->fillForm(['title' => 'Galeria', 'assignee_id' => $ion->id])
            ->set('files', [UploadedFile::fake()->image('captura.png'), UploadedFile::fake()->create('ecran.mp4', 300, 'video/mp4')])
            ->call('add')->assertHasNoErrors();
        $task = Task::where('title', 'Galeria')->sole();
        $this->assertSame([$ion->id, 2], [$task->assignee_id, $task->attachments()->count()]);

        Livewire::test(CreateTask::class)->fillForm(['title' => 'X', 'project_id' => $project->id])
            ->set('files', [UploadedFile::fake()->create('script.exe', 2)])->call('add')->assertHasErrors('files.0');
    }

    public function test_a_new_task_page_has_a_way_back(): void
    {
        $this->actingAs(User::factory()->create());
        $project = Project::factory()->create();

        $this->assertSame([['label' => __('Tasks'), 'url' => TaskResource::getUrl('index')]], Livewire::test(CreateTask::class)->instance()->backLinks());
        $this->assertSame($project->name, collect(Livewire::withQueryParams(['project' => $project->id])->test(CreateTask::class)->instance()->backLinks())->last()['label']);
        $this->assertSame(__('Tasks'), Livewire::withQueryParams(['project' => 999999])->test(CreateTask::class)->instance()->backLinks()[0]['label']);
    }

    public function test_the_board_shows_tasks_by_status_moves_them_and_undoes_a_move(): void
    {
        $ion = User::factory()->create(['name' => 'Ion Rusu']);
        $this->actingAs($ion);
        $project = Project::factory()->create();
        $todo = Task::add($project, $ion, 'Pagina de contact', assignee: $ion);
        $doing = Task::add($project, $ion, 'Galeria foto', assignee: $ion);
        $doing->moveTo(TaskStatus::Doing, $ion);
        $old = Task::add($project, $ion, 'Logo vechi', assignee: $ion);
        $old->close($ion);
        $old->forceFill(['done_at' => now()->subWeeks(2)])->save();

        $page = Livewire::test(ListTasks::class)->call('setMode', 'board')
            ->assertSeeInOrder(['To do', 'Pagina de contact', 'Blocked', 'In progress', 'Galeria foto', 'Done this week'])
            ->assertDontSee('Logo vechi');                                                            // gata doar săptămâna asta
        $this->assertSame('board', session('tasks.view'));

        $page->call('dropTask', $todo->id, 'doing')->assertNotified(__('Moved to :status: #:id', ['status' => TaskStatus::Doing->getLabel(), 'id' => $todo->id]));
        $this->assertSame(TaskStatus::Doing, $todo->fresh()->status);
        $page->dispatch('board-undo');                                                                // „Anulează" din notificare
        $this->assertSame(TaskStatus::Todo, $todo->fresh()->status);

        $page->call('dropTask', $doing->id, 'blocked')->assertActionMounted('mineBlock');   // blocat cere motivul, în fereastra de pe panou
        $page->setActionData(['reason' => 'Textele de la client'])->callMountedAction();
        $this->assertSame([TaskStatus::Blocked, 'Textele de la client'], [$doing->fresh()->status, $doing->fresh()->blocked_reason]);
        $maria = User::factory()->create(['name' => 'Maria Ceban']);
        $page->mountAction('boardAssign', ['task' => $todo->id])->setActionData(['assignee_id' => $maria->id])->callMountedAction();
        $this->assertSame($maria->id, $todo->fresh()->assignee_id);
        $doing->comments()->create(['user_id' => $maria->id, 'body' => 'Am pus textele']);            // un comentariu nou pentru Ion: „1 nou" pe card, din clopoțel
        $page->call('setMode', 'board')->assertSee('1 new');
        Inbox::readAbout($ion, TaskResource::getUrl('view', ['record' => $doing]));
        $page->call('setMode', 'board')->assertDontSee('1 new');
        $done = Task::add($project, $ion, 'Banner nou', assignee: $ion);                              // gata: cât a lucrat fiecare, din ce s-a păstrat la închidere
        $done->close($ion);
        $done->forceFill(['time_minutes' => 150, 'time_people' => [[$ion->id, 90], [$maria->id, 60]]])->save();
        $page->call('setMode', 'board')->assertSeeInOrder(['Banner nou', '2h 30m', 'Ion', '1h 30m', 'Maria', '1h']);
        Livewire::test(ListTasks::class)->assertSet('mode', 'board')->call('setMode', 'list')->assertSee('Galeria foto')->assertDontSee('wsa-board-cols', false);
    }

    public function test_task_page_shows_history_marks_it_seen_and_closes_it(): void
    {
        $ana = User::factory()->create(['name' => 'Ana Popescu']);
        $ion = User::factory()->create(['name' => 'Ion Rusu']);
        $task = Task::add(Project::factory()->create(), $ana, 'Galeria foto', 'Swipe pe mobil', $ion);
        $this->actingAs($ion);

        Livewire::test(ViewTask::class, ['record' => $task->getRouteKey()])
            ->assertSuccessful()->assertSee(['Galeria foto', 'Swipe pe mobil', 'Ana Popescu created it for Ion Rusu'])
            ->callAction('done');

        $this->assertSame(TaskStatus::Done, $task->fresh()->status);
        $this->assertFalse($task->fresh()->isNew());
    }

    public function test_a_colleague_gets_take_done_and_reassign_but_not_edit_or_delete(): void
    {
        $ion = User::factory()->create();
        $task = Task::add(Project::factory()->create(), $ion, 'A lui Ion', assignee: $ion);
        $this->actingAs(User::factory()->create());

        Livewire::test(ViewTask::class, ['record' => $task->getRouteKey()])
            ->assertActionVisible('done')->assertActionVisible('reassign')->assertActionVisible('take')->assertActionHidden('edit')->assertActionHidden('delete');
    }

    public function test_a_task_opened_from_my_list_leads_back_to_it_with_the_previous_and_next_ones(): void
    {
        $ion = User::factory()->create();
        $project = Project::factory()->create(['name' => 'Studio Nord']);
        $a = Task::add($project, $ion, 'Prima', assignee: $ion);
        $b = Task::add($project, $ion, 'A doua', assignee: $ion);
        $c = Task::add($project, $ion, 'A treia', assignee: $ion);
        $this->actingAs($ion);

        Livewire::withQueryParams(['tab' => 'mine'])->test(ListTasks::class)->assertSee(TaskResource::getUrl('view', ['record' => $b, 'from' => 'list']), false);
        $ids = session('tasks.trail.ids');
        $this->assertCount(3, $ids);
        $middle = $ids[1];

        $this->get(TaskResource::getUrl('view', ['record' => $middle, 'from' => 'list']))->assertOk()
            ->assertSee(['My tasks', 'tab=mine', 'Studio Nord', 'Previous one', 'Next one'], false)
            ->assertSee(TaskResource::getUrl('view', ['record' => $ids[0], 'from' => 'list']), false)
            ->assertSee(TaskResource::getUrl('view', ['record' => $ids[2], 'from' => 'list']), false);

        $this->get(TaskResource::getUrl('view', ['record' => $middle]))->assertOk()->assertDontSee('Next one');   // venit de altundeva: drumul obișnuit
    }

    public function test_overdue_and_today_tasks_are_flagged_on_my_list(): void
    {
        $ion = User::factory()->create();
        $project = Project::factory()->create();
        Task::add($project, $ion, 'Coperta', assignee: $ion)->forceFill(['due_on' => now()->subDays(3)->toDateString()])->save();
        Task::add($project, $ion, 'Afișul', assignee: $ion)->forceFill(['due_on' => now()->toDateString()])->save();
        $this->actingAs($ion);

        Livewire::withQueryParams(['tab' => 'mine'])->test(ListTasks::class)->assertSee(['Overdue by 3 days', 'Due today'])->assertSeeHtml('is-overdue');
    }

    public function test_a_task_dragged_to_another_group_moves_and_blocked_or_done_ask_first(): void
    {
        $ion = User::factory()->create();
        $task = Task::add(Project::factory()->create(), $ion, 'Coperta', assignee: $ion);
        $this->actingAs($ion);
        $page = Livewire::withQueryParams(['tab' => 'mine'])->test(ListTasks::class);

        $page->call('dropTask', $task->id, 'doing');
        $this->assertSame(TaskStatus::Doing, $task->fresh()->status);
        $page->call('dropTask', $task->id, 'todo');
        $this->assertSame(TaskStatus::Todo, $task->fresh()->status);

        $page->call('dropTask', $task->id, 'blocked')->assertActionMounted('mineBlock');                 // motivul, întâi
        $this->assertSame(TaskStatus::Todo, $task->fresh()->status);
        $page->call('dropTask', $task->id, 'idea')->assertForbidden();
    }

    public function test_a_task_link_can_be_copied_from_its_page_and_from_my_list(): void
    {
        $ion = User::factory()->create();
        $task = Task::add(Project::factory()->create(), $ion, 'Coperta', assignee: $ion);
        $this->actingAs($ion);
        $url = TaskResource::getUrl('view', ['record' => $task]);

        $this->get($url)->assertOk()->assertSee(['wsa-copy-task', 'Copy the link'], false)->assertSee(Js::from($url)->toHtml(), false);
        Livewire::withQueryParams(['tab' => 'mine'])->test(ListTasks::class)->assertSeeHtml('wsa-copy-task');
    }
}
