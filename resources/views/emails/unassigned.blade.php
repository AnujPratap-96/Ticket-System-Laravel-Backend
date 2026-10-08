<x-mail::message>
# A ticket needs an owner

Hello {{ $name }},

Ticket **{{ $number }}** could not be assigned automatically. Every agent in the department is either at full capacity or unavailable.

<x-mail::panel>
{!! $subject !!}
</x-mail::panel>

<x-mail::button :url="$url" color="error">
Assign this ticket
</x-mail::button>
</x-mail::message>
