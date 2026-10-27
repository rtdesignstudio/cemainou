<?php

namespace Tests\Feature;

use App\Filament\Widgets\ClientPeopleCard;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\ClientPeople;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** „Clienții" pe Azi: oamenii clienților - ce au făcut, ce așteaptă de la noi și de la ei, invitația, primii pași; doar ce vede echipa. */
class ClientPeopleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_team_sees_the_clients_people_with_what_waits_and_what_they_did(): void
    {
        $cip = Client::factory()->create(['name' => 'Auto Nord']);
        $other = Client::factory()->create(['name' => 'Alt client']);
        $radu = User::factory()->create(['name' => 'Radu', 'role' => 'admin']);
        $alex = User::factory()->create(['name' => 'Alex', 'role' => 'lead']);
        $dima = User::factory()->create(['name' => 'Dima', 'role' => 'client', 'client_id' => $cip->id, 'visited_at' => now()->subHour()]);
        $maxim = User::factory()->create(['name' => 'Maxim', 'role' => 'client', 'client_id' => $cip->id, 'visited_at' => null, 'invited_at' => now(), 'invite_accepted_at' => null]);
        User::factory()->create(['name' => 'Strain', 'role' => 'client', 'client_id' => $other->id]);
        $site = Project::factory()->create(['name' => 'Site nou', 'client_id' => $cip->id, 'client_visible' => true, 'lead_id' => $alex->id]);
        Project::factory()->create(['name' => 'Intern', 'client_id' => $other->id, 'restricted' => true]);   // Alex nu-l vede

        $request = Task::add($site, $dima, 'Telefonul din footer e greșit', assignee: $alex);       // cererea lui, fără răspuns: așteaptă de la noi
        $onHim = Task::add($site, $alex, 'Textele pentru pagina Despre', assignee: $dima);          // așteaptă de la el

        $people = ClientPeople::for($alex);
        $this->assertSame(['Dima', 'Maxim'], $people->pluck('user.name')->all());                    // fără clientul altui proiect; cine are ceva care ne așteaptă, primul
        $d = $people->first();
        $this->assertSame([$request->id], $d['waiting']->pluck('task.id')->all());
        $this->assertSame([$onHim->id], $d['on_them']->pluck('id')->all());
        $this->assertStringContainsString('#'.$request->id, $d['last']['text']);
        $this->assertTrue(collect($d['steps'])->firstWhere('label', 'Request')['done']);
        $this->assertTrue($people->last()['never']);

        $this->actingAs($radu);
        $this->assertTrue(ClientPeopleCard::canView());
        Livewire::test(ClientPeopleCard::class)->assertSee(['Clients', 'Dima', 'Waiting for us', 'Waiting for them', 'Telefonul din footer', 'Invited, has not signed in yet', 'Send the invitation again'])
            ->call('resend', $maxim->id)->assertNotified();

        $this->actingAs($alex);
        Livewire::test(ClientPeopleCard::class)->assertDontSee('Send the invitation again');           // doar adminul retrimite
        $this->actingAs($dima);
        $this->assertFalse(ClientPeopleCard::canView());                                             // clientul nu vede nimic din asta
    }
}
