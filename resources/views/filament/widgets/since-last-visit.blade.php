<x-filament-widgets::widget>
    @if (array_sum($counts ?? []) > 0)
    {{-- antetul (eticheta și ×, pe același rând), apoi ce e nou: pe desktop un rând de linkuri, pe telefon plăci mari, două pe rând --}}
    @php
        $items = array_filter([
            $counts['updates'] ? ['n' => $counts['updates'], 'label' => trans_choice('update|updates', $counts['updates']), 'url' => $updatesUrl] : null,
            $counts['tasks'] ? ['n' => $counts['tasks'], 'label' => trans_choice('new task|new tasks', $counts['tasks']), 'url' => $tasksUrl] : null,
            $counts['done'] ? ['n' => $counts['done'], 'label' => trans_choice('task done|tasks done', $counts['done']), 'url' => $doneUrl] : null,
            $counts['comments'] ? ['n' => $counts['comments'], 'label' => trans_choice('comment|comments', $counts['comments']), 'modal' => 'since-comments'] : null,
        ]);
    @endphp
    <div class="wsa-since">
        <span class="wsa-since-label"><i aria-hidden="true"></i>{{ __('Since your last visit (:when)', ['when' => $when]) }}</span>
        <span class="wsa-since-items">
            @foreach ($items as $item)
                @if (isset($item['modal']))
                    <button type="button" class="wsa-since-item" x-data x-on:click="$dispatch('open-modal', { id: '{{ $item['modal'] }}' })"><b>{{ $item['n'] }}</b> <span>{{ $item['label'] }}</span></button>
                @else
                    <a href="{{ $item['url'] }}" class="wsa-since-item"><b>{{ $item['n'] }}</b> <span>{{ $item['label'] }}</span></a>
                @endif
            @endforeach
        </span>
        <x-wsa.close wire:click="seen" :title="__('Mark as seen')" />
    </div>

    {{-- comentariile noi, într-o fereastră: pe ce sarcină, cine și când, textul; fiecare duce la comentariile sarcinii --}}
    @if ($counts['comments'])
        <x-filament::modal id="since-comments" width="2xl" :heading="trans_choice(':count new comment|:count new comments', $counts['comments'])" :description="__('Since your last visit (:when)', ['when' => $when])">
            <div class="wsa-since-modal">
                @foreach ($comments as $comment)
                    <article class="wsa-since-comment">
                        <a href="{{ \App\Filament\Resources\Tasks\TaskResource::getUrl('view', ['record' => $comment->task]) }}#comments" class="wsa-since-comment-task"><span class="wsa-id">#{{ $comment->task_id }}</span>{{ $comment->task?->title }}</a>
                        <p class="wsa-meta">{{ $comment->author?->name }} · <x-wsa.time :at="$comment->created_at" />@if ($comment->task?->project) · {{ $comment->task->project->name }}@endif</p>
                        <p class="wsa-since-comment-body">{{ \Illuminate\Support\Str::limit(\App\Support\Markdown::plain($comment->body), 360) }}</p>
                        <a href="{{ \App\Filament\Resources\Tasks\TaskResource::getUrl('view', ['record' => $comment->task]) }}#comments" class="wsa-since-comment-open">{{ __('Open the task') }}<x-wsa.arrow /></a>
                    </article>
                @endforeach
            </div>
        </x-filament::modal>
    @endif
    @endif
</x-filament-widgets::widget>
