{{-- Ce înseamnă semnele sarcinilor, sub listele care nu au grupe cu nume (Sarcinile mele, sarcinile unui client). --}}
<p class="wsa-legend">
    @foreach ([\App\Enums\TaskStatus::Doing, \App\Enums\TaskStatus::Blocked, \App\Enums\TaskStatus::Todo, \App\Enums\TaskStatus::Done] as $status)
        <span><span class="wsa-mark is-{{ $status->value }}" aria-hidden="true"></span>{{ $status->getLabel() }}</span>
    @endforeach
</p>
