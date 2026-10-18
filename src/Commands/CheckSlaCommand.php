<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Commands;

use AlfaSix\Alfakit\Notifications\Actions\Action as NotificationAction;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\AlfakitHelpdesk\AlfakitHelpdesk;
use AlfaSix\AlfakitHelpdesk\Enums\SlaState;
use AlfaSix\AlfakitHelpdesk\Enums\TicketStatus;
use AlfaSix\AlfakitHelpdesk\Events\SlaBreached;
use AlfaSix\AlfakitHelpdesk\Models\Ticket;
use AlfaSix\AlfakitHelpdesk\Sla\Sla;
use AlfaSix\AlfakitHelpdesk\Support\Links;
use AlfaSix\AlfakitHelpdesk\Support\Tickets;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\Authenticatable;
use Throwable;

/**
 * Schedule every minute: warns once per ticket about a missed SLA target (the
 * `SlaBreached` event and a database notification to the assignee) and closes tickets
 * solved more than `close_solved_after_days` days ago.
 */
class CheckSlaCommand extends Command
{
    protected $signature = 'alfakit-helpdesk:sla';

    protected $description = 'Warn about breached helpdesk SLAs and close old solved tickets';

    public function handle(): int
    {
        $model = AlfakitHelpdesk::ticketModel();
        $now = CarbonImmutable::now();
        $breached = 0;

        $model::query()->breached($now)->whereNull('sla_breach_notified_at')->whereNull('merged_into_id')->with('assignee')
            ->chunkById(200, function ($tickets) use ($now, &$breached): void {
                foreach ($tickets as $ticket) {
                    $target = Sla::firstResponseState($ticket, $now) === SlaState::Breached ? 'first_response' : 'resolution';
                    $ticket->forceFill(['sla_breach_notified_at' => $now])->saveQuietly();

                    SlaBreached::dispatch($ticket, $target);
                    $this->notifyAssignee($ticket, $target);
                    $breached++;
                }
            });

        $closed = 0;
        $days = config('alfakit-helpdesk.close_solved_after_days', 4);

        if (is_numeric($days) && (int) $days > 0) {
            $model::query()->where('status', TicketStatus::Solved->value)->where('solved_at', '<=', $now->subDays((int) $days))
                ->chunkById(200, function ($tickets) use (&$closed): void {
                    foreach ($tickets as $ticket) {
                        Tickets::changeStatus($ticket, TicketStatus::Closed);
                        $closed++;
                    }
                });
        }

        $this->components->info("{$breached} breached SLA warning(s), {$closed} ticket(s) closed.");

        return self::SUCCESS;
    }

    protected function notifyAssignee(Ticket $ticket, string $target): void
    {
        $assignee = $ticket->assignee_id !== null ? $ticket->assignee : null;

        if (! $assignee instanceof Authenticatable || ! method_exists($assignee, 'notify')) {
            return;
        }

        try {
            Notification::make()
                ->danger()
                ->icon('triangle-alert')
                ->title(__('alfakit-helpdesk::helpdesk.notifications.breached', ['reference' => $ticket->getReference()]))
                ->body(__('alfakit-helpdesk::helpdesk.notifications.breached_body', ['subject' => $ticket->subject, 'target' => __('alfakit-helpdesk::helpdesk.sla.'.$target)]))
                ->actions(array_filter([
                    ($url = Links::forAgent($ticket)) === null ? null : NotificationAction::make('open')->label(__('alfakit-helpdesk::helpdesk.mail.open_ticket'))->url($url),
                ]))
                ->sendToDatabase($assignee);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
