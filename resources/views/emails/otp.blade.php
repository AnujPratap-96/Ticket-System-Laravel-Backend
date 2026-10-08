<x-mail::message>
# {{ $heading }}

{{ $intro }}

<x-mail::panel>
<span style="display:block;text-align:center;font-size:34px;font-weight:700;letter-spacing:10px;color:#111827;font-family:Menlo,Consolas,monospace;">{{ $code }}</span>
</x-mail::panel>

This code expires in **{{ $ttl }} minutes** and can be used only once.
Never share it with anyone. {{ config('app.name') }} staff will never ask you for it.

If you didn't request this, you can safely ignore this email.
</x-mail::message>
