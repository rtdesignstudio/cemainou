@php($f = $this->record)
<x-filament-panels::page>
    <div class="wsa-split">
        <div>
            <section class="wsa-block">
                <header class="wsa-block-head"><h2>{{ __('What they wrote') }}</h2><span>{{ $f->status->getLabel() }}</span></header>
                <div class="wsa-prose">{{ \App\Support\Markdown::html($f->body, breaks: true) }}</div>
                @if ($f->files)
                    <div class="wsa-feedback-gallery" data-wsa-gallery>
                        @foreach ($f->files as $i => $file)
                            <a href="{{ route('feedback.file', [$f, $i]) }}" target="_blank" rel="noopener" @class(['wsa-feedback-thumb', 'is-file' => ! $f->isImage($i)]) @if ($f->isImage($i)) data-wsa-lightbox data-caption="{{ $file['name'] }}" @endif>
                                @if ($f->isImage($i))
                                    <img src="{{ route('feedback.file', [$f, $i]) }}" alt="{{ $file['name'] }}" loading="lazy">
                                @else
                                    <span>{{ $file['name'] }}</span><span class="wsa-meta">{{ \App\Support\Files::size($file['size'] ?? null) }}</span>
                                @endif
                            </a>
                        @endforeach
                    </div>
                @endif
            </section>

            @unless ($f->isOpen())
                <section class="wsa-block wsa-section">
                    <header class="wsa-block-head"><h2>{{ __('Resolved') }}</h2><span>{{ $f->resolver?->name }} · <x-wsa.time :at="$f->resolved_at" /></span></header>
                    @if ($f->resolution)
                        <div class="wsa-prose">{{ \App\Support\Markdown::html($f->resolution, breaks: true) }}</div>
                    @endif
                </section>
            @endunless
        </div>

        <aside class="wsa-aside">
            <section class="wsa-block">
                <header class="wsa-block-head"><h2>{{ __('Details') }}</h2></header>
                <dl class="wsa-kv">
                    <div><dt>{{ __('Who') }}</dt><dd>@if ($f->author)<a href="{{ \App\Filament\Resources\Users\UserResource::getUrl('view', ['record' => $f->author]) }}">{{ $f->author->name }}</a>@else {{ $f->from() ?? '-' }}@endif</dd></div>
                    <div><dt>{{ __('When') }}</dt><dd>{{ \App\Support\Ago::exact($f->created_at) }}</dd></div>
                    @if ($f->url && $f->installation)<div><dt>{{ __('Page') }}</dt><dd>{{ $f->url }}</dd></div>@elseif ($f->url && preg_match('#^https?://#i', $f->url))<div><dt>{{ __('Page') }}</dt><dd><a href="{{ $f->url }}">{{ parse_url($f->url, PHP_URL_PATH) ?: '/' }}{{ parse_url($f->url, PHP_URL_QUERY) ? '?'.parse_url($f->url, PHP_URL_QUERY) : '' }}</a></dd></div>@endif
                    @if ($f->viewport)<div><dt>{{ __('Window') }}</dt><dd>{{ $f->viewport }}</dd></div>@endif
                    @if ($f->screen)<div><dt>{{ __('Screen') }}</dt><dd>{{ $f->screen }}</dd></div>@endif
                    @if ($device)<div><dt>{{ __('Browser') }}</dt><dd title="{{ $f->user_agent }}">{{ $device }}</dd></div>@endif
                    @if ($f->app_version)<div><dt>{{ __('Version') }}</dt><dd>{{ $f->app_version }}</dd></div>@endif
                    @if ($f->sent_to_rt_at)<div><dt>{{ __('Sent to RT') }}</dt><dd>{{ \App\Support\Ago::exact($f->sent_to_rt_at) }}</dd></div>@endif
                </dl>
            </section>
        </aside>
    </div>
</x-filament-panels::page>
