<x-mail::message>
# Your support week

Hello {{ $name }}, here is how the last seven days went.

<x-mail::table>
| | |
|:--|--:|
| New tickets | **{{ $created }}** |
| Resolved | **{{ $resolved }}** |
| Open right now | **{{ $open }}** |
| SLA deadlines missed | **{{ $breached }}** |
| Customer rating (average) | **{{ $rating ?? 'no ratings yet' }}** |
</x-mail::table>

<x-mail::button :url="$url">
Open analytics
</x-mail::button>
</x-mail::message>
