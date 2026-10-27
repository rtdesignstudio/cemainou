{{-- Butonul de feedback din dreapta jos: pentru echipa activă (nu și pentru client). --}}
@auth
    @can('create', \App\Models\Feedback::class)
        <livewire:feedback-button />
    @endcan
@endauth
