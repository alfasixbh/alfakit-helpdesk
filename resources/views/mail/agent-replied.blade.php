<x-mail::message>
# {{ __('alfakit-helpdesk::helpdesk.mail.replied.heading', ['name' => $author]) }}

<x-mail::panel>
{{ $body }}
</x-mail::panel>

@if ($url)
<x-mail::button :url="$url">
{{ __('alfakit-helpdesk::helpdesk.mail.view_ticket') }}
</x-mail::button>
@endif

{{ __('alfakit-helpdesk::helpdesk.mail.reply_hint') }}
@if ($poweredBy ?? null)

<x-mail::subcopy>
{{ $poweredBy }}
</x-mail::subcopy>
@endif
</x-mail::message>
