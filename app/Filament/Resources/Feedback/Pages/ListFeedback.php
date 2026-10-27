<?php

namespace App\Filament\Resources\Feedback\Pages;

use App\Enums\FeedbackStatus;
use App\Filament\Resources\Feedback\FeedbackResource;
use App\Models\Feedback;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

/** Feedback-ul: întâi cele deschise. */
class ListFeedback extends ListRecords
{
    protected static string $resource = FeedbackResource::class;

    /** Lista întreagă: doar adminii (resursa se deschide și autorilor, dar numai pentru feedback-ul lor). */
    protected function authorizeAccess(): void
    {
        abort_unless(FeedbackResource::canViewAny(), 403);
    }

    public function getSubheading(): ?string
    {
        return __('From + → Feedback: what the team noticed, with the page, the screen and the files.');
    }

    public function getTabs(): array
    {
        return [
            'open' => Tab::make(__('Open ones'))->badge(fn () => Feedback::where('status', FeedbackStatus::Open->value)->count())
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', FeedbackStatus::Open->value)),
            'resolved' => Tab::make(__('Resolved ones'))->modifyQueryUsing(fn (Builder $query) => $query->where('status', FeedbackStatus::Resolved->value)),
            'all' => Tab::make(__('All')),
        ];
    }
}
