<?php

namespace Tests\Feature;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Widgets\ProjectCards;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\Update;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Cardurile proiectelor de pe „Azi": ce se vede, în ce ordine, și cine vede ore, oameni și update-uri interne. */
class ProjectCardsTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'Europe/Chisinau';

    private User $ana;

    private User $ion;

    private Project $site;

    private Project $labels;

    private Project $quiet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-08 15:00', self::TZ));
        $cip = Client::factory()->create(['name' => 'Auto Nord']);
        $this->ana = User::factory()->create(['name' => 'Ana Popescu', 'role' => 'lead', 'timezone' => self::TZ]);
        $this->ion = User::factory()->create(['name' => 'Ion Rusu', 'role' => 'member', 'timezone' => self::TZ]);
        $this->site = Project::factory()->create(['name' => 'Site Nord', 'client_id' => $cip->id, 'client_visible' => true, 'lead_id' => $this->ana->id]);
        $this->labels = Project::factory()->create(['name' => 'Etichete', 'lead_id' => $this->ana->id]);
        $this->quiet = Project::factory()->create(['name' => 'Proiect liniștit']);

        Update::factory()->for($this->ion)->for($this->labels)->create(['summary' => 'Etichetele au plecat la tipar', 'created_at' => now()->subDays(3)]);
        Update::factory()->for($this->quiet, 'project')->for($this->ion)->create(['summary' => 'Demult', 'created_at' => now()->subDays(30)]);
        Update::factory()->for($this->ion)->for($this->site)->create(['summary' => 'Galeria e gata', 'created_at' => now()->subHour()]);
        Update::factory()->for($this->ion)->for($this->site)->create(['summary' => 'Notă internă despre buget', 'is_internal' => true, 'created_at' => now()->subMinutes(30)]);
        Task::factory()->for($this->site)->create(['title' => 'Formularul de contact', 'status' => 'blocked', 'blocked_reason' => 'Lipsesc datele SMTP']);
        Task::factory()->for($this->site)->create(['status' => 'todo']);
        Task::factory()->for($this->site)->create(['status' => 'done', 'done_at' => now()->subDays(2)]);
        foreach ([0, 5, 10] as $m) {
            Activity::create(['user_id' => $this->ion->id, 'project_id' => $this->site->id, 'kind' => 'tool', 'source' => 'claude-code', 'occurred_at' => now()->subMinutes(12 - $m)]);
        }
    }

    public function test_the_team_sees_active_projects_latest_first_with_people_tasks_the_first_blocker_and_hours(): void
    {
        $widget = Livewire::actingAs($this->ion)->test(ProjectCards::class)
            ->assertSeeInOrder(['Site Nord', 'Etichete'])->assertDontSee('Proiect liniștit')
            ->assertSee(['Formularul de contact: Lipsesc datele SMTP', 'Notă internă despre buget', 'IR', 'Working now: Ion Rusu', 'Auto Nord', 'Last 7 days', 'Time invested'])
            ->assertSeeHtml('href="'.ClientResource::getUrl('view', ['record' => $this->site->client_id]).'"');   // clientul de pe card duce la pagina lui
        $site = $widget->instance()->cards()->firstWhere('project.id', $this->site->id);

        $this->assertSame([2, 1, 1], [$site['open'], $site['blocked'], $site['done']]);
        $this->assertTrue($site['people']->first()['now']);
        $this->assertSame(10, $site['minutes']);                                        // și un membru vede orele proiectului
    }

    public function test_the_lead_sees_the_week_hours(): void
    {
        $site = Livewire::actingAs($this->ana)->test(ProjectCards::class)->assertSee(['Last 7 days', 'Time invested'])->instance()->cards()->firstWhere('project.id', $this->site->id);

        $this->assertSame(10, $site['minutes']);
    }

    public function test_the_rhythm_counts_what_was_done_each_day_not_hours(): void
    {
        $site = Livewire::actingAs($this->ion)->test(ProjectCards::class)->instance()->cards()->firstWhere('project.id', $this->site->id);

        $this->assertCount(\App\Support\ProjectCards::DAYS, $site['rhythm']);
        $this->assertSame(2, $site['rhythm'][13]['level']);                             // azi: 2 update-uri + 1 om care a lucrat = 3 (nuanța 2; 4+ e nuanța 3)
        $this->assertStringContainsString('2 updates, 1 person worked', $site['rhythm'][13]['label']);
        $this->assertSame(1, $site['rhythm'][11]['level']);                             // acum 2 zile: o sarcină gata
        $this->assertSame(0, $site['rhythm'][0]['level']);
    }

    public function test_the_client_sees_their_projects_without_people_hours_or_internal_updates(): void
    {
        $maria = User::factory()->create(['name' => 'Maria', 'role' => 'client', 'client_id' => $this->site->client_id, 'timezone' => self::TZ]);

        $site = Livewire::actingAs($maria)->test(ProjectCards::class)
            ->assertSee(['Your projects', 'Site Nord', 'Galeria e gata'])->assertDontSee(['Etichete', 'Notă internă despre buget', 'Working now', 'Time, 7 days'])->assertDontSeeHtml('>IR<')   // inițialele, nu „IR" din id-urile aleatoare
            ->assertDontSeeHtml('href="'.ClientResource::getUrl('view', ['record' => $this->site->client_id]).'"')
            ->instance()->cards()->first();

        $this->assertTrue($site['people']->isEmpty());
        $this->assertNull($site['minutes']);                                            // clientul nu vede orele
        $this->assertSame(1, $site['rhythm'][13]['level']);                             // azi: doar update-ul neintern
    }
}
