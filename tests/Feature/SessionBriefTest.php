<?php

namespace Tests\Feature;

use App\Enums\ActivityKind;
use App\Enums\Source;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\ProjectDay;
use App\Models\Task;
use App\Models\Update;
use App\Models\User;
use App\Models\WorkSession;
use App\Support\AgentSessions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Ce era /brief, de la sine la pornirea sesiunii: sarcina în lucru, blocajele, deciziile noi ale colegilor, zilele neraportate. */
class SessionBriefTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_session_start_brings_what_matters_without_a_command(): void
    {
        $ion = User::factory()->create(['name' => 'Ion', 'role' => 'member', 'timezone' => 'Europe/Chisinau']);
        $ana = User::factory()->create(['name' => 'Ana', 'role' => 'lead']);
        $site = Project::factory()->create(['name' => 'Site Nord', 'slug' => 'site-nord']);

        $earlier = WorkSession::create(['user_id' => $ion->id, 'project_id' => $site->id, 'agent_session_id' => 'old', 'source' => 'claude-code', 'started_at' => now()->subDays(2)]);
        $gallery = Task::add($site, $ana, 'Galeria foto', assignee: $ion);
        $gallery->moveTo(TaskStatus::Doing, $ion);
        Task::add($site, $ana, 'Formularul', assignee: $ana)->moveTo(TaskStatus::Blocked, $ana, 'Lipsesc datele SMTP');
        ProjectDay::create(['user_id' => $ion->id, 'project_id' => $site->id, 'date' => now('Europe/Chisinau')->subDay()->toDateString(), 'active_minutes' => 90, 'agent_minutes' => 60]);

        $session0 = AgentSessions::touch($ion, 'first', ActivityKind::SessionStart, Source::ClaudeCode, null, 'site-nord');
        $this->assertStringNotContainsString('without reporting', AgentSessions::contextLine($session0->fresh()));   // n-a raportat niciodată aici: nu i se propune
        Update::factory()->for($ion)->for($site)->create(['created_at' => now()->subDays(6)]);                      // a dat odată /upd pe proiect
        $decision = Update::factory()->for($ana)->for($site)->create(['created_at' => now()->addMinute()]);          // o decizie a Anei, după sesiunea lui de mai devreme
        $decision->items()->create(['type' => 'decision', 'text' => 'Hârtie texturată, fără folio', 'position' => 0]);

        $session = AgentSessions::touch($ion, 'abc', ActivityKind::SessionStart, Source::ClaudeCode, null, 'site-nord');
        $line = AgentSessions::contextLine($session->fresh());

        $this->assertStringContainsString("#{$gallery->id} \"Galeria foto\" in progress here", $line);
        $this->assertStringContainsString('call start_task with id '.$gallery->id, $line);
        $this->assertStringContainsString('1 task is blocked', $line);
        $this->assertStringContainsString('Hârtie texturată', $line);
        $this->assertStringContainsString('without reporting', $line);

        Update::factory()->for($ion)->for($site)->create(['created_at' => now()->subDay()]);           // raportată: nu mai e propusă
        $this->assertStringNotContainsString('without reporting', AgentSessions::contextLine($session->fresh()));
        $this->assertNotNull($earlier);
    }
}
