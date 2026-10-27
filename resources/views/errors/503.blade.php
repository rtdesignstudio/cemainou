{{-- Mentenanța (cât rulează o actualizare): fără sesiune, deci limba vine din cookie-ul de limbă, din browser sau a echipei. --}}
@php(app()->setLocale(\App\Http\Middleware\SetLocale::guess(request())))
@include('updating')
