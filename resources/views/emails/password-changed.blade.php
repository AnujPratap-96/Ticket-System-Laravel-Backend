<x-mail::message>
# Your password was changed

Hello {{ $name }},

The password for your DeskFlow account was just changed. All your other devices were signed out.

<x-mail::panel>
**When:** {{ $when }}<br>
**Device:** {{ $device }}
</x-mail::panel>

If this was you, there is nothing more to do.

**If it wasn't you,** someone may have access to your account. Reset your password right away:

<x-mail::button :url="$resetUrl" color="error">
Reset my password
</x-mail::button>
</x-mail::message>
