<?php

namespace Tests\Feature;

use App\Enums\FeedbackStatus;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Feedback\FeedbackResource;
use App\Filament\Resources\Feedback\Pages\ListFeedback;
use App\Filament\Resources\Feedback\Pages\ViewFeedback;
use App\Livewire\FeedbackButton;
use App\Models\Client;
use App\Models\Feedback;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** Feedback-ul despre whatsupai: butonul din dreapta jos (echipa), cu pagina, ecranul și fișierele; adminul îl vede deschis sau rezolvat. */
class FeedbackTest extends TestCase
{
    use RefreshDatabase;

    private User $radu;

    private User $ion;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('data');
        $this->radu = User::factory()->create(['name' => 'Radu', 'role' => 'admin']);
        $this->ion = User::factory()->create(['name' => 'Ion Rusu', 'role' => 'member']);
    }

    private function send(User $by, string $body = 'Butonul „Iau eu” nu se vede pe telefon', array $files = []): void
    {
        Livewire::actingAs($by)->test(FeedbackButton::class)->set('data.body', $body)->set('files', $files)
            ->call('send', ['url' => 'https://echipa.example.test/tasks?tab=free', 'viewport' => '390×844', 'screen' => '1170×2532 @3x'])->assertHasNoErrors();
    }

    public function test_the_team_sends_feedback_with_the_page_the_screen_and_files(): void
    {
        $this->send($this->ion, files: [UploadedFile::fake()->image('captura.png', 390, 844), UploadedFile::fake()->create('ecran.mp4', 500, 'video/mp4')]);

        $feedback = Feedback::sole();
        $this->assertSame(['https://echipa.example.test/tasks?tab=free', '390×844', '1170×2532 @3x', FeedbackStatus::Open], [$feedback->url, $feedback->viewport, $feedback->screen, $feedback->status]);
        $this->assertCount(2, $feedback->files);
        Storage::disk('data')->assertExists($feedback->files[0]['path']);
        $this->assertStringContainsString('Ion Rusu sent feedback', $this->radu->notifications()->sole()->data['title']);   // adminul, în clopoțel
    }

    public function test_only_files_from_a_phone_are_accepted(): void
    {
        Livewire::actingAs($this->ion)->test(FeedbackButton::class)->set('data.body', 'X')->set('files', [UploadedFile::fake()->create('pagina.html', 2, 'text/html')])
            ->call('send', [])->assertHasErrors('files.0');
        Livewire::actingAs($this->ion)->test(FeedbackButton::class)->call('send', [])->assertHasErrors('data.body');
    }

    public function test_the_button_is_for_the_team_not_the_client(): void
    {
        $maria = User::factory()->create(['role' => 'client', 'client_id' => Client::factory()->create()->id]);

        $this->actingAs($this->ion)->get(Dashboard::getUrl())->assertOk()->assertSee('wsa-feedback', false);              // din „+"
        $this->actingAs($maria)->get(Dashboard::getUrl())->assertOk()->assertDontSee('id="wsa-feedback"', false)->assertDontSee('Feedback about cemainou');
    }

    public function test_the_admin_resolves_it_and_the_author_is_told(): void
    {
        $this->send($this->ion, files: [UploadedFile::fake()->image('captura.png')]);
        $feedback = Feedback::sole();

        Livewire::actingAs($this->radu)->test(ListFeedback::class)->assertCanSeeTableRecords([$feedback])
            ->callTableAction('resolve', $feedback, ['resolution' => 'Butonul e acum vizibil pe telefon.']);
        $this->assertSame(FeedbackStatus::Resolved, $feedback->fresh()->status);
        $this->assertStringContainsString('Butonul e acum vizibil', $this->ion->notifications()->sole()->data['body']);
        $this->assertSame(FeedbackResource::getUrl('view', ['record' => $feedback]), collect($this->ion->notifications()->sole()->data['actions'] ?? [])->pluck('url')->filter()->first());   // „Deschide” duce la feedback, nu la pagina de unde a fost trimis
        Livewire::actingAs($this->radu)->test(ListFeedback::class)->assertCanNotSeeTableRecords([$feedback]);          // tab-ul „Deschise”
        Livewire::actingAs($this->radu)->test(ViewFeedback::class, ['record' => $feedback->id])->assertSee(['Butonul e acum vizibil pe telefon.', '390×844'])
            ->callAction('reopen');
        $this->assertTrue($feedback->fresh()->isOpen());
    }

    public function test_tables_show_50_rows_and_offer_250_and_500(): void
    {
        $list = Livewire::actingAs($this->radu)->test(ListFeedback::class)->assertSet('tableRecordsPerPage', 50);
        $this->assertSame([50, 250, 500], $list->instance()->getTable()->getPaginationPageOptions());
    }

    public function test_only_admins_see_the_list_and_files_go_to_the_admin_and_the_author(): void
    {
        $this->send($this->ion, files: [UploadedFile::fake()->image('captura.png')]);
        $feedback = Feedback::sole();
        $ana = User::factory()->create(['role' => 'lead']);

        $this->actingAs($ana)->get(FeedbackResource::getUrl('index'))->assertForbidden();
        $this->actingAs($ana)->get(FeedbackResource::getUrl('view', ['record' => $feedback]))->assertForbidden();   // al altcuiva: nu
        $this->actingAs($this->ion)->get(FeedbackResource::getUrl('view', ['record' => $feedback]))->assertOk();     // autorul își vede feedback-ul (din notificare)
        $this->actingAs($this->ion)->get(FeedbackResource::getUrl('index'))->assertForbidden();                       // dar nu lista
        $this->actingAs($ana)->get(route('feedback.file', [$feedback, 0]))->assertForbidden();
        $this->actingAs($this->ion)->get(route('feedback.file', [$feedback, 0]))->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->actingAs($this->radu)->get(route('feedback.file', [$feedback, 0]))->assertOk();
        $this->actingAs($this->radu)->get(route('feedback.file', [$feedback, 5]))->assertNotFound();
    }
}
