{{-- rândurile schimbate între două versiuni (App\Support\LineDiff::hunks): + adăugat, − scos, restul strânse --}}
@if (empty($hunks))
    <p class="wsa-empty">{{ __('No differences in the text.') }}</p>
@else
    <div class="wsa-diff" role="table" aria-label="{{ __('Differences') }}">
        @foreach ($hunks as [$op, $line])
            @if ($op === '…')
                <div class="wsa-diff-skip">{{ trans_choice(':count unchanged line|:count unchanged lines', $line, ['count' => $line]) }}</div>
            @else
                <div @class(['wsa-diff-line', 'is-add' => $op === '+', 'is-del' => $op === '-'])><span class="wsa-diff-op" aria-hidden="true">{{ $op === '-' ? '−' : $op }}</span><span class="wsa-diff-text">{{ $line === '' ? ' ' : $line }}</span></div>
            @endif
        @endforeach
    </div>
@endif
