<x-mail::message>
# {{ $heading }}

Hello {{ $name }},

{{ $lead }}

**Ticket:** {{ $number }}
@if ($subject !== '')
<br>**Subject:** {{ $subject }}
@endif

@if ($detail !== '')
<x-mail::panel>
{!! $detail !!}
</x-mail::panel>
@endif

<x-mail::button :url="$url">
Open ticket
</x-mail::button>
</x-mail::message>
