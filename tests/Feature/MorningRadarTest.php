<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use App\Support\MorningSummary;
use App\Support\Radar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Radarul de dimineață al liderului: clienți fără răspuns de 48h, termene fără mișcare, „Gata" fără dovadă; cel mult 5 rânduri. */
class MorningRadarTest extends TestCase
{
    use RefreshDatabase;

    private User $lead;

    private User $dima;

    private Project $site;

    protected function setUp(): void
    {
        parent::setUp();
        $company = Client::factory()->create();
        $this->lead = User::factory()->create(['name' => 'Radu', 'role' => 'lead']);
        $this->dima = User::factory()->create(['name' => 'Dima', 'role' => 'client', 'client_id' => $company->id]);
        $this->site = Project::factory()->create(['name' => 'Site nou', 'client_id' => $company->id, 'client_visible' => true, 'lead_id' => $this->lead->id]);
    }

    public function test_clients_waiting_for_an_answer_for_two_days(): void
    {
        $this->travelTo(now()->subDays(3));
        $request = Task::add($this->site, $this->dima, 'Schimbă logo-ul');                          // cererea lui, neîncepută, fără răspuns
        $answered = Task::add($this->site, $this->dima, 'Fotografii noi');
        TaskComment::create(['task_id' => $answered->id, 'user_id' => $this->lead->id, 'body' => 'Le facem joi']);
        $asked = Task::add($this->site, $this->lead, 'Trimite textele', assignee: $this->dima);
        TaskComment::create(['task_id' => $asked->id, 'user_id' => $this->dima->id, 'body' => 'Care texte?']);   // întrebarea lui, rămasă fără răspuns
        $this->travelBack();
        $fresh = Task::add($this->site, $this->dima, 'Un typo');                                    // de azi: încă nu

        $this->assertSame([$request->id, $asked->id], Radar::clientsWaiting($this->lead)->pluck('id')->all());
        $this->assertStringContainsString("2 clients waiting for an answer for 2+ days: #{$request->id}, #{$asked->id}", implode(' · ', MorningSummary::lines($this->lead)));
        $this->assertSame([], Radar::clientsWaiting(User::factory()->create(['role' => 'lead']))->all());   // alt lider: nu-l privește
        $this->assertNotContains($fresh->id, Radar::clientsWaiting($this->lead)->pluck('id'));
    }

    public function test_deadlines_with_nothing_moving_and_tasks_done_without_proof(): void
    {
        $andrei = User::factory()->create(['name' => 'Andrei', 'role' => 'member']);
        $this->travelTo(now()->subDays(6));
        $due = Task::add($this->site, $this->lead, 'Lansarea', assignee: $andrei);
        $due->forceFill(['due_on' => now()->addDays(9)->toDateString()])->saveQuietly();
        $this->travelBack();
        $this->assertSame(['Site nou'], Radar::dueButStill($this->lead)->pluck('name')->all());

        $bare = Task::add($this->site, $this->lead, 'Meniul pe mobil', assignee: $andrei);
        $bare->moveTo(TaskStatus::Done, $andrei);
        $proven = Task::add($this->site, $this->lead, 'Footer', assignee: $andrei);
        TaskComment::create(['task_id' => $proven->id, 'user_id' => $andrei->id, 'body' => 'Gata, vezi pe staging']);
        $proven->moveTo(TaskStatus::Done, $andrei);
        $mine = Task::add($this->site, $this->lead, 'Factura');
        $mine->moveTo(TaskStatus::Done, $this->lead);                                              // închisă de lider: o știe
        $this->assertSame([$bare->id], Radar::doneWithoutProof($this->lead)->pluck('id')->all());
        $this->assertSame([], Radar::dueButStill($this->lead)->all());                              // sarcinile mișcate azi: proiectul nu mai e nemișcat
    }

    public function test_at_most_five_lines(): void
    {
        $this->travelTo(now()->subDays(20));
        foreach (range(1, 3) as $i) {
            Task::add($this->site, $this->dima, "Cererea {$i}");
            Project::factory()->create(['lead_id' => $this->lead->id]);
        }
        $other = User::factory()->create(['role' => 'member']);
        Task::add($this->site, $this->lead, 'Întârziată', assignee: $other)->forceFill(['due_on' => now()->addDay()->toDateString()])->saveQuietly();
        Task::add($this->site, $this->lead, 'A mea', assignee: $this->lead)->forceFill(['due_on' => now()->addDay()->toDateString()])->saveQuietly();
        Task::add($this->site, $this->lead, 'Blocată', assignee: $other)->forceFill(['status' => 'blocked'])->saveQuietly();
        $this->travelBack();

        $lines = MorningSummary::lines($this->lead);
        $this->assertLessThanOrEqual(MorningSummary::MAX, count($lines));
        $this->assertStringContainsString('task open', $lines[0]);                                  // întâi ale lui
        $this->assertStringContainsString('clients waiting', implode(' · ', $lines));               // apoi radarul, cel mai urgent primul
    }
}
