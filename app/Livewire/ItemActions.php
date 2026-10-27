<?php

namespace App\Livewire;

use App\Enums\Source;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\UpdateItem;
use App\Support\LineActions;
use App\Support\Markdown;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Acțiunile unui rând „Blocat" sau „Urmează" dintr-un update, la fel peste tot (pagina update-ului, Blocajele de pe Azi, pagina proiectului):
 * Rezolvat (cu o notă, opțional; se poate redeschide), Fă sarcină (un blocaj: sarcină blocată a celui care l-a raportat; un „urmează": liberă)
 * și Comentează (un comentariu pe update, cu rândul citat). Le folosește echipa care vede update-ul și autorul lui; după fiecare, listele se reîmprospătează.
 */
class ItemActions extends Component
{
    public int $itemId;

    /** compact: doar butoanele (în liste), fără starea „rezolvat de…" (care apare pe pagina update-ului) */
    public bool $compact = false;

    public ?string $mode = null;                                       // null | resolve | comment

    public string $note = '';

    /** Rândul deja încărcat de pagina care desenează lista (prima desenare, fără o interogare în plus); la acțiuni, se citește din nou. */
    protected ?UpdateItem $loaded = null;

    public function mount(int $itemId, ?UpdateItem $item = null): void
    {
        $this->itemId = $itemId;
        $this->loaded = $item?->id === $itemId ? $item : null;
    }

    public function item(): UpdateItem
    {
        if ($this->loaded) {
            $item = $this->loaded;
            $this->loaded = null;                                          // doar pentru desenarea de acum
            abort_unless(LineActions::allowed($item, auth()->user()), 404);

            return $item;
        }
        $item = UpdateItem::with(['statusUpdate.project', 'statusUpdate.user', 'task', 'resolver'])->findOrFail($this->itemId);
        abort_unless(LineActions::allowed($item, auth()->user()), 404);

        return $item;
    }

    public function open(string $mode): void
    {
        $this->mode = in_array($mode, ['resolve', 'comment'], true) ? $mode : null;
        $this->note = '';
    }

    public function resolve(): void
    {
        $this->validate(['note' => 'nullable|string|max:300']);
        $this->item()->resolve(auth()->user(), $this->note);
        $this->done(__('Marked as resolved.'));
    }

    public function reopen(): void
    {
        $this->item()->reopen();
        $this->done(__('Open again.'));
    }

    public function makeTask(): void
    {
        $item = $this->item();
        $task = LineActions::makeTask($item, auth()->user());
        $this->dispatch('items-changed');
        Notification::make()->title(__('Task #:id added on :project.', ['id' => $task->id, 'project' => $item->statusUpdate->project->name]))
            ->actions([Action::make('open')->label(__('Open it'))->url(TaskResource::getUrl('view', ['record' => $task]))])->success()->send();
    }

    public function comment(): void
    {
        $this->validate(['note' => 'required|string|max:5000']);
        $item = $this->item();
        abort_unless(auth()->user()->can('comment', $item->statusUpdate), 403);
        $item->statusUpdate->comments()->create(['user_id' => auth()->id(), 'body' => '> '.Markdown::plain($item->text)."\n\n".trim($this->note), 'source' => Source::Web]);
        $this->done(__('Comment added.'));
    }

    private function done(string $message): void
    {
        $this->mode = null;
        $this->note = '';
        $this->dispatch('items-changed');
        Notification::make()->title($message)->success()->send();
    }

    public function render(): View
    {
        $item = $this->item();

        return view('livewire.item-actions', ['item' => $item, 'canTask' => LineActions::canMakeTask($item, auth()->user())]);
    }
}
