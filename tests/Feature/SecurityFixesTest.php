<?php

namespace Tests\Feature;

use App\Enums\ActivityKind;
use App\Enums\Source;
use App\Filament\Pages\Shared;
use App\Filament\Resources\Feedback\Pages\ViewFeedback;
use App\Models\Feedback;
use App\Models\Project;
use App\Models\User;
use App\Support\AgentSessions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** Reparațiile din auditul de securitate (21 oct. 2026): fiecare cu scenariul de atac de atunci. */
class SecurityFixesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_shared_id_from_the_address_cannot_delete_other_files(): void
    {
        Storage::fake('data');
        Storage::disk('data')->put('projects/42/contract.pdf', '%PDF');
        $dan = User::factory()->create(['role' => 'collaborator']);
        $project = Project::factory()->create(['lead_id' => $dan->id]);

        Livewire::actingAs($dan)->withQueryParams(['id' => '..'])->test(Shared::class)
            ->set('text', 'o idee')->set('project', $project->id)->call('save');
        $this->assertTrue(Storage::disk('data')->exists('projects/42/contract.pdf'));                 // „shared/.." ar fi șters tot data/files
    }

    public function test_a_javascript_page_link_in_feedback_is_dropped_and_never_rendered(): void
    {
        $ion = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $bad = Feedback::submit($ion, 'Butonul nu merge', ['url' => 'javascript:fetch("//evil.test")//projects/12']);
        $this->assertNull($bad->url);
        $good = Feedback::submit($ion, 'Altul', ['url' => 'https://cemainou.test/projects/12']);
        $this->assertSame('https://cemainou.test/projects/12', $good->url);

        $bad->forceFill(['url' => 'javascript:alert(1)'])->save();                                    // unul vechi, salvat înainte de reparație
        Livewire::actingAs($admin)->test(ViewFeedback::class, ['record' => $bad->id])->assertDontSeeHtml('href="javascript:');
    }

    public function test_an_agent_session_does_not_open_a_project_the_person_cannot_see(): void
    {
        $dan = User::factory()->create(['role' => 'collaborator']);
        $secret = Project::factory()->create(['name' => 'Ofertă internă', 'slug' => 'oferta-interna', 'restricted' => true]);
        $session = AgentSessions::touch($dan, 'sess-1', ActivityKind::Prompt, Source::ClaudeCode, null, 'oferta-interna');
        $this->assertNull($session->project_id);                                                    // un slug ghicit nu leagă sesiunea (și nu-i dă contextul) unui proiect restrâns
    }
}
