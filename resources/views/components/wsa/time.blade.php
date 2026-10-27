@props(['at'])
{{-- Cât a trecut („acum 2 zile"), cu ziua și ora exacte la hover, în fusul celui care se uită. --}}
@if ($at)<time datetime="{{ $at->copy()->utc()->toIso8601String() }}" title="{{ \App\Support\Ago::exact($at) }}" {{ $attributes }}>{{ $at->diffForHumans() }}</time>@endif
