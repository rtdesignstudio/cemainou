@props(['update', 'groups'])
{{-- Un update desfăcut în cronologia proiectului: cine, când, rezumatul (link la update) și rândurile pe tipuri; după 6 rânduri, restul la cerere. --}}
@php
    $viewer = auth()->user();
    $limit = 6;
    $count = $groups->sum(fn (array $group) => $group['items']->count());
    $n = 0;
    $marks = ['done' => 'is-done', 'doing' => 'is-doing', 'blocked' => 'is-blocked', 'next' => 'is-todo', 'decision' => 'is-decision'];
    $code = ($stats = \App\Support\CodeStats::sum(collect([$update]))) ? \App\Support\CodeStats::label($stats) : null;
@endphp
<article class="wsa-entry" x-data="{ more: false }">
    <div class="wsa-entry-meta">
        @if ($update->user && ! $viewer->isClient())<a href="{{ \App\Filament\Resources\Users\UserResource::getUrl('view', ['record' => $update->user]) }}">{{ $update->user->name }}</a>@else{{ $update->user?->name }}@endif
        · <x-wsa.time :at="$update->created_at" />
        @if ($update->is_internal) · <span class="wsa-tag is-soft">{{ __('Internal') }}</span>@endif
        @if ($code && ! $viewer->isClient()) · {{ $code }}@endif
    </div>
    <a href="{{ \App\Filament\Resources\Updates\UpdateResource::getUrl('view', ['record' => $update]) }}" class="wsa-entry-summary"><x-wsa.new :at="$update->created_at" :by="$update->user_id" />{{ \App\Support\Markdown::inline($update->summary, links: false) }}</a>
    @if ($count)
        <div class="wsa-entry-lines">
            @foreach ($groups as $group)
                <div class="wsa-entry-group" @if ($n >= $limit) x-show="more" x-cloak @endif>
                    <div class="wsa-entry-type">{{ $group['type']->sectionLabel() }}</div>
                    <ul>
                        @foreach ($group['items'] as $item)
                            <li @if ($n++ >= $limit) x-show="more" x-cloak @endif>
                                <span class="wsa-mark {{ $marks[$group['type']->value] }}" aria-hidden="true"></span>
                                <span>@if ($item->task_id)<a href="{{ \App\Filament\Resources\Tasks\TaskResource::getUrl('view', ['record' => $item->task_id]) }}" class="wsa-id">#{{ $item->task_id }}</a>@endif{{ \App\Support\Markdown::inline($item->text) }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
            @if ($count > $limit)
                <button type="button" class="wsa-link wsa-entry-more" x-show="! more" x-on:click="more = true">{{ trans_choice('and :count more line|and :count more lines', $count - $limit) }}</button>
            @endif
        </div>
    @endif
    <livewire:update-comments :update="$update" compact :count="$update->visible_comments_count ?? null" :key="'update-comments-'.$update->id" />
</article>
