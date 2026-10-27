{{-- Turul „cum funcționează cemainou": datele (varianta, textele traduse) și scriptul; se deschide din orice [data-wsa-tour] sau cu ?tour=1. --}}
@auth
    <script>window.wsaTourData = @json(\App\Support\Tour::data(auth()->user()));</script>
    <script src="{{ \App\Support\Tour::scriptUrl() }}" defer></script>
@endauth
