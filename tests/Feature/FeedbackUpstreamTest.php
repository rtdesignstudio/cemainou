<?php

namespace Tests\Feature;

use App\Filament\Resources\Feedback\FeedbackResource;
use App\Models\Feedback;
use App\Models\User;
use App\Support\FeedbackUpstream;
use App\Support\Housekeeping;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Feedback-ul centralizat: o instalare care a ales își trimite feedback-ul despre cemainou la RT, fără numele sau emailul omului; la RT el apare
 * la Feedback cu numărul instalării. Oprit implicit; pe instalările care nu primesc, adresa de primire nu există.
 */
class FeedbackUpstreamTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('data');
        config(['cemainou.feedback_upstream' => 'https://rt.test/api/feedback/upstream', 'cemainou.feedback_inbox' => false]);
        $this->admin = User::factory()->create(['role' => 'admin', 'name' => 'Ana Popescu', 'email' => 'ana@firma.test']);
    }

    public function test_nothing_leaves_until_the_admin_chooses(): void
    {
        Http::fake();
        Feedback::submit($this->admin, 'Butonul nu merge', ['url' => 'https://echipa.firma.test/tasks']);

        Http::assertNothingSent();
        $this->assertFalse(FeedbackUpstream::enabled());
    }

    public function test_once_chosen_it_goes_without_name_email_or_address_and_unsent_ones_are_retried(): void
    {
        $this->withoutDefer();                                                      // în aplicație, trimiterea pleacă după răspuns
        Settings::save(['feedback_to_rt' => true], $this->admin);
        Http::fake(['rt.test/*' => Http::sequence()->push('', 500)->push(['ok' => true], 201)]);

        $feedback = Feedback::submit($this->admin, 'Butonul nu merge', ['url' => 'https://echipa.firma.test/tasks/5?x=1', 'viewport' => '390x844'],
            [UploadedFile::fake()->image('ecran.png')]);

        app(Housekeeping::class)->run();
        $this->assertNotNull($feedback->fresh()->sent_to_rt_at);

        Http::assertSent(function (ClientRequest $request) {
            $body = $request->body();

            return str_contains($body, 'Butonul nu merge') && str_contains($body, '/tasks/5') && str_contains($body, 'ecran.png')
                && ! str_contains($body, 'Ana Popescu') && ! str_contains($body, 'ana@firma.test') && ! str_contains($body, 'echipa.firma.test');
        });
    }

    public function test_rt_receives_it_without_an_author_and_shows_the_installation(): void
    {
        $this->post(route('feedback.upstream'), ['body' => 'x', 'installation' => '7c9e6679-7425-40de-944b-e07fc1f90ae7'])->assertNotFound();   // instalările obișnuite nu primesc

        config(['cemainou.feedback_inbox' => true]);
        $this->postJson(route('feedback.upstream'), ['body' => 'Lipsește ceva'])->assertUnprocessable();
        $this->post(route('feedback.upstream'), ['body' => 'Calendarul e greu de citit', 'installation' => '7c9e6679-7425-40de-944b-e07fc1f90ae7', 'version' => '0.1.250',
            'path' => '/tasks', 'files' => [UploadedFile::fake()->image('a.png')]])->assertCreated();

        $feedback = Feedback::latest('id')->first();
        $this->assertSame([null, '7c9e6679-7425-40de-944b-e07fc1f90ae7', '0.1.250', 'Installation 7c9e6679'], [$feedback->user_id, $feedback->installation, $feedback->app_version, $feedback->from()]);
        $this->assertCount(1, $feedback->files);
        $this->assertFalse(FeedbackUpstream::enabled());                            // RT nu-și trimite singur feedback-ul

        $this->actingAs($this->admin)->get(FeedbackResource::getUrl('view', ['record' => $feedback]))->assertOk()->assertSee(['Installation 7c9e6679', 'Calendarul e greu de citit']);
        $this->assertSame(1, $this->admin->unreadNotifications()->count());          // adminii RT îl primesc în clopoțel
    }
}
