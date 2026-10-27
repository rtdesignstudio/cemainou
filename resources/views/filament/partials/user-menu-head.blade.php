{{-- Antetul meniului de la avatar (pe calculator, un panou din dreapta, ca Notificările): numele, rolul și la ce lucrează omul acum (focusul), ca să știi din ce cont lucrezi; X-ul închide panoul. --}}
@php($me = auth()->user())
@if ($me)
    <div class="wsa-usermenu-head">
        <button type="button" class="wsa-usermenu-close" x-on:click="close()" aria-label="{{ __('Close') }}">{{ svg('heroicon-o-x-mark', '', ['aria-hidden' => 'true']) }}</button>
        <b>{{ $me->name }}</b>
        <span>{{ $me->role->getLabel() }} · {{ $me->email }}</span>
        @if ($me->focus_text)
            <span class="wsa-usermenu-focus">{{ __('working on :focus', ['focus' => \App\Support\Markdown::plain($me->focus_text)]) }}</span>
        @endif
    </div>
@endif
