<?php

namespace Tests\Feature;

use App\Filament\Resources\Projects\Pages\CreateProject;
use App\Filament\Resources\Projects\Pages\EditProject;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Un repo aparține unui singur proiect, ca agentul să nu aleagă la întâmplare între două. */
class UniqueRepoTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_form_refuses_a_repo_already_used_however_it_is_written(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        Project::factory()->create(['name' => 'Site Studio Nord', 'repo_url' => 'git@github.com:rt/studio-nord.git']);

        Livewire::test(CreateProject::class)
            ->fillForm(['name' => 'Altul', 'client_id' => Client::factory()->create()->id, 'repo_url' => 'https://github.com/rt/studio-nord'])
            ->call('create')
            ->assertHasFormErrors(['repo_url']);
    }

    public function test_a_project_keeps_its_own_repo_when_edited(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $project = Project::factory()->create(['repo_url' => 'git@github.com:rt/studio-nord.git']);

        Livewire::test(EditProject::class, ['record' => $project->getRouteKey()])
            ->fillForm(['repo_url' => 'https://github.com/rt/studio-nord.git'])
            ->call('save')
            ->assertHasNoFormErrors();
    }

    public function test_the_database_refuses_duplicates_too(): void
    {
        Project::factory()->create(['repo_url' => 'git@github.com:rt/studio-nord.git']);

        $this->expectException(UniqueConstraintViolationException::class);
        Project::factory()->create(['repo_url' => 'https://github.com/rt/studio-nord']);
    }
}
