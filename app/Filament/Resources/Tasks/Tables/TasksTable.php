<?php

namespace App\Filament\Resources\Tasks\Tables;

use App\Enums\TaskStatus;
use App\Filament\Resources\Tasks\TaskActions;
use App\Models\Task;
use App\Support\TaskTrail;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TasksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->deferFilters(false)                                   // filtrele se aplică pe loc, fără butonul „Aplică"
            ->paginated([25, 50, 250, 500])->defaultPaginationPageOption(25)   // rândurile sarcinilor sunt bogate: 25 la început, pagina e ușoară pe telefon
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['project', 'assignee']))
            ->columns([
                TextColumn::make('status')->label(__('Status'))->html()
                    ->formatStateUsing(fn (TaskStatus $state) => '<span class="wsa-status-cell"><span class="wsa-mark is-'.$state->value.'"></span>'.e($state->getLabel()).'</span>'),
                // numărul în fața titlului, pe orice ecran (după el se vorbește: „#12"); se caută și după el
                TextColumn::make('title')->label(__('Task'))->searchable(query: fn (Builder $query, string $search) => $query->where(fn ($q) => $q->where('title', 'like', '%'.$search.'%')
                    ->when(ctype_digit(ltrim($search, '#')), fn ($q) => $q->orWhere('id', (int) ltrim($search, '#')))))
                    ->html()->formatStateUsing(fn (string $state, Task $record) => '<span class="wsa-id">#'.$record->id.'</span>'.e($state))
                    ->weight('medium')->wrap()->extraAttributes(['class' => 'wsa-col-title'])
                    ->description(fn (Task $record) => collect([$record->project?->name, $record->repeat?->short()])->filter()->join(' · ')),
                // termenul: depășit = subliniat gros (fără roșu; roșul e doar pentru blocaje)
                TextColumn::make('due_on')->label(__('Due date'))->html()->sortable()
                    ->state(fn (Task $record) => ($due = $record->due()) ? '<span class="wsa-due'.($due['overdue'] ? ' is-overdue' : '').'">'.e($due['overdue'] ? __('overdue, :when', ['when' => $due['label']]) : $due['label']).'</span>' : null),
                // pașii: câți sunt gata, cu o bară mică
                TextColumn::make('steps')->label(__('Steps'))->html()->visibleFrom('md')
                    ->state(fn (Task $record) => ($list = $record->checklist()) ? '<span class="wsa-steps-cell"><span class="wsa-steps-bar"><i style="width: '.round($list['done'] * 100 / $list['total']).'%"></i></span>'.$list['done'].'/'.$list['total'].'</span>' : null),
                TextColumn::make('assignee.name')->label(__('Who'))->placeholder(__('Free')),
                TextColumn::make('seen')->label('')->html()
                    ->state(fn (Task $record) => $record->isNew() && $record->assignee_id === auth()->id() ? '<span class="wsa-new">'.e(__('New')).'</span>' : null),
                TextColumn::make('updated_at')->label(__('Last moved'))->since()->dateTimeTooltip('l, j F Y, H:i', fn () => auth()->user()?->tz())->sortable()->visibleFrom('lg'),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading(__('No tasks'))->emptyStateDescription(__('Add one with “New task” or take a free one from a project.'))
            ->recordUrl(fn (Task $record) => TaskTrail::url($record))   // din listă: pagina sarcinii știe drumul înapoi
            ->persistFiltersInSession()->persistSortInSession()                     // revii unde ai rămas
            // proiectul și omul se aleg deasupra listei (ListTasks: oamenii și proiectele), nu dintr-un filtru ascuns
            // „Gata": buton cu bifă, nu un text gri care pare dezactivat
            ->recordActions([TaskActions::promote(), TaskActions::take(), TaskActions::done()->icon(Heroicon::OutlinedCheck)->button()->outlined()->color('gray')->size('sm')]);
    }
}
