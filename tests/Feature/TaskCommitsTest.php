<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Filament\Resources\Tasks\Pages\ViewTask;
use App\Mcp\Servers\CemainouServer;
use App\Mcp\Tools\LogUpdateTool;
use App\Mcp\UpdateFlow;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskCommit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Commit-urile legate de sarcini: „#12" în mesaj apare pe sarcină; „fixes #12" face ca /upd să propună închiderea ei. */
class TaskCommitsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_numbers_in_a_message_are_found_with_the_ones_it_closes(): void
    {
        $this->assertSame([12 => false], TaskCommit::refs('Galeria pe mobil (#12)'));
        $this->assertSame([12 => true, 14 => false], TaskCommit::refs('fixes #12, vezi și #14'));
        $this->assertSame([7 => true], TaskCommit::refs('Rezolvă #7: formularul'));
        $this->assertSame([], TaskCommit::refs('culoarea &#12; și a/#3 nu sunt sarcini'));
    }

    public function test_commits_from_the_update_show_on_the_task_and_open_fixed_ones_are_proposed(): void
    {
        $ana = User::factory()->create(['name' => 'Ana', 'role' => 'lead']);
        $site = Project::factory()->create(['name' => 'Auto Nord', 'slug' => 'auto-nord', 'lead_id' => $ana->id, 'repo_url' => 'https://github.com/rt/nord']);
        $other = Project::factory()->create(['slug' => 'alt']);
        $gallery = Task::add($site, $ana, 'Galeria pe mobil', assignee: $ana);
        $form = Task::add($site, $ana, 'Formularul de contact', assignee: $ana);
        $elsewhere = Task::add($other, $ana, 'Pe alt proiect');

        $response = CemainouServer::actingAs($ana)->tool(LogUpdateTool::class, ['project' => 'auto-nord', 'summary' => 'Galeria și formularul', 'done' => ['Galeria merge pe mobil'],
            'task_changes' => [['id' => $gallery->id, 'status' => 'done', 'proof' => 'a1b2c3d']],
            'commits' => [
                ['hash' => 'a1b2c3d4e5', 'subject' => "Glisarea în galerie, fixes #{$gallery->id}", 'date' => '2026-10-18'],
                ['hash' => 'b2c3d4e5f6', 'subject' => "Formularul trimite, closes #{$form->id}", 'date' => '2026-10-18'],
                ['hash' => 'c3d4e5f6a7', 'subject' => "Pomenește #{$elsewhere->id} de pe alt proiect"],
            ]]);
        $response->assertOk()->assertSee('"commits_linked":2')->assertSee('Formularul de contact')->assertDontSee('"title":"Galeria pe mobil"');

        $this->assertSame(2, TaskCommit::count());
        $this->assertSame(0, $elsewhere->commits()->count());                                   // doar sarcinile proiectului update-ului
        $this->assertTrue($form->commits()->sole()->closes);
        $this->assertSame(TaskStatus::Todo, $form->fresh()->status);                             // nu se închide singură: se propune

        CemainouServer::actingAs($ana)->tool(LogUpdateTool::class, ['project' => 'auto-nord', 'summary' => 'Din nou', 'done' => ['Ceva'],
            'commits' => [['hash' => 'a1b2c3d4e5', 'subject' => "Glisarea în galerie, fixes #{$gallery->id}"]]])->assertOk();
        $this->assertSame(2, TaskCommit::count());                                              // același commit, o singură dată

        Livewire::actingAs($ana)->test(ViewTask::class, ['record' => $form->getRouteKey()])
            ->assertSee(['b2c3d4e', 'Formularul trimite', 'closes it', 'https://github.com/rt/nord/commit/b2c3d4e5f6']);
        $this->assertStringContainsString('fixes, closes or resolves #N', implode("\n", UpdateFlow::nextSteps()));
    }
}
