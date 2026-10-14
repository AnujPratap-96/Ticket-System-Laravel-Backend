<x-mail::message>
# We received your request

Hello {{ $name }},

Thank you for contacting us. Your request is now **{{ $number }}**@if ($department) and is with our **{{ $department }}** team. @else. @endif

<x-mail::panel>
{!! $subject !!}
</x-mail::panel>

@if ($replyBy !== ' UTC')
We aim to give you a first answer by **{{ $replyBy }}** (counted in our support hours).
@endif

Please keep **{{ $number }}** in the subject if you write to us about this request.

<x-mail::button :url="$url">
View your ticket
</x-mail::button>

@if ($canReplyByEmail)
You can also simply reply to this email. Your answer will be added to the ticket.
@endif
</x-mail::message>
