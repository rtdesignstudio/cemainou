@props(['progress'])
{{-- Cât din proiect e gata: sarcinile terminate din toate cele de făcut (fără idei și abandonate). --}}
@if ($progress)
    @php $text = $progress['label'] ?? trans_choice(':done of :total task done|:done of :total tasks done', $progress['total'], ['done' => $progress['done'], 'total' => $progress['total']]); @endphp
    <div {{ $attributes->class('wsa-progress') }} title="{{ $text }}">
        <div class="wsa-progress-bar" role="progressbar" aria-valuenow="{{ $progress['percent'] }}" aria-valuemin="0" aria-valuemax="100"><span style="width: {{ $progress['percent'] }}%"></span></div>
        <span class="wsa-meta">{{ $text }}</span>
    </div>
@endif
