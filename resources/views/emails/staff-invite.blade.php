<x-mail::message>
# You're invited to DeskFlow

Hello {{ $name }},

@if ($invitedBy)
**{{ $invitedBy }}** has invited you to join the DeskFlow support team as **{{ $role }}**@if ($department) in **{{ $department }}**@endif.
@else
You have been invited to join the DeskFlow support team as **{{ $role }}**@if ($department) in **{{ $department }}**@endif.
@endif

Choose your password to activate your account:

<x-mail::button :url="$url">
Accept invitation
</x-mail::button>

This link works once and expires in **{{ $days }} days**. If you weren't expecting this invitation you can safely ignore this email.
</x-mail::message>
