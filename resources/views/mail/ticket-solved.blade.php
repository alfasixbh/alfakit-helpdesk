<x-mail::message>
# {{ __('alfakit-helpdesk::helpdesk.mail.solved.heading') }}

{{ __('alfakit-helpdesk::helpdesk.mail.solved.body', ['reference' => $ticket->getReference(), 'subject' => $ticket->subject]) }}

@if ($rateUrl)
<x-mail::button :url="$rateUrl">
{{ __('alfakit-helpdesk::helpdesk.mail.solved.rate') }}
</x-mail::button>
@endif

@if ($url)
{{ __('alfakit-helpdesk::helpdesk.mail.solved.reopen') }} [{{ __('alfakit-helpdesk::helpdesk.mail.view_ticket') }}]({{ $url }})
@endif
@if ($poweredBy ?? null)

<x-mail::subcopy>
{{ $poweredBy }}
</x-mail::subcopy>
@endif
</x-mail::message>
