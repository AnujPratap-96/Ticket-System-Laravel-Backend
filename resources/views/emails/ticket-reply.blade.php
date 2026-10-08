<x-mail::message>
# New reply on your ticket

Hello {{ $name }},

**{{ $agent }}** from our support team replied to your ticket.

**Ticket:** {{ $number }}<br>
**Subject:** {{ $subject }}

<x-mail::panel>
{!! $excerpt !!}
</x-mail::panel>

<x-mail::button :url="$url">
View ticket and reply
</x-mail::button>

@if ($canReplyByEmail)
You can also reply to this email. Your answer will be added to the ticket.
@endif
</x-mail::message>
