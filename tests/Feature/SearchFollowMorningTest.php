<?php

namespace Tests\Feature;

use App\Actions\LogUpdate;
use App\Filament\GlobalSearch\EverythingSearch;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Models\Client;
use App\Models\Document;
use App\Models\Project;
use App\Models\Task;
use App\Models\Update;
use App\Models\User;
use App\Support\MorningSummary;
use App\Support\WebPush;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

/** Căutarea găsește și în rânduri, documente, notițe, comentarii; urmărirea proiectelor și rezumatul de dimineață; reperele din cronologia proiectului. */
class SearchFollowMorningTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'Europe/Chisinau';

    public function test_search_finds_lines_details_documents_notes_and_comments_for_the_team_only(): void
    {
        $ion = User::factory()->create(['name' => 'Ion']);
        $site = Project::factory()->create(['name' => 'Site']);
        $update = app(LogUpdate::class)->create($ion, $site, ['summary' => 'Ziua'], ['done' => ['Formularul trimite prin SMTP-ul nou']]);
        Task::add($site, $ion, 'Emailurile', 'Folosim SMTP-ul de la hosting');
        Document::start($site, $ion, 'Brief', 'Serverul SMTP e la client.');
        $site->notes()->create(['user_id' => $ion->id, 'body' => 'Parola SMTP e în 1Password', 'source' => 'web']);
        $update->comments()->create(['user_id' => $ion->id, 'body' => 'Merge SMTP-ul acum?', 'source' => 'web']);

        $this->actingAs($ion);
        $categories = (new EverythingSearch)->getResults('SMTP')->getCategories();
        foreach (['Lines in updates', 'Task details', 'Documents', 'Notes', 'Comments'] as $label) {
            $this->assertArrayHasKey($label, $categories->all());
        }

        $this->actingAs(User::factory()->create(['role' => 'client', 'client_id' => Client::factory()->create()->id]));
        $this->assertArrayNotHasKey('Notes', (new EverythingSearch)->getResults('SMTP')->getCategories()->all());
    }

    public function test_following_a_project_and_the_morning_summary_once_a_day(): void
    {
        WebPush::fake();
        $this->travelTo(Carbon::parse('2026-10-14 07:30', self::TZ));
        $ana = User::factory()->create(['name' => 'Ana', 'timezone' => self::TZ]);
        $ion = User::factory()->create(['name' => 'Ion', 'timezone' => self::TZ]);
        $site = Project::factory()->create(['name' => 'Site']);
        Livewire::actingAs($ana)->test(ViewProject::class, ['record' => $site->id])->callAction('follow');
        $this->assertTrue($ana->followedProjects()->whereKey($site->id)->exists());
        Task::add($site, $ion, 'Galeria', assignee: $ana, due: '2026-10-14');
        Task::factory()->for($site)->create(['status' => 'blocked', 'blocked_reason' => 'SMTP']);
        Update::factory()->for($ion)->for($site)->create(['created_at' => Carbon::parse('2026-10-13 17:00', self::TZ)->utc()]);

        MorningSummary::sendIfDue();
        $this->assertSame(0, $ana->notifications()->where('data->title', 'like', '%Good morning%')->count());   // înainte de 8: nimic

        $this->travelTo(Carbon::parse('2026-10-14 08:30', self::TZ));
        Cache::forget('cemainou.morning.check');
        MorningSummary::sendIfDue();
        $morning = $ana->notifications()->get()->first(fn ($n) => str_contains($n->data['title'], 'Good morning'));
        $this->assertNotNull($morning);
        foreach (['1 task open', '1 due today', '1 new for you', '1 blocker', '1 update yesterday'] as $part) {
            $this->assertStringContainsString($part, $morning->data['body']);
        }

        Cache::forget('cemainou.morning.check');
        MorningSummary::sendIfDue();                                                                          // o dată pe zi
        $this->assertSame(1, $ana->notifications()->get()->filter(fn ($n) => str_contains($n->data['title'], 'Good morning'))->count());
    }

    public function test_the_project_timeline_marks_tasks_done_and_ideas(): void
    {
        $ion = User::factory()->create(['role' => 'admin']);
        $site = Project::factory()->create();
        Update::factory()->for($ion)->for($site)->create();
        Task::factory()->for($site)->create(['title' => 'Galeria', 'status' => 'done', 'done_at' => now(), 'assignee_id' => $ion->id]);
        Task::add($site, $ion, 'Mod întunecat', idea: true);

        Livewire::actingAs($ion)->test(ViewProject::class, ['record' => $site->id])->assertSee(['«Galeria» done', 'Idea: Mod întunecat']);
    }
}
