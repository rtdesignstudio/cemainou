<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Filament\Resources\Tasks\Pages\ViewTask;
use App\Livewire\ProjectTasks;
use App\Livewire\QuickCapture;
use App\Mcp\Servers\CemainouServer;
use App\Mcp\Tools\AddTaskTool;
use App\Mcp\Tools\ListTasksTool;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ideile: notate pentru mai târziu, fără om și în afara sarcinilor deschise, până cineva le face sarcini. Notarea rapidă (N, „+", Azi):
 * idee, sarcină sau notiță într-un câmp, pe proiectul paginii sau pe ultimul la care ai lucrat.
 */
class IdeasAndCaptureTest extends TestCase
{
    use RefreshDatabase;

    private User $ion;

    private Project $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ion = User::factory()->create(['name' => 'Ion', 'role' => 'member']);
        $this->site = Project::factory()->create(['name' => 'Site', 'slug' => 'site']);
    }

    public function test_an_idea_waits_apart_until_it_becomes_a_task(): void
    {
        $idea = Task::add($this->site, $this->ion, 'Galeria cu swipe', idea: true, assignee: User::factory()->create());
        $this->assertSame([TaskStatus::Idea, null], [$idea->status, $idea->assignee_id]);                 // fără om, chiar dacă i s-a dat unul
        $this->assertSame(0, Task::open()->count());

        Livewire::actingAs($this->ion)->test(ListTasks::class)->assertCanNotSeeTableRecords([$idea])
            ->set('activeTab', 'ideas')->assertCanSeeTableRecords([$idea]);
        Livewire::actingAs($this->ion)->test(ProjectTasks::class, ['project' => $this->site])->assertSee(['Ideas', 'Galeria cu swipe', 'Make it a task'])
            ->call('promote', $idea->id);
        $this->assertSame(TaskStatus::Todo, $idea->fresh()->status);
        Livewire::actingAs($this->ion)->test(ViewTask::class, ['record' => $idea->getRouteKey()])->assertActionHidden('promote');
    }

    public function test_agents_note_ideas_and_list_them(): void
    {
        CemainouServer::actingAs($this->ion)->tool(AddTaskTool::class, ['project' => 'site', 'title' => 'Un mod întunecat', 'idea' => true])->assertOk()->assertSee('Idea #');
        CemainouServer::actingAs($this->ion)->tool(ListTasksTool::class, ['project' => 'site', 'scope' => 'ideas'])->assertOk()->assertSee('Un mod întunecat');
        CemainouServer::actingAs($this->ion)->tool(ListTasksTool::class, ['project' => 'site', 'scope' => 'all'])->assertOk()->assertDontSee('Un mod întunecat');
    }

    public function test_quick_capture_saves_an_idea_a_task_or_a_note_on_the_right_project(): void
    {
        $other = Project::factory()->create(['name' => 'Altul']);
        $capture = fn () => Livewire::actingAs($this->ion)->test(QuickCapture::class);

        $capture()->call('open', ['kind' => 'idea', 'project' => $this->site->id])->assertSet('project', $this->site->id)
            ->set('text', "Galeria cu swipe\nși săgeți pe desktop")->call('save')->assertHasNoErrors()->assertDispatched('close-modal', id: 'wsa-capture');
        $idea = Task::sole();
        $this->assertSame([TaskStatus::Idea, 'Galeria cu swipe', 'și săgeți pe desktop'], [$idea->status, $idea->title, $idea->notes]);

        $capture()->call('open', ['kind' => 'task', 'project' => $other->id])->set('text', 'Formularul')->set('mine', true)->call('save');
        $this->assertSame([$other->id, $this->ion->id, TaskStatus::Todo], [Task::latest('id')->first()->project_id, Task::latest('id')->first()->assignee_id, Task::latest('id')->first()->status]);

        $capture()->call('open', ['kind' => 'note'])->assertSet('project', $other->id)                     // fără proiect: ultimul la care a lucrat... sau primul
            ->set('project', $this->site->id)->set('text', 'Pe staging se intră prin VPN')->call('save');
        $this->assertSame('Pe staging se intră prin VPN', $this->site->notes()->sole()->body);

        $capture()->call('open', [])->assertSet('choosing', true)->call('pick', 'task')->assertSet('kind', 'task')->assertSet('choosing', false);
        $capture()->call('open', ['kind' => 'idea'])->set('text', '')->call('save')->assertHasErrors('text');
    }

    public function test_one_plus_opens_ideas_tasks_notes_projects_clients_and_feedback_and_clients_get_none(): void
    {
        Livewire::actingAs($this->ion)->test(Dashboard::class)->assertActionVisible('newTask');
        $this->actingAs($this->ion)->get(Dashboard::getUrl())->assertSee(['wsa-bottom-add', 'wsa-fab-add', 'wsa-capture', 'wsa-feedback'], false);   // „+" pe telefon și pe calculator; feedback-ul în alegere
        Livewire::actingAs($this->ion)->test(QuickCapture::class)->call('open', [])->assertSee(['Feedback about cemainou', 'New project', 'New client']);

        $maria = User::factory()->create(['role' => 'client', 'client_id' => Client::factory()->create()->id]);
        $this->actingAs($maria)->get(Dashboard::getUrl())->assertSee('wsa-bottom-add', false)->assertDontSee('Feedback about cemainou');   // și clientul are „+" (fără timp și fără feedback despre aplicație)
        Livewire::actingAs($maria)->test(QuickCapture::class)->call('open', [])->assertDontSee(['New project', 'New client', 'a meeting, a call']);

    }

    public function test_a_quick_task_has_every_field_and_from_a_persons_page_it_is_theirs(): void
    {
        $ana = User::factory()->create(['name' => 'Ana', 'role' => 'member']);
        $closed = Project::factory()->create(['name' => 'Doar aleșii', 'restricted' => true]);
        $closed->members()->attach($this->ion);                                         // Ion îl vede, Ana nu
        $capture = fn () => Livewire::actingAs($this->ion)->test(QuickCapture::class);

        // toate câmpurile: cine o face, termenul, recurența; detaliile din rândurile de sub titlu
        $due = now()->addDays(3)->toDateString();
        $capture()->call('open', ['kind' => 'task', 'project' => $this->site->id])->assertSee(['Who does it', 'Due', 'Repeats'])
            ->set('text', "Raportul lunar\n- [ ] cifrele")->set('assignee', (string) $ana->id)->set('due', $due)->set('repeat', 'monthly')->call('save')->assertHasNoErrors();
        $task = Task::latest('id')->first();
        $this->assertSame(['Raportul lunar', $ana->id, $due, 'monthly', '- [ ] cifrele'], [$task->title, $task->assignee_id, $task->due_on->toDateString(), $task->repeat->value, $task->notes]);

        // de pe pagina omului: sarcina e a lui, pe un proiect pe care îl vede
        $capture()->call('open', ['kind' => 'task', 'project' => $closed->id, 'assignee' => $ana->id])->assertSet('assignee', null);   // proiect închis lui: rămâne liberă
        $capture()->call('open', ['kind' => 'task', 'assignee' => $ana->id])->assertSet('assignee', (string) $ana->id)
            ->tap(fn ($c) => $this->assertNotSame($closed->id, $c->get('project')));

        // cineva fără acces la proiect sau un termen trecut: refuzat, nimic salvat
        $count = Task::count();
        $capture()->call('open', ['kind' => 'task', 'project' => $closed->id])->set('text', 'Ceva')->set('assignee', (string) $ana->id)->call('save')->assertHasErrors('assignee');
        $capture()->call('open', ['kind' => 'task', 'project' => $this->site->id])->set('text', 'Ceva')->set('due', now()->subDay()->toDateString())->call('save')->assertHasErrors('due');
        $this->assertSame($count, Task::count());
    }
}
