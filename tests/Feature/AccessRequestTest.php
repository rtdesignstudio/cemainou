<?php

namespace Tests\Feature;

use App\Filament\Pages\Downloads;
use App\Mail\DownloadLinkMail;
use App\Models\DownloadRequest;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Descărcarea de pe pagina publică: omul își lasă datele și primește pe loc linkul (în răspuns și pe email); datele rămân la Descărcări,
 * cu alegerea lui despre vești; o întrebare devine sarcină. Roboții și celelalte instalări nu ajung nicăieri.
 */
class AccessRequestTest extends TestCase
{
    use RefreshDatabase;

    private array $form = ['name' => 'Ion Ceban', 'email' => 'Ion@Firma.test', 'company' => 'Firma Demo', 'team' => '6-15', 'lang' => 'ro'];

    private function turnOn(): User
    {
        $radu = User::factory()->create(['role' => 'admin']);
        Project::factory()->create(['slug' => 'cereri-acces', 'lead_id' => $radu->id]);
        config(['cemainou.access_requests' => 'cereri-acces']);
        Settings::save(['mail_mailer' => 'smtp', 'mail_host' => 'smtp.rt.test', 'mail_from_address' => 'cemainou@rt.test'], $radu);

        return $radu;
    }

    public function test_the_link_comes_at_once_and_by_email_and_the_details_stay_with_the_choice_about_news(): void
    {
        Mail::fake();
        $this->withoutDefer();
        $this->post(route('access.request'), $this->form)->assertNotFound();      // instalările obișnuite nu primesc
        $radu = $this->turnOn();

        $this->postJson(route('access.request'), ['name' => 'X'])->assertUnprocessable();
        $this->post(route('access.request'), $this->form + ['website' => 'http://spam.test'])->assertCreated();   // capcana pentru roboți
        $this->assertSame(0, DownloadRequest::count());

        $this->post(route('access.request'), $this->form, ['Origin' => 'https://rt.md'])->assertCreated()->assertHeader('Access-Control-Allow-Origin')
            ->assertJsonPath('download', 'https://rt.md/cemainou/releases/cemainou-'.config('cemainou.version').'.zip');
        $download = DownloadRequest::sole();
        $this->assertSame(['ion@firma.test', false, config('cemainou.version')], [$download->email, $download->wants_updates, $download->version]);   // nebifat: fără vești
        $this->assertSame(0, Task::count());                                       // fără întrebare, fără sarcină
        Mail::assertSent(DownloadLinkMail::class, fn (DownloadLinkMail $m) => $m->hasTo('ion@firma.test') && $m->locale === 'ro');

        $this->post(route('access.request'), ['name' => 'Ana', 'email' => 'ana@alta.test', 'updates' => '1', 'message' => 'Suntem 9: cât costă licența?', 'lang' => 'en'])->assertCreated();
        $this->assertSame(['Întrebare de pe pagina cemainou: Ana', $radu->id], [Task::sole()->title, Task::sole()->assignee_id]);

        $this->actingAs($radu)->get(Downloads::getUrl())->assertOk()->assertSee(['Ion Ceban', 'Firma Demo', 'ana@alta.test', 'cât costă licența']);
        $csv = $this->get(route('downloads.export'))->assertOk()->streamedContent();
        $this->assertStringContainsString('ana@alta.test', $csv);
        $this->assertStringNotContainsString('ion@firma.test', $csv);              // doar cine a ales veștile
        $this->actingAs(User::factory()->create(['role' => 'lead']))->get(route('downloads.export'))->assertForbidden();
    }

    public function test_the_email_alone_is_enough_and_the_mail_greets_without_a_name(): void
    {
        Mail::fake();
        $this->withoutDefer();
        $this->turnOn();

        $this->postJson(route('access.request'), ['email' => 'nu-e-email'])->assertUnprocessable();
        $this->postJson(route('access.request'), ['email' => 'Dev@Echipa.test', 'lang' => 'en'])->assertCreated();
        $this->assertSame(['dev@echipa.test', ''], [DownloadRequest::sole()->email, DownloadRequest::sole()->name]);
        Mail::assertSent(DownloadLinkMail::class, fn (DownloadLinkMail $m) => str_contains($m->render(), 'Hello, thank you for trying cemainou'));

        $this->postJson(route('access.request'), ['email' => 'q@echipa.test', 'message' => 'Suntem 12?'])->assertCreated();
        $this->assertSame('Întrebare de pe pagina cemainou: q@echipa.test', Task::sole()->title);
    }
}
