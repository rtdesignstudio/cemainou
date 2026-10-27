<?php

namespace Tests\Feature;

use App\Livewire\TaskComments;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\Mentions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** „@" în editor: propunerile sunt oamenii care văd sarcina (nu toată echipa), cu ce se scrie pentru fiecare, exact ce recunoaște Mentions. */
class MentionSuggestionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_handle_is_the_first_name_when_unique_otherwise_the_full_name(): void
    {
        $ionut = User::factory()->create(['name' => 'Ionuț Ciobanu', 'role' => 'collaborator']);
        $ana1 = User::factory()->create(['name' => 'Ana Popescu']);
        User::factory()->create(['name' => 'Ana Rusu']);
        User::factory()->create(['name' => 'Client X', 'role' => 'client']);
        Mentions::forget();

        $c = collect(Mentions::candidates(User::all()))->pluck('handle', 'name');
        $this->assertSame('Ionuț', $c['Ionuț Ciobanu']);
        $this->assertSame('AnaPopescu', $c['Ana Popescu']);
        $this->assertArrayNotHasKey('Client X', $c->all());                                   // clienții nu se menționează
        $this->assertTrue(Mentions::in('@'.$c['Ana Popescu'].' și @'.$c['Ionuț Ciobanu'])->pluck('id')->contains($ana1->id));
        $this->assertTrue(Mentions::in('@Ionuț')->first()->is($ionut));
    }

    public function test_a_task_comment_box_suggests_only_who_sees_the_task(): void
    {
        $radu = User::factory()->create(['name' => 'Victor Rusu', 'role' => 'admin']);
        $ionut = User::factory()->create(['name' => 'Ionuț Ciobanu', 'role' => 'collaborator']);
        User::factory()->create(['name' => 'Dan Străin', 'role' => 'collaborator']);           // nu e pe proiect: nu vede sarcina
        User::factory()->create(['name' => 'Eve "<script>', 'role' => 'collaborator']);       // nici el; și numele n-ar strica atributul
        $project = Project::factory()->create(['restricted' => true]);
        $project->members()->attach($ionut->id);
        $task = Task::add($project, $ionut, 'Titlurile sunt cam mari');
        Mentions::forget();

        Livewire::actingAs($radu)->test(TaskComments::class, ['task' => $task])
            ->assertSeeHtml('data-mentions="[{&quot;name&quot;:&quot;Ionuț Ciobanu&quot;')->assertDontSee('Dan Străin');   // JSON escape-uit în atribut
    }
}
