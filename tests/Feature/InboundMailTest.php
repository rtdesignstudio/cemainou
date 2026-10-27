<?php

namespace Tests\Feature;

use App\Enums\Source;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Attachment;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\InboundMail;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Emailul care devine sarcină: adresa proiectului, cine poate trimite, răspunsurile automate, dublurile, fișierele, pipe-ul și webhook-ul. */
class InboundMailTest extends TestCase
{
    use RefreshDatabase;

    private User $lead;

    private User $dima;

    private Project $site;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Attachment::DISK);
        $admin = User::factory()->create(['role' => 'admin']);
        Settings::save(['inbound_address' => 'tasks@cemainou.test'], $admin);
        $company = Client::factory()->create();
        $this->lead = User::factory()->create(['name' => 'Radu', 'email' => 'radu@rt.test', 'role' => 'lead']);
        $this->dima = User::factory()->create(['name' => 'Dima', 'email' => 'dima@autonord.test', 'role' => 'client', 'client_id' => $company->id]);
        $this->site = Project::factory()->create(['name' => 'Site nou', 'client_id' => $company->id, 'client_visible' => true, 'lead_id' => $this->lead->id]);
    }

    private function mail(string $from, string $to, string $subject, string $body, array $headers = [], ?string $attachment = null): string
    {
        $lines = ["From: Someone <{$from}>", "To: {$to}", "Subject: {$subject}", 'Message-ID: <'.uniqid().'@mail.test>', 'MIME-Version: 1.0', ...$headers];
        if (! $attachment) {
            return implode("\r\n", [...$lines, 'Content-Type: text/plain; charset=UTF-8', '', $body]);
        }

        return implode("\r\n", [...$lines, 'Content-Type: multipart/mixed; boundary="b1"', '', '--b1', 'Content-Type: text/plain; charset=UTF-8', '', $body,
            '--b1', 'Content-Type: application/pdf; name="brief.pdf"', 'Content-Disposition: attachment; filename="brief.pdf"', 'Content-Transfer-Encoding: base64', '',
            base64_encode($attachment), '--b1--']);
    }

    public function test_a_client_email_to_the_project_address_becomes_a_request_for_the_lead_with_its_file(): void
    {
        $address = InboundMail::addressFor($this->site);
        $this->assertMatchesRegularExpression('/^tasks\+[a-z0-9]{12}@cemainou\.test$/', $address);

        $task = InboundMail::receive($this->mail('Dima@AutoNord.test', $address, 'Fwd: Textele pentru pagina Despre', "Le găsești atașate.\n\n-- \nDima Toma\nAuto Nord", attachment: '%PDF-1.4 brief'));
        $this->assertNotNull($task);
        $this->assertSame(['Textele pentru pagina Despre', 'Le găsești atașate.', $this->dima->id, $this->lead->id, Source::Email],
            [$task->title, $task->notes, $task->created_by, $task->assignee_id, $task->source]);
        $this->assertSame(['brief.pdf'], $task->attachments()->pluck('name')->all());
        $this->assertTrue($this->lead->notifications()->exists());                                         // liderul află, ca la orice cerere
    }

    public function test_strangers_other_projects_automatic_replies_spoofs_and_duplicates_are_ignored(): void
    {
        $address = InboundMail::addressFor($this->site);
        $other = Project::factory()->create(['name' => 'Alt client']);
        $this->assertNull(InboundMail::receive($this->mail('cineva@spam.test', $address, 'Oferta', 'Cumpără')));     // fără cont
        $this->assertNull(InboundMail::receive($this->mail('dima@autonord.test', InboundMail::addressFor($other), 'Ceva', 'x')));   // proiectul nu e al lui
        $this->assertNull(InboundMail::receive($this->mail('dima@autonord.test', 'tasks@cemainou.test', 'Fără cheie', 'x')));
        $this->assertNull(InboundMail::receive($this->mail('dima@autonord.test', $address, 'Out of office', 'Revin luni', ['Auto-Submitted: auto-replied'])));
        $this->assertNull(InboundMail::receive($this->mail('dima@autonord.test', $address, 'Fals', 'x', ['Authentication-Results: mx.test; spf=fail; dkim=none; dmarc=fail'])));

        $raw = $this->mail('radu@rt.test', $address, 'O dată', 'Doar o dată');
        $this->assertNotNull(InboundMail::receive($raw));
        $this->assertNull(InboundMail::receive($raw));                                                     // același Message-ID: o singură sarcină
        $this->assertSame(1, Task::count());
        $this->assertStringStartsWith('ignored: already received', InboundMail::recent()[0]['result']);   // în Setări: ce s-a întâmplat cu fiecare
        $this->assertStringStartsWith('task #', InboundMail::recent()[1]['result']);

        Settings::save(['inbound_address' => null], User::factory()->create(['role' => 'admin']));
        $this->assertNull(InboundMail::addressFor($this->site));                                            // oprit: nicio adresă, nimic primit
        $this->assertNull(InboundMail::receive($this->mail('radu@rt.test', $address, 'Altul', 'x')));
    }

    public function test_the_pipe_command_and_the_webhook(): void
    {
        $address = InboundMail::addressFor($this->site);
        $this->postJson('/api/inbound/mail/'.str_repeat('0', 32), ['raw' => $this->mail('radu@rt.test', $address, 'Webhook', 'x')])->assertNotFound();
        $this->postJson('/api/inbound/mail/'.InboundMail::token(), ['raw' => $this->mail('radu@rt.test', $address, 'Webhook', 'x')])->assertOk()->assertJsonPath('ok', true);
        $this->postJson('/api/inbound/mail/'.InboundMail::token(), ['raw' => $this->mail('nimeni@x.test', $address, 'Necunoscut', 'x')])->assertOk()->assertJsonPath('task', null);
        $this->assertSame(['Webhook'], Task::pluck('title')->all());

        $this->actingAs($this->dima)->get(ProjectResource::getUrl('view', ['record' => $this->site]))->assertSee($address);
    }
}
