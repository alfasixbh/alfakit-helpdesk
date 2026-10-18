<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Http\Controllers;

use AlfaSix\AlfakitHelpdesk\AlfakitHelpdesk;
use AlfaSix\AlfakitHelpdesk\Models\Ticket;
use AlfaSix\AlfakitHelpdesk\Portal\ContactForm;
use AlfaSix\AlfakitHelpdesk\Portal\MyTicketsTable;
use AlfaSix\AlfakitHelpdesk\Portal\NewTicketForm;
use AlfaSix\AlfakitHelpdesk\Portal\ReplyForm;
use AlfaSix\AlfakitHelpdesk\Portal\SatisfactionForm;
use AlfaSix\AlfakitHelpdesk\Portal\TicketConversation;
use AlfaSix\AlfakitHelpdesk\Support\Tickets;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The customer side: the portal (signed-in customers' tickets), the signed links of
 * emails (guests), the contact form and the satisfaction survey. Every page renders the
 * Inertia page of `config('alfakit-helpdesk.page')` with standalone AlfaKit components.
 */
class PortalController
{
    public function index(Request $request): Response|RedirectResponse
    {
        if (($redirect = $this->requireCustomer()) !== null) {
            return $redirect;
        }

        return $this->render('index', __('alfakit-helpdesk::helpdesk.portal.heading'), [MyTicketsTable::make()]);
    }

    public function create(Request $request): Response|RedirectResponse
    {
        if (($redirect = $this->requireCustomer()) !== null) {
            return $redirect;
        }

        return $this->render('create', __('alfakit-helpdesk::helpdesk.portal.new_heading'), [NewTicketForm::make()]);
    }

    public function show(Request $request, string $ticket): Response|RedirectResponse
    {
        if (($redirect = $this->requireCustomer()) !== null) {
            return $redirect;
        }

        $record = $this->find($ticket);

        // Another requester's ticket is "not found", never "forbidden".
        abort_unless($record !== null && $record->isRequestedBy(AlfakitHelpdesk::customer()), 404);

        return $this->ticketPage($record, guest: false);
    }

    /**
     * A ticket from the signed link of an email: no account needed.
     */
    public function guest(Request $request, string $ticket): Response
    {
        $record = $this->find($ticket);

        abort_if($record === null, 404);

        return $this->ticketPage($record, guest: true);
    }

    public function contact(Request $request): Response
    {
        abort_unless((bool) config('alfakit-helpdesk.contact.enabled', true), 404);

        return $this->render('contact', __('alfakit-helpdesk::helpdesk.contact.heading'), [ContactForm::show()]);
    }

    public function rate(Request $request, string $ticket): Response
    {
        $record = $this->find($ticket);

        abort_if($record === null, 404);

        return $this->render('rate', __('alfakit-helpdesk::helpdesk.csat.heading'), [SatisfactionForm::make(['ticket' => $record, 'guest' => true])]);
    }

    protected function ticketPage(Ticket $ticket, bool $guest): Response
    {
        // A merged ticket lives on in the one it was merged into (same requester).
        if ($ticket->isMerged() && $ticket->mergedInto !== null) {
            $ticket = $ticket->mergedInto;
        }

        Tickets::seenByRequester($ticket);

        $components = [
            TicketConversation::make(['ticket' => $ticket, 'guest' => $guest]),
            ReplyForm::make(['ticket' => $ticket, 'guest' => $guest]),
        ];

        if ($ticket->canBeRated() && $ticket->satisfaction_rated_at === null) {
            $components[] = SatisfactionForm::make(['ticket' => $ticket, 'guest' => $guest]);
        }

        return $this->render('show', $ticket->getReference().' '.$ticket->subject, $components);
    }

    protected function find(string $ticket): ?Ticket
    {
        if (! ctype_digit($ticket)) {
            return null;
        }

        $model = AlfakitHelpdesk::ticketModel();
        $record = $model::query()->whereKey((int) $ticket)->first();

        return $record instanceof Ticket ? $record : null;
    }

    protected function requireCustomer(): ?RedirectResponse
    {
        abort_unless((bool) config('alfakit-helpdesk.portal.enabled', true), 404);

        if (AlfakitHelpdesk::customer() !== null) {
            return null;
        }

        $login = config('alfakit-helpdesk.portal.login_url');
        $login = is_string($login) && $login !== '' ? $login : (Route::has('login') ? route('login') : null);

        abort_if($login === null, 403);

        return redirect()->guest($login);
    }

    /**
     * @param  array<int, \AlfaSix\Alfakit\Standalone\Component>  $components
     */
    protected function render(string $view, string $title, array $components): Response
    {
        return Inertia::render((string) config('alfakit-helpdesk.page', 'alfakit-helpdesk/portal'), [
            'components' => array_map(static fn ($component): array => $component->toPayload(), $components),
            'meta' => [
                'view' => $view,
                'title' => $title,
                'appName' => (string) config('app.name'),
                'signedIn' => AlfakitHelpdesk::customer() !== null,
                // "Powered by AlfaKit · Alfa Six" (`HelpdeskPlugin::poweredBy()`), off by default.
                'poweredBy' => AlfakitHelpdesk::plugin()->getPoweredByHtml(),
                'links' => array_filter([
                    'portal' => config('alfakit-helpdesk.portal.enabled', true) ? AlfakitHelpdesk::route('portal.index') : null,
                    'newTicket' => config('alfakit-helpdesk.portal.enabled', true) ? AlfakitHelpdesk::route('portal.create') : null,
                    'contact' => config('alfakit-helpdesk.contact.enabled', true) ? AlfakitHelpdesk::route('contact') : null,
                ]),
                'labels' => [
                    'portal' => __('alfakit-helpdesk::helpdesk.portal.heading'),
                    'newTicket' => __('alfakit-helpdesk::helpdesk.portal.new_heading'),
                    'contact' => __('alfakit-helpdesk::helpdesk.contact.heading'),
                    'navigation' => __('alfakit-helpdesk::helpdesk.portal.navigation'),
                ],
            ],
        ]);
    }
}
