<?php

namespace App\Support;

use App\Models\ClientView;
use App\Models\Document;
use App\Models\Project;
use App\Models\Task;
use App\Models\Update;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * „Văzut de client" (ca la raportul lunar): când un om de la client deschide o sarcină, un update sau un document, se notează prima și ultima
 * deschidere. Echipa vede pe pagina aceea „văzut de Elena, acum 2 ore" sau „clientul n-a văzut încă" - doar pe proiectele vizibile clientului
 * și doar la ce i se arată lui (nu la update-urile interne sau la documentele nepartajate). Știi dacă e de reamintit sau dacă nici n-a deschis.
 */
class ClientSeen
{
    private const TYPES = [Task::class => 'task', Update::class => 'update', Document::class => 'document'];

    public static function record(User $user, Model $subject): void
    {
        if (! $user->isClient() || ! isset(self::TYPES[$subject::class])) {
            return;
        }
        $key = ['user_id' => $user->id, 'subject_type' => self::TYPES[$subject::class], 'subject_id' => $subject->getKey()];
        $row = ClientView::where($key)->first();
        $row ? $row->forceFill(['last_seen_at' => now()])->save() : ClientView::create($key + ['first_seen_at' => now(), 'last_seen_at' => now()]);
    }

    /** Rândul pentru echipă (în subtitlul paginii), sau null când nu e cazul (alt om de la client, proiect nevizibil clientului, lucru nearătat lui). */
    public static function line(Model $subject, ?Project $project, User $viewer): ?string
    {
        if ($viewer->isClient() || ! $project?->client_id || ! $project->client_visible || ! self::shownToClient($subject)) {
            return null;
        }
        $views = ClientView::with('user')->where('subject_type', self::TYPES[$subject::class])->where('subject_id', $subject->getKey())->orderBy('first_seen_at')->get();
        if ($views->isEmpty()) {
            return __('the client has not seen it yet');
        }

        return __('seen by :names :when', ['names' => $views->map(fn (ClientView $v) => $v->user?->name)->filter()->unique()->join(', '), 'when' => $views->first()->first_seen_at->diffForHumans()]);
    }

    private static function shownToClient(Model $subject): bool
    {
        return match ($subject::class) {
            Update::class => ! $subject->is_internal,
            Document::class => (bool) $subject->client_visible,
            Task::class => true,
            default => false,
        };
    }
}
