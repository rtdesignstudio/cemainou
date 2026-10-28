{{-- Instanța demo, pe pagina de intrare: oamenii inventați, fiecare cu rolul lui; un clic și ești el. --}}
@if (\App\Support\Demo::on() && ($people = \App\Support\Demo::people())->isNotEmpty())
    <div class="wsa-demo-login">
        <p class="wsa-demo-login-title">{{ __('Try the demo as') }}</p>
        @foreach ($people as $person)
            <form method="post" action="{{ route('demo.enter', $person) }}">
                @csrf
                <button type="submit"><b>{{ $person->name }}</b><span>{{ $person->role->getLabel() }}</span></button>
            </form>
        @endforeach
        <p class="wsa-demo-login-note">{{ __('Made-up people and projects. Everything starts over every hour.') }}</p>
    </div>
@endif
