{{-- Grila cardurilor de pe Azi: desenată o dată pe minut (App\Support\PageCache), din ProjectCards. --}}
@if ($cards->isEmpty())
    <p class="wsa-empty">{{ $client ? __('No project is shared with you yet. The team turns it on per project.') : __('No project had activity in the last 14 days.') }}</p>
@else
    <div class="wsa-portfolio-grid wsa-today-projects">
        @foreach ($cards as $c)<x-wsa.pcard :c="$c" :period-label="__('Current month')" :from="$from" :to="$to" />@endforeach
    </div>
@endif
