<?php

namespace App\Filament\Widgets;

use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\WidgetInvite;
use App\Support\FeedbackWidget;
use Illuminate\Support\Collection;

/**
 * Azi, la clientul al cărui proiect are widget-ul de feedback pornit: butonul spre site (cu Feedback pornit pe dispozitivul lui), codul QR
 * pentru telefon și ce a trimis de acolo, cu starea fiecăruia.
 */
class ClientSiteFeedback extends ClientWidget
{
    protected string $view = 'filament.widgets.client-site-feedback';

    /** Adresa pentru care se arată codul QR („id|adresă"); null = niciunul. */
    public ?string $qr = null;

    public static function canView(): bool
    {
        return parent::canView() && self::sites()->isNotEmpty();
    }

    /** Adresele widget-ului din proiectele clientului (cel mult 3). @return Collection<int, array{project: Project, domain: string}> */
    public static function sites(): Collection
    {
        $user = auth()->user();

        return Project::visibleTo($user)->with('lead')->where('is_archived', false)->where('widget_enabled', true)->orderByDesc('updated_at')->get()
            ->filter(fn (Project $p) => FeedbackWidget::canUse($user, $p))
            ->flatMap(fn (Project $p) => collect($p->widget_domains ?? [])->map(fn (string $d) => ['project' => $p, 'domain' => $d]))
            ->take(3)->values();
    }

    public function showQr(?string $key = null): void
    {
        $this->qr = $key !== null && self::sites()->contains(fn (array $s) => $s['project']->id.'|'.$s['domain'] === $key) ? $key : null;
    }

    protected function getViewData(): array
    {
        $me = auth()->user();
        $sites = self::sites();
        $sent = fn () => Task::visibleTo($me)->where('created_by', $me->id)->whereNotNull('site_context')->whereIn('project_id', $sites->pluck('project.id'));
        $qr = $sites->first(fn (array $s) => $s['project']->id.'|'.$s['domain'] === $this->qr);

        return [
            'sites' => $sites,
            'projects' => $sites->pluck('project')->unique('id'),
            'tasks' => $sent()->with(['project', 'assignee'])->latest()->limit(3)->get(),
            'count' => $sent()->count(),
            'done' => $sent()->where('status', TaskStatus::Done->value)->count(),
            'qrUrl' => $link = $qr ? WidgetInvite::forShare(auth()->user(), $qr['project'], $qr['domain'])->url() : null,   // invitația clientului, de dat colegilor lui (30 de zile, refolosibilă)
            'qrSvg' => $link ? FeedbackWidget::qrSvg($link) : null,
            'qrDomain' => $qr['domain'] ?? null,
        ];
    }
}
