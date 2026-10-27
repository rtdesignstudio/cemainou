<?php

namespace App\Livewire;

use App\Models\Update;
use App\Models\UpdateAck;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/** „Văzut" pe pagina unui update: un buton pentru cine l-a citit (nu și autorul) și lista lor, pentru echipă. Clientul nu vede nimic din asta. */
class UpdateSeen extends Component
{
    public Update $update;

    public function toggle(): void
    {
        $user = auth()->user();
        Gate::authorize('view', $this->update);
        abort_if($user->isClient() || $user->id === $this->update->user_id, 403);
        $ack = UpdateAck::where('update_id', $this->update->id)->where('user_id', $user->id)->first();
        $ack ? $ack->delete() : UpdateAck::create(['update_id' => $this->update->id, 'user_id' => $user->id]);
    }

    public function render(): View
    {
        $acks = $this->update->acks()->with('user')->get()->filter(fn (UpdateAck $ack) => $ack->user)->values();

        return view('livewire.update-seen', [
            'acks' => $acks,
            'mine' => $acks->contains('user_id', auth()->id()),
            'canAck' => ! auth()->user()->isClient() && auth()->id() !== $this->update->user_id,
        ]);
    }
}
