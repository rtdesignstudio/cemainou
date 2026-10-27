<?php

namespace Tests\Feature;

use App\Mcp\Servers\CemainouServer;
use App\Mcp\Tools\DeleteCommentTool;
use App\Mcp\Tools\StartTaskTool;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\Update;
use App\Models\UpdateComment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** delete_comment: omul își șterge din agent comentariile lui, cu regulile de pe web (la sarcină autorul; la update autorul sau adminul). */
class DeleteCommentTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    private User $ion;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ana = User::factory()->create(['name' => 'Ana', 'role' => 'member']);
        $this->ion = User::factory()->create(['name' => 'Ion', 'role' => 'member']);
        $this->task = Task::add(Project::factory()->create(), $this->ana, 'Galeria', assignee: $this->ana);
    }

    private function remove(User $as, int $id, string $on = 'task')
    {
        return CemainouServer::actingAs($as)->tool(DeleteCommentTool::class, ['id' => $id, 'on' => $on]);
    }

    public function test_the_author_sees_the_ids_of_their_comments_and_deletes_one(): void
    {
        $mine = $this->task->comments()->create(['user_id' => $this->ana->id, 'body' => 'Am făcut machetele', 'source' => 'web']);
        $theirs = $this->task->comments()->create(['user_id' => $this->ion->id, 'body' => 'Super', 'source' => 'web']);

        CemainouServer::actingAs($this->ana)->tool(StartTaskTool::class, ['id' => $this->task->id])->assertOk()->assertSee(['"id":'.$mine->id, '"mine":true']);

        $this->remove($this->ana, $mine->id)->assertOk()->assertSee('Deleted comment #'.$mine->id);
        $this->remove($this->ana, $theirs->id)->assertHasErrors()->assertSee('only its author');
        $this->assertSame([$theirs->id], TaskComment::pluck('id')->all());
    }

    public function test_on_an_update_an_admin_may_delete_too_and_unseen_ones_are_hidden(): void
    {
        $update = Update::factory()->create(['user_id' => $this->ana->id, 'project_id' => $this->task->project_id]);
        $comment = UpdateComment::create(['update_id' => $update->id, 'user_id' => $this->ion->id, 'body' => 'Întrebare', 'source' => 'web']);

        $this->remove($this->ana, $comment->id, 'update')->assertHasErrors();
        $this->remove(User::factory()->create(['role' => 'admin']), $comment->id, 'update')->assertOk();
        $this->assertSame(0, UpdateComment::count());
        $this->remove($this->ana, 9999)->assertHasErrors()->assertSee('There is no comment');
    }
}
