<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Resources\TicketResource\Pages;

use AlfaSix\Alfakit\Resources\Pages\CreateRecord;
use AlfaSix\AlfakitHelpdesk\Actions\TicketActions;
use AlfaSix\AlfakitHelpdesk\AlfakitHelpdesk;
use AlfaSix\AlfakitHelpdesk\Enums\TicketChannel;
use AlfaSix\AlfakitHelpdesk\HelpdeskPlugin;
use AlfaSix\AlfakitHelpdesk\Resources\TicketResource;
use AlfaSix\AlfakitHelpdesk\Support\Tickets;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * An agent opens a ticket on behalf of a requester (a user, or an email address): the
 * message counts as the first response.
 */
class CreateTicket extends CreateRecord
{
    protected static string $resource = TicketResource::class;

    public static function getResource(): string
    {
        return HelpdeskPlugin::get()->getTicketResource();
    }

    protected function handleRecordCreation(array $data): Model
    {
        $requester = null;
        $model = AlfakitHelpdesk::userModel($this->getPanel()->getAuthGuard());

        if (filled($data['requester_id'] ?? null) && $model !== null) {
            $requester = $model::query()->whereKey($data['requester_id'])->first();

            if (! $requester instanceof Model) {
                throw ValidationException::withMessages(['data.requester_id' => __('validation.exists', ['attribute' => __('alfakit-helpdesk::helpdesk.fields.requester')])]);
            }
        }

        [$assignee, $group] = TicketActions::resolveAssignment($data);
        $tenant = $this->getPanelTenant();

        return Tickets::open([
            'subject' => $data['subject'] ?? '',
            'priority' => $data['priority'] ?? null,
            'type' => $data['type'] ?? null,
            'channel' => TicketChannel::Agent,
            'requester' => $requester,
            'requester_email' => $requester === null ? ($data['requester_email'] ?? null) : null,
            'requester_name' => $requester === null ? ($data['requester_name'] ?? null) : null,
            'assignee' => $assignee,
            'group_id' => $group,
            'labels' => $data['labels'] ?? null,
            'tenant' => $tenant !== null && static::getResource()::isScopedToTenant() ? $tenant : null,
        ], (string) ($data['body'] ?? ''), [
            'author' => TicketActions::agent(),
            'fromRequester' => false,
            'attachments' => TicketActions::attachments($data),
            'source' => 'agent',
        ]);
    }

    protected function getRedirectUrl(): string
    {
        $record = $this->getRecord();

        return $record === null ? static::getResource()::getUrl() : static::getResource()::getUrl('view', ['record' => $record]);
    }
}
