<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Filament\Resources\Tasks\Pages\ViewTask;
use App\Filament\Resources\Updates\Pages\ListUpdates;
use App\Filament\Resources\Updates\Pages\ViewUpdate;
use App\Filament\Resources\Updates\UpdateResource;
use App\Filament\Widgets\Blockers;
use App\Filament\Widgets\LatestUpdates;
use App\Livewire\ProjectKnowledge;
use App\Models\Project;
use App\Models\Task;
use App\Models\Update;
use App\Models\User;
use App\Support\Markdown;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Markdown peste tot: formatarea din rând în rezumate și rânduri, Markdown complet în note; HTML-ul scris rămâne text; fără link în link. */
class MarkdownTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ana = User::factory()->create(['name' => 'Ana']);
        $this->project = Project::factory()->create(['name' => 'Auto Nord']);
    }

    public function test_the_three_forms_are_safe(): void
    {
        $line = 'Am reparat **galeria** în `app.js`, vezi [PR-ul](https://github.com/rt/site/pull/4) <script>x</script> [rău](javascript:alert(1))';

        $inline = (string) Markdown::inline($line);
        $this->assertStringContainsString('<strong>galeria</strong>', $inline);
        $this->assertStringContainsString('<code>app.js</code>', $inline);
        $this->assertStringContainsString('href="https://github.com/rt/site/pull/4"', $inline);
        $this->assertStringContainsString('&lt;script&gt;', $inline);
        $this->assertStringNotContainsString('javascript:', $inline);
        $this->assertStringNotContainsString('<p>', $inline);                                                    // rămâne un rând

        $this->assertStringNotContainsString('<a', (string) Markdown::inline($line, links: false));              // în interiorul unui link
        $this->assertSame('Am reparat galeria în app.js, vezi PR-ul <script>x</script> rău', Markdown::plain($line));
        $this->assertStringContainsString("rândul unu<br>\nrândul doi", (string) Markdown::html("rândul unu\nrândul doi", breaks: true));
    }

    public function test_an_update_page_formats_the_summary_and_the_lines_but_titles_stay_plain(): void
    {
        $update = Update::factory()->for($this->ana)->for($this->project)->create(['summary' => 'Galeria **merge** pe mobil']);
        $update->addItems(['done' => ['Glisarea în `gallery.js`'], 'blocked' => ['Aștept [logo-ul](https://drive.test/logo)']]);

        Livewire::actingAs($this->ana)->test(ViewUpdate::class, ['record' => $update->getRouteKey()])
            ->assertSeeHtml('Galeria <strong>merge</strong> pe mobil')->assertSeeHtml('<code>gallery.js</code>')
            ->assertSeeHtml('href="https://drive.test/logo"');

        $this->assertSame('Galeria merge pe mobil', UpdateResource::getRecordTitle($update));                     // căutare, fir de navigare
        $html = $this->actingAs($this->ana)->get(UpdateResource::getUrl('view', ['record' => $update]))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<title>\s*Galeria merge pe mobil\s*-#', $html);                       // titlul din tab
    }

    public function test_lists_of_updates_format_without_nesting_links(): void
    {
        Update::factory()->for($this->ana)->for($this->project)->create(['summary' => 'Am pus **formularul**, vezi [demo](https://demo.test)']);
        $this->actingAs($this->ana);

        Livewire::test(LatestUpdates::class)->assertSeeHtml('<strong>formularul</strong>')->assertDontSeeHtml('href="https://demo.test"');
        Livewire::test(ListUpdates::class)->assertSeeHtml('<strong>formularul</strong>')->assertDontSeeHtml('href="https://demo.test"');
    }

    public function test_blockers_and_task_notes_are_formatted(): void
    {
        $update = Update::factory()->for($this->ana)->for($this->project)->create();
        $update->addItems(['blocked' => ['Lipsesc datele **SMTP**']]);
        $task = Task::add($this->project, $this->ana, 'Formularul', notes: "Pași:\n\n- [x] câmpurile\n- [ ] trimiterea prin `SMTP`");
        $task->moveTo(TaskStatus::Blocked, $this->ana, reason: 'Aștept datele de la **client**');
        $this->actingAs($this->ana);

        Livewire::test(Blockers::class)->assertSeeHtml('Lipsesc datele <strong>SMTP</strong>');
        Livewire::test(ViewTask::class, ['record' => $task->getRouteKey()])
            ->assertSeeHtml('type="checkbox"')->assertSeeHtml('<code>SMTP</code>')->assertSeeHtml('<strong>client</strong>');
    }

    public function test_team_notes_take_full_markdown_and_keep_line_breaks(): void
    {
        $this->project->notes()->create(['user_id' => $this->ana->id, 'body' => "Accesul la staging:\n- prin VPN\n- contul `deploy`\n<img src=x onerror=alert(1)>", 'source' => 'web']);

        Livewire::actingAs($this->ana)->test(ProjectKnowledge::class, ['project' => $this->project])
            ->assertSeeHtml('<li>prin VPN</li>')->assertSeeHtml('<code>deploy</code>')->assertDontSeeHtml('<img src=x');
    }
}
