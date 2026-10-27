<?php

namespace Tests\Feature;

use App\Filament\Pages\Notifications;
use App\Livewire\Bell;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use App\Support\WebPush;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Centrul de notificări: istoria păstrată, filtre, deschiderea care marchează citită; comentariile pe același subiect într-un rând; clopoțelul cu ultimele 10. */
class NotificationCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_comments_group_the_page_keeps_the_history_and_opening_marks_read(): void
    {
        WebPush::fake();
        $radu = User::factory()->create(['name' => 'Radu Rusu', 'role' => 'admin']);
        $andrei = User::factory()->create(['name' => 'Andrei Moraru']);
        $mihai = User::factory()->create(['name' => 'Mihai Lungu']);
        $task = Task::add(Project::factory()->create(), $radu, 'Audit de securitate', assignee: $andrei);
        foreach ([[$andrei, 'Am actualizat Next.js'], [$mihai, 'Am verificat pe live'], [$andrei, 'Și RLS e închis']] as [$who, $text]) {
            TaskComment::create(['task_id' => $task->id, 'user_id' => $who->id, 'body' => $text, 'source' => 'web']);
        }

        $notes = $radu->notifications()->get();
        $this->assertCount(1, $notes);                                                              // trei comentarii, un rând
        $this->assertSame('3 new comments · Andrei Moraru, Mihai Lungu', $notes->first()->data['title']);
        $this->assertStringContainsString('Și RLS e închis', $notes->first()->data['body']);

        $page = Livewire::actingAs($radu)->test(Notifications::class)->assertSee(['3 new comments', 'Audit de securitate', 'What I receive'])->assertSeeHtml('#notifications')
            ->set('show', 'unread')->assertSee('3 new comments')->set('type', 'mentions')->assertDontSee('3 new comments');
        $page->set('type', '')->call('open', $notes->first()->id)->assertRedirect();
        $this->assertNotNull($notes->first()->fresh()->read_at);
        Livewire::actingAs($radu)->test(Notifications::class)->set('show', 'unread')->assertSee('Nothing unread');

        Livewire::actingAs($radu)->test(Bell::class)->call('clearNotifications');                   // „Șterge tot" nu mai șterge istoria
        $this->assertSame(1, $radu->notifications()->count());
    }
}
