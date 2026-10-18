<x-mail::message>
# {{ __('alfakit-helpdesk::helpdesk.mail.received.heading') }}

{{ __('alfakit-helpdesk::helpdesk.mail.received.body', ['reference' => $ticket->getReference(), 'subject' => $ticket->subject]) }}

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
