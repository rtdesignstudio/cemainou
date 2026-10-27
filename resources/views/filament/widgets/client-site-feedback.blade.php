{{-- Azi, la client: Feedback direct pe site (butonul pornit pe dispozitivul lui, QR pentru telefon) și ce a trimis de acolo. --}}
<x-filament-widgets::widget>
    <section class="wsa-block wsa-site-fb">
        <header class="wsa-block-head">
            <h2>{{ __('Feedback on the site') }}</h2>
            @if ($count)
                <span>{{ trans_choice(':count sent|:count sent', $count, ['count' => $count]) }} · {{ trans_choice(':count solved|:count solved', $done, ['count' => $done]) }}</span>
            @endif
        </header>
        <p class="wsa-site-fb-text">
            {{ __('Something to change on :site? Turn on the Feedback button right on the site: point at the element, take a screenshot, write. It goes to :lead.', [
                'site' => $sites->pluck('domain')->join(', '),
                'lead' => $projects->pluck('lead.name')->filter()->unique()->join(', ') ?: __('the team'),
            ]) }}
        </p>
        <div class="wsa-site-fb-actions">
            @foreach ($sites as $site)
                <div class="wsa-site-fb-site">
                    <a href="{{ route('widget.activate', ['project' => $site['project'], 'domain' => $site['domain']]) }}" target="_blank" rel="noopener" class="wsa-site-fb-open">
                        {{ __('Open :site with the button', ['site' => $site['domain']]) }}<x-wsa.arrow />
                    </a>
                    <button type="button" class="wsa-inline-btn" wire:click="showQr('{{ $site['project']->id }}|{{ $site['domain'] }}')">{{ __('Link and QR') }}</button>
                </div>
            @endforeach
        </div>
        @if ($qrSvg)
            <div class="wsa-site-fb-qr">
                <div class="wsa-widget-qr-code">{!! $qrSvg !!}</div>
                <div>
                    <p>{{ __('Scan it, or send the link to your colleagues: :site opens with the Feedback button on. Whoever has an account goes in with it; the others write with their name, through your link. It works for 30 days.', ['site' => $qrDomain]) }}</p>
                    <x-wsa.copy-link :url="$qrUrl" />
                    <button type="button" class="wsa-inline-btn" wire:click="showQr">{{ __('Close') }}</button>
                </div>
            </div>
        @endif
        @foreach ($tasks as $task)
            <a href="{{ \App\Filament\Resources\Tasks\TaskResource::getUrl('view', ['record' => $task]) }}" class="wsa-row has-mark">
                <span class="wsa-mark is-{{ $task->status->value }}" aria-hidden="true"></span>
                <span class="wsa-row-title"><span class="wsa-id">#{{ $task->id }}</span>{{ $task->title }}</span>
                <span class="wsa-row-meta"><b>{{ $task->status->getLabel() }}</b>{{ $task->assignee && $task->status->isOpen() ? ' · '.$task->assignee->name : '' }} · {{ $task->created_at->diffForHumans() }}</span>
            </a>
        @endforeach
    </section>
</x-filament-widgets::widget>
