<?php

namespace App\Filament\Resources\Feedback\Pages;

use App\Filament\Resources\Feedback\FeedbackResource;
use App\Livewire\PushNotifications;
use App\Support\Ago;
use App\Support\BackLinks;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

/** Un feedback: textul și fișierele; în dreapta cine, când, pagina, ecranul, browserul, versiunea; sus „Rezolvat" / „Redeschide". */
class ViewFeedback extends ViewRecord
{
    protected static string $resource = FeedbackResource::class;

    protected string $view = 'filament.resources.feedback.view-feedback';

    public function getTitle(): string|Htmlable
    {
        return __('Feedback #:id', ['id' => $this->record->id]);
    }

    public function getSubheading(): ?string
    {
        return collect([$this->record->from(), Ago::exact($this->record->created_at), $this->record->status->getLabel()])->filter()->join(' · ');
    }

    protected function getHeaderActions(): array
    {
        return [FeedbackResource::resolveAction(), FeedbackResource::reopenAction(), DeleteAction::make()->outlined()];
    }

    public function backLinks(): array
    {
        return FeedbackResource::canViewAny() ? BackLinks::to(__('Feedback'), FeedbackResource::getUrl('index')) : [];   // autorul nu are lista
    }

    protected function getViewData(): array
    {
        return ['device' => $this->record->user_agent ? PushNotifications::device($this->record->user_agent) : null];
    }
}
