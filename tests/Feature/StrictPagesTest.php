<?php

namespace Tests\Feature;

use App\Filament\Resources\Clients\Pages\ListClients;
use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Filament\Resources\Tasks\Pages\ViewTask;
use App\Filament\Resources\Updates\Pages\ListUpdates;
use App\Filament\Resources\Updates\Pages\ViewUpdate;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Widgets\Blockers;
use App\Filament\Widgets\LatestUpdates;
use App\Filament\Widgets\MyTasks;
use App\Filament\Widgets\TeamToday;
use App\Models\Project;
use App\Models\Task;
use App\Models\Update;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Cu modelele stricte, o listă care încarcă o legătură rând cu rând aruncă eroare. Aici fiecare pagină se deschide ca admin,
 * cu mai multe rânduri de toate felurile, ca o astfel de greșeală să pice în teste, nu la Radu în browser.
 */
class StrictPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_page_opens_with_many_rows(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);
        $projects = Project::factory()->count(3)->create(['lead_id' => User::factory()->create(['role' => 'lead'])->id]);
        $people = User::factory()->count(3)->sequence(fn ($s) => ['focus_text' => "Lucru {$s->index}", 'focus_project_id' => $projects[$s->index]->id])->create();
        foreach ($people as $i => $person) {
            $update = Update::factory()->for($person)->for($projects[$i])->create(['minutes' => 30]);
            $task = Task::add($projects[$i], $admin, "Sarcina {$i}", assignee: $person);
            $update->addItems(['done' => [['text' => $task->title, 'task_id' => $task->id]], 'blocked' => ["Blocaj {$i}"]]);
            $task->take($person);
        }

        foreach ([ListUsers::class, ListProjects::class, ListClients::class, ListUpdates::class, ListTasks::class] as $page) {
            Livewire::test($page)->assertSuccessful();
        }
        Livewire::test(ListTasks::class)->set('activeTab', 'open')->assertSuccessful()->assertSee('Sarcina 2');
        Livewire::test(ListUsers::class)->assertSee([$projects[0]->name, 'Sarcina 1']);      // „Iau eu" a mutat focusul pe sarcină
        Livewire::test(ViewProject::class, ['record' => $projects[0]->getRouteKey()])->assertSuccessful();
        Livewire::test(ViewTask::class, ['record' => Task::first()->getRouteKey()])->assertSuccessful();
        Livewire::test(ViewUpdate::class, ['record' => Update::first()->getRouteKey()])->assertSuccessful();
        foreach ([TeamToday::class, LatestUpdates::class, MyTasks::class, Blockers::class] as $widget) {
            Livewire::test($widget)->assertSuccessful();
        }
    }
}
