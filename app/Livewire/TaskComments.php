<?php

namespace App\Livewire;

use App\Enums\Source;
use App\Models\Task;
use App\Models\User;
use App\Support\Editor;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/** Comentariile unei sarcini (clarificări, întrebări, răspunsuri), în Markdown; le scrie oricine vede sarcina, și clientul. Autorul și le poate șterge. */
class TaskComments extends Component implements HasSchemas
{
    use InteractsWithSchemas;

    public Task $task;

    /** @var array{body?: ?string} */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Editor::markdown('body', fn () => User::query()->team()->get()->filter(fn (User $person) => Task::visibleTo($person)->whereKey($this->task->id)->exists()))   // doar cine vede sarcina
                ->hiddenLabel()->required()->validationMessages(['required' => __('Write the comment.')])
                ->placeholder(__('A question, a clarification, an answer. Write @Name to let someone know.')),
        ])->statePath('data');
    }

    public function add(): void
    {
        Gate::authorize('comment', $this->task);
        $body = trim((string) $this->form->getState()['body']);

        $this->task->comments()->create(['user_id' => auth()->id(), 'body' => $body, 'source' => Source::Web]);
        $this->form->fill();
    }

    public function remove(int $id): void
    {
        $comment = $this->task->comments()->findOrFail($id);
        abort_unless($comment->user_id === auth()->id(), 403);
        $comment->delete();
    }

    public function render(): View
    {
        return view('livewire.task-comments', ['comments' => $this->task->comments()->with('author')->get()]);
    }
}
