<x-mail::message>
# {{ $isNew ? __('alfakit-helpdesk::helpdesk.mail.agent.new_heading', ['reference' => $ticket->getReference()]) : __('alfakit-helpdesk::helpdesk.mail.agent.reply_heading', ['name' => $requester]) }}

**{{ $ticket->subject }}** · {{ $ticket->getPriority()->getLabel() }}

@if ($body)
<x-mail::panel>
{{ $body }}
</x-mail::panel>
@endif

@if ($url)
<x-mail::button :url="$url">
{{ __('alfakit-helpdesk::helpdesk.mail.open_ticket') }}
</x-mail::button>
@endif
@if ($poweredBy ?? null)

<x-mail::subcopy>
{{ $poweredBy }}
</x-mail::subcopy>
@endif
</x-mail::message>
