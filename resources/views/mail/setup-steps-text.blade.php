{{ __('Hello :name, :sender sent you what is still missing so your work shows up by itself.', ['name' => $name, 'sender' => $senderName]) }}

@foreach ($list as $i => $step)
{{ $i + 1 }}. {{ $step['title'] }}
{{ $step['text'] }}
{{ $step['url'] }}

@endforeach
