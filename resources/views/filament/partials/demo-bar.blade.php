{{-- Instanța demo: o bandă subțire sus, pe fiecare pagină. --}}
@if (\App\Support\Demo::on())
    <div class="wsa-demo-bar">
        <span>{{ __('Demo with made-up data. Try anything: it all starts over every hour.') }}</span>
        <a href="https://rt.md/cemainou/{{ app()->getLocale() === 'ro' ? 'ro/' : '' }}" target="_blank" rel="noopener">{{ __('Install it for your team') }}</a>
    </div>
@endif
