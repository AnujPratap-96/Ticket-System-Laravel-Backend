<x-mail::message>
# Your ticket was resolved

Hello {{ $name }},

We marked **{{ $number }}** as resolved:

<x-mail::panel>
{!! $subject !!}
</x-mail::panel>

If something is still wrong, just reply to the ticket and we will reopen it right away.

How did we do? Your rating helps us improve.

<x-mail::button :url="$url" color="success">
Rate your support experience
</x-mail::button>
</x-mail::message>
