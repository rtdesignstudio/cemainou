<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Filament\AvatarProviders\InitialsAvatarProvider;
use App\Filament\Resources\Tasks\Pages\ViewTask;
use App\Filament\Resources\Updates\Pages\ViewUpdate;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Livewire\ProjectTasks;
use App\Models\Project;
use App\Models\Task;
use App\Models\Update;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Corecțiile din auditul de interfață (7 oct 2026): texte în română peste tot, pagina de update cu update-urile proiectului pe zile, bifa la sarcinile făcute. */
class InterfaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_validation_speaks_romanian_in_the_inline_fields_and_in_filament_forms(): void
    {
        $this->actingAs(User::factory()->create(['locale' => 'ro', 'role' => 'admin']));
        app()->setLocale('ro');

        Livewire::test(ProjectTasks::class, ['project' => Project::factory()->create()])->set('data.text', '')->call('add')
            ->assertHasErrors(['data.text'])->assertSee('Scrie sarcina: primul rând e titlul.');
        Livewire::test(CreateUser::class)->fillForm(['name' => '', 'email' => 'nu-e-email'])->call('create')
            ->assertHasFormErrors(['name', 'email'])->assertSee(['Câmpul „nume” e obligatoriu.', 'Câmpul „email” trebuie să fie o adresă de email validă.']);
    }

    public function test_filament_texts_use_the_app_voice_in_romanian(): void
    {
        $this->actingAs(User::factory()->create(['locale' => 'ro', 'role' => 'admin']));
        $this->get('/users')->assertOk()->assertSee(['Invită pe cineva', 'Adaugă cu parolă']);
        $this->get('/users/create')->assertOk()->assertSee('Creează')->assertDontSee('Creare');
        $this->get('/login')->assertDontSee('Loghează-te');
    }

    public function test_the_update_page_lists_the_projects_updates_by_day_with_the_current_one_marked(): void
    {
        $viewer = User::factory()->create(['timezone' => 'Europe/Chisinau']);
        $project = Project::factory()->create(['name' => 'Site Test']);
        $older = Update::factory()->for($project)->create(['summary' => 'Formularul de contact', 'created_at' => Carbon::parse('2026-10-05 10:00', 'Europe/Chisinau')->utc()]);
        $current = Update::factory()->for($project)->create(['summary' => 'Galeria foto', 'created_at' => Carbon::parse('2026-10-06 00:30', 'Europe/Chisinau')->utc()]);   // 5 oct în UTC, 6 oct la Chișinău
        Update::factory()->create(['summary' => 'Alt proiect']);

        Livewire::actingAs($viewer)->test(ViewUpdate::class, ['record' => $current->getRouteKey()])
            ->assertSeeInOrder(['On Site Test', '6 October', '00:30', 'Galeria foto', '5 October', '10:00', 'Formularul de contact'])
            ->assertSeeHtml('aria-current="page"')
            ->assertDontSee('Alt proiect');
    }

    public function test_done_tasks_get_a_check_mark_on_the_update_page_and_in_lists(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create();
        $task = Task::add($project, $user, 'Eticheta de rosé', assignee: $user);
        $update = Update::factory()->for($project)->for($user)->create(['summary' => 'Etichete']);
        $task->moveTo(TaskStatus::Done, $user, update: $update);
        $update->addItems(['done' => [['text' => $task->title, 'task_id' => $task->id]]]);

        Livewire::actingAs($user)->test(ViewUpdate::class, ['record' => $update->getRouteKey()])->assertSeeHtml('class="wsa-item is-done"');   // bifa (::before) la fiecare rând din „Făcut"
        Livewire::actingAs($user)->test(ProjectTasks::class, ['project' => $project])->assertSeeHtml('wsa-mark is-done');
        Livewire::actingAs($user)->test(ViewTask::class, ['record' => $task->getRouteKey()])->assertSee('Eticheta de rosé');
    }

    public function test_the_project_page_shows_colleagues_notes_to_the_lead_without_lazy_loading(): void
    {
        $lead = User::factory()->create();
        $project = Project::factory()->create(['lead_id' => $lead->id]);
        $project->notes()->create(['user_id' => User::factory()->create()->id, 'body' => 'Pe staging se intră prin VPN', 'source' => 'web']);

        $this->actingAs($lead)->get("/projects/{$project->id}")->assertOk()->assertSee('Pe staging se intră prin VPN')->assertSee('No longer valid');
    }

    public function test_avatars_are_local_initials_not_an_external_service(): void
    {
        $url = app(InitialsAvatarProvider::class)->get(User::factory()->create(['name' => 'Ana Maria Popescu']));

        $this->assertStringStartsWith('data:image/svg+xml;base64,', $url);
        $this->assertStringContainsString('>AM<', base64_decode(substr($url, strlen('data:image/svg+xml;base64,'))));
        $this->actingAs(User::factory()->create())->get('/')->assertOk()->assertDontSee('ui-avatars.com');
    }
}
