<?php

namespace App\Livewire;

use App\Enums\Source;
use App\Models\Update;
use App\Models\User;
use App\Support\Editor;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * Comentariile unui update (în Markdown): ale colegilor și ale autorului, pe care le vede toată echipa, și ale clientului, care își vede doar comentariile lui.
 * În cronologia proiectului stau strânse (doar numărul); pe pagina update-ului, deschise. Când autorul le vede, sunt „văzute" și pentru agentul lui.
 */
class UpdateComments extends Component implements HasSchemas
{
    use InteractsWithSchemas;

    public Update $update;

    public bool $compact = false;

    public bool $open = false;

    /** În cronologie: câte comentarii vede omul, numărate de pagină pentru toate update-urile odată; cât sunt strânse, nu se citesc. */
    public ?int $count = null;

    /** @var array{body?: ?string} */
    public ?array $data = [];

    public function mount(): void
    {
        $this->open = ! $this->compact;
        if ($this->open) {
            $this->form->fill();
        }
    }

    public function form(Schema $schema): Schema
    {
        $client = auth()->user()?->isClient();

        return $schema->components([
            Editor::markdown('body', fn () => User::query()->team()->get()->filter(fn (User $person) => $person->can('view', $this->update)))   // doar cine vede update-ul
                ->hiddenLabel()->required()->validationMessages(['required' => __('Write the comment.')])
                ->placeholder($client ? __('A question or a note for the team. Only the team sees it.') : __('A question, a note, an answer. Write @Name to let someone know.')),
        ])->statePath('data');
    }

    public function toggle(): void
    {
        $this->open = ! $this->open;
        if ($this->open) {
            $this->form->fill();                                                      // formularul, abia la deschidere (pe pagina proiectului sunt zeci de update-uri)
        }
    }

    public function add(): void
    {
        Gate::authorize('comment', $this->update);
        $body = trim((string) $this->form->getState()['body']);

        $this->update->comments()->create(['user_id' => auth()->id(), 'body' => $body, 'source' => Source::Web]);
        $this->form->fill();
        $this->open = true;
    }

    public function remove(int $id): void
    {
        $comment = $this->update->comments()->findOrFail($id);
        abort_unless($comment->user_id === auth()->id() || auth()->user()->isAdmin(), 403);
        $comment->delete();
    }

    public function render(): View
    {
        $viewer = auth()->user();
        if (! $this->open && $this->count !== null) {                                    // strânse: doar numărul, fără nicio interogare
            return view('livewire.update-comments', ['comments' => collect(), 'total' => $this->count, 'client' => $viewer->isClient()]);
        }
        $comments = $this->update->comments()->visibleTo($viewer)->with('author')->get();
        $this->count = $comments->count();
        if ($this->open && $this->update->user_id === $viewer->id) {                      // autorul le-a văzut: agentul lui nu i le mai aduce ca noi
            $this->update->comments()->whereNull('author_seen_at')->where('user_id', '!=', $viewer->id)->update(['author_seen_at' => now()]);
        }

        return view('livewire.update-comments', ['comments' => $comments, 'total' => $comments->count(), 'client' => $viewer->isClient()]);
    }
}
