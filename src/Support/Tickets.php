<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Support;

use AlfaSix\AlfakitHelpdesk\AlfakitHelpdesk;
use AlfaSix\AlfakitHelpdesk\Enums\MessageKind;
use AlfaSix\AlfakitHelpdesk\Enums\TicketChannel;
use AlfaSix\AlfakitHelpdesk\Enums\TicketPriority;
use AlfaSix\AlfakitHelpdesk\Enums\TicketStatus;
use AlfaSix\AlfakitHelpdesk\Enums\TicketType;
use AlfaSix\AlfakitHelpdesk\Events\ReplyAdded;
use AlfaSix\AlfakitHelpdesk\Events\TicketAssigned;
use AlfaSix\AlfakitHelpdesk\Events\TicketCreated;
use AlfaSix\AlfakitHelpdesk\Events\TicketRated;
use AlfaSix\AlfakitHelpdesk\Events\TicketsMerged;
use AlfaSix\AlfakitHelpdesk\Events\TicketSolved;
use AlfaSix\AlfakitHelpdesk\Mail\AgentNotification;
use AlfaSix\AlfakitHelpdesk\Mail\AgentReplied;
use AlfaSix\AlfakitHelpdesk\Mail\TicketReceived;
use AlfaSix\AlfakitHelpdesk\Mail\TicketSolvedMail;
use AlfaSix\AlfakitHelpdesk\Models\Ticket;
use AlfaSix\AlfakitHelpdesk\Models\TicketMessage;
use AlfaSix\AlfakitHelpdesk\Realtime\HelpdeskBroadcaster;
use AlfaSix\AlfakitHelpdesk\Sla\Sla;
use BackedEnum;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Everything that changes a ticket goes through here, so the SLA clock, events, emails
 * and real-time broadcasts stay consistent wherever the change comes from (panel, portal,
 * chat, email or your own code).
 *
 * ```php
 * $ticket = Tickets::open(['subject' => 'Printer on fire', 'requester' => $user], '<p>Help!</p>');
 * Tickets::reply($ticket, '<p>On our way.</p>', author: $agent);
 * Tickets::changeStatus($ticket, TicketStatus::Solved, $agent);
 * ```
 */
final class Tickets
{
    /**
     * Open a ticket with its first message.
     *
     * `$attributes`: `subject`, `priority`, `type`, `channel`, `requester` (a user) or
     * `requester_email` / `requester_name`, `assignee` (an agent), `group_id`, `labels`,
     * `tenant` (a model). `$options`: `author` (who wrote the first message; the requester
     * by default), `fromRequester` (default true unless an agent opens it on behalf of
     * the requester with `author`), `attachments`, `notify` (emails, default true).
     *
     * @param  array<string, mixed>  $attributes
     * @param  array{author?: Model|null, fromRequester?: bool, attachments?: array<int, array<string, mixed>>, notify?: bool, source?: string}  $options
     */
    public static function open(array $attributes, ?string $html = null, array $options = []): Ticket
    {
        $model = AlfakitHelpdesk::ticketModel();
        $requester = $attributes['requester'] ?? null;
        $assignee = $attributes['assignee'] ?? null;
        $tenant = $attributes['tenant'] ?? null;
        $channel = self::enumValue($attributes['channel'] ?? null, TicketChannel::class, TicketChannel::Agent);
        $author = $options['author'] ?? ($requester instanceof Model ? $requester : null);
        $fromRequester = $options['fromRequester'] ?? ! (isset($options['author']) && $options['author'] !== $requester);

        /** @var Ticket $ticket */
        $ticket = new $model;
        $ticket->forceFill([
            'subject' => mb_substr(trim((string) ($attributes['subject'] ?? '')), 0, 255) ?: (string) __('alfakit-helpdesk::helpdesk.ticket.no_subject'),
            'status' => TicketStatus::Open->value,
            'priority' => self::enumValue($attributes['priority'] ?? null, TicketPriority::class, TicketPriority::Normal),
            'type' => self::enumValue($attributes['type'] ?? null, TicketType::class, TicketType::Question),
            'channel' => $channel,
            'labels' => self::labels($attributes['labels'] ?? null),
            'requester_id' => $requester instanceof Model ? $requester->getKey() : null,
            'requester_email' => self::email($attributes['requester_email'] ?? ($requester instanceof Model ? $requester->getAttribute('email') : null)),
            'requester_name' => self::name($attributes['requester_name'] ?? ($requester instanceof Model ? $requester->getAttribute('name') : null)),
            'assignee_id' => $assignee instanceof Model ? $assignee->getKey() : null,
            'group_id' => is_numeric($attributes['group_id'] ?? null) ? (int) $attributes['group_id'] : null,
            'last_activity_at' => now(),
        ]);

        if ($tenant instanceof Model) {
            $ticket->tenant()->associate($tenant);
        }

        $message = DB::transaction(static function () use ($ticket, $html, $author, $fromRequester, $options, $channel): ?TicketMessage {
            $ticket->created_at = CarbonImmutable::now();
            Sla::apply($ticket);
            $ticket->save();

            if (MessageBody::isBlank($html)) {
                return null;
            }

            $message = self::createMessage($ticket, MessageKind::Reply, (string) $html, $author, $fromRequester, $options['attachments'] ?? [], $options['source'] ?? $channel);

            // An agent opening a ticket on behalf of the requester has answered it.
            if (! $fromRequester && $ticket->first_responded_at === null) {
                $ticket->first_responded_at = CarbonImmutable::now();
                $ticket->save();
            }

            return $message;
        });

        TicketCreated::dispatch($ticket, $message);
        HelpdeskBroadcaster::dispatch($ticket, HelpdeskBroadcaster::UPDATED, $fromRequester ? 'requester' : 'agent', $author);

        if ($options['notify'] ?? true) {
            if ($ticket->getChannel() !== TicketChannel::Chat && $fromRequester) {
                self::mailRequester($ticket, new TicketReceived($ticket));
            }

            if ($fromRequester) {
                self::mailAgents($ticket, new AgentNotification($ticket, $message, isNew: true));
            }
        }

        return $ticket;
    }

    /**
     * Add a public reply. From the requester it reopens a pending or solved ticket; from an
     * agent it counts as the first response and is emailed to the requester. `$status`
     * changes the status in the same step ("Reply and set pending").
     *
     * @param  array<int, array<string, mixed>>  $attachments
     */
    public static function reply(Ticket $ticket, string $html, ?Model $author = null, bool $fromRequester = false, array $attachments = [], ?string $source = null, ?TicketStatus $status = null, bool $notify = true): TicketMessage
    {
        self::ensureOpenForReplies($ticket, $fromRequester);

        $message = DB::transaction(static function () use ($ticket, $html, $author, $fromRequester, $attachments, $source, $status): TicketMessage {
            $message = self::createMessage($ticket, MessageKind::Reply, $html, $author, $fromRequester, $attachments, $source ?? ($fromRequester ? TicketChannel::Portal->value : 'agent'));
            $now = CarbonImmutable::now();

            if ($fromRequester) {
                $ticket->requester_seen_at = $now;

                if ($ticket->getStatus() === TicketStatus::Solved) {
                    self::reopen($ticket);
                } elseif ($ticket->getStatus() === TicketStatus::Pending) {
                    Sla::resume($ticket, $now);
                    $ticket->status = TicketStatus::Open->value;
                }
            } elseif ($ticket->first_responded_at === null) {
                $ticket->first_responded_at = $now;
            }

            $ticket->last_activity_at = $now;
            $ticket->save();

            if ($status !== null && $status !== $ticket->getStatus()) {
                self::applyStatus($ticket, $status, $author);
            }

            return $message;
        });

        if ($fromRequester) {
            Typing::stopRequester($ticket);
        } else {
            Typing::stopAgent($ticket);
        }

        ReplyAdded::dispatch($ticket, $message);
        HelpdeskBroadcaster::dispatch($ticket, HelpdeskBroadcaster::MESSAGE, $fromRequester ? 'requester' : 'agent', $author);

        if ($notify) {
            if ($fromRequester) {
                self::mailAgents($ticket, new AgentNotification($ticket, $message));
            } elseif ($ticket->getChannel() !== TicketChannel::Chat) {
                self::mailRequester($ticket, new AgentReplied($ticket, $message));
            }
        }

        if ($status === TicketStatus::Solved) {
            self::solved($ticket, $author);
        }

        return $message;
    }

    /**
     * Add an internal note: agents only, never shown or emailed to the requester.
     *
     * @param  array<int, array<string, mixed>>  $attachments
     */
    public static function note(Ticket $ticket, string $html, ?Model $author, array $attachments = []): TicketMessage
    {
        $message = DB::transaction(static function () use ($ticket, $html, $author, $attachments): TicketMessage {
            $message = self::createMessage($ticket, MessageKind::Note, $html, $author, false, $attachments, 'agent');
            $ticket->last_activity_at = CarbonImmutable::now();
            $ticket->save();

            return $message;
        });

        ReplyAdded::dispatch($ticket, $message);
        HelpdeskBroadcaster::dispatch($ticket, HelpdeskBroadcaster::MESSAGE, 'agent', $author);

        return $message;
    }

    /**
     * Record a change in the conversation (agents only), e.g. "Dana set the status to Solved".
     */
    public static function event(Ticket $ticket, string $text, ?Model $author = null): TicketMessage
    {
        return self::createMessage($ticket, MessageKind::Event, MessageBody::fromText($text), $author, false, [], 'system');
    }

    public static function assign(Ticket $ticket, ?Model $agent, ?Model $by = null, bool $changeGroup = false, ?int $groupId = null): void
    {
        $previous = $ticket->assignee_id !== null ? $ticket->assignee : null;
        $sameAgent = (string) ($previous?->getKey() ?? '') === (string) ($agent?->getKey() ?? '');
        $sameGroup = ! $changeGroup || $ticket->group_id === $groupId;

        if ($sameAgent && $sameGroup) {
            return;
        }

        DB::transaction(static function () use ($ticket, $agent, $by, $changeGroup, $groupId, $sameAgent): void {
            $ticket->assignee_id = $agent?->getKey();

            if ($changeGroup) {
                $ticket->group_id = $groupId;
            }

            $ticket->save();

            if (! $sameAgent) {
                self::event($ticket, $agent === null
                    ? (string) __('alfakit-helpdesk::helpdesk.events.unassigned', ['name' => self::actor($by)])
                    : (string) __('alfakit-helpdesk::helpdesk.events.assigned', ['name' => self::actor($by), 'agent' => Agents::name($agent)]), $by);
            }
        });

        $ticket->unsetRelation('assignee');

        TicketAssigned::dispatch($ticket, $agent, $previous);
        HelpdeskBroadcaster::dispatch($ticket, HelpdeskBroadcaster::UPDATED, 'agent', $by);
    }

    public static function changeStatus(Ticket $ticket, TicketStatus $status, ?Model $by = null): void
    {
        if ($ticket->getStatus() === $status) {
            return;
        }

        if ($ticket->isMerged()) {
            throw ValidationException::withMessages(['status' => __('alfakit-helpdesk::helpdesk.errors.merged')]);
        }

        DB::transaction(static fn () => self::applyStatus($ticket, $status, $by));

        HelpdeskBroadcaster::dispatch($ticket, HelpdeskBroadcaster::UPDATED, 'agent', $by);

        if ($status === TicketStatus::Solved) {
            self::solved($ticket, $by);
        }
    }

    /**
     * Change the subject, priority, type or labels. The SLA targets follow the policy of the
     * new priority and type.
     *
     * @param  array{subject?: string, priority?: TicketPriority|string, type?: TicketType|string, labels?: array<int, string>|null}  $changes
     */
    public static function update(Ticket $ticket, array $changes, ?Model $by = null): void
    {
        if (array_key_exists('subject', $changes) && trim($changes['subject']) !== '') {
            $ticket->subject = mb_substr(trim($changes['subject']), 0, 255);
        }

        if (array_key_exists('priority', $changes)) {
            $ticket->priority = self::enumValue($changes['priority'], TicketPriority::class, $ticket->getPriority());
        }

        if (array_key_exists('type', $changes)) {
            $ticket->type = self::enumValue($changes['type'], TicketType::class, $ticket->getType());
        }

        if (array_key_exists('labels', $changes)) {
            $ticket->labels = self::labels($changes['labels']);
        }

        if (! $ticket->isDirty()) {
            return;
        }

        if ($ticket->isDirty(['priority', 'type'])) {
            Sla::apply($ticket);
        }

        $ticket->save();

        HelpdeskBroadcaster::dispatch($ticket, HelpdeskBroadcaster::UPDATED, 'agent', $by);
    }

    /**
     * Merge `$source` into `$target`: its conversation moves to the target and it is closed.
     * Both must belong to the same requester and tenant (another requester would otherwise
     * see the conversation).
     *
     * @throws ValidationException
     */
    public static function merge(Ticket $source, Ticket $target, ?Model $by = null): void
    {
        if ($source->is($target)) {
            throw ValidationException::withMessages(['target' => __('alfakit-helpdesk::helpdesk.errors.merge_self')]);
        }

        if ($source->isMerged() || $target->isMerged() || $target->getStatus() === TicketStatus::Closed) {
            throw ValidationException::withMessages(['target' => __('alfakit-helpdesk::helpdesk.errors.merged')]);
        }

        if ((string) $source->tenant_type !== (string) $target->tenant_type || (string) $source->tenant_id !== (string) $target->tenant_id || ! self::sameRequester($source, $target)) {
            throw ValidationException::withMessages(['target' => __('alfakit-helpdesk::helpdesk.errors.merge_requester')]);
        }

        DB::transaction(static function () use ($source, $target, $by): void {
            $messages = AlfakitHelpdesk::messageModel();
            $messages::query()->where('ticket_id', $source->getKey())->update(['ticket_id' => $target->getKey()]);

            $source->forceFill([
                'merged_into_id' => $target->getKey(),
                'status' => TicketStatus::Closed->value,
                'closed_at' => CarbonImmutable::now(),
            ])->save();

            self::event($source, (string) __('alfakit-helpdesk::helpdesk.events.merged_into', ['name' => self::actor($by), 'reference' => $target->getReference()]), $by);
            self::event($target, (string) __('alfakit-helpdesk::helpdesk.events.merged_from', ['name' => self::actor($by), 'reference' => $source->getReference(), 'subject' => $source->subject]), $by);

            $target->forceFill(['last_activity_at' => CarbonImmutable::now()])->save();
        });

        TicketsMerged::dispatch($source, $target);
        HelpdeskBroadcaster::dispatch($source, HelpdeskBroadcaster::UPDATED, 'agent', $by);
        HelpdeskBroadcaster::dispatch($target, HelpdeskBroadcaster::MESSAGE, 'agent', $by);
    }

    /**
     * The requester rates a solved ticket (1 to 5).
     *
     * @throws ValidationException
     */
    public static function rate(Ticket $ticket, int $rating, ?string $comment = null): void
    {
        if (! $ticket->canBeRated()) {
            throw ValidationException::withMessages(['rating' => __('alfakit-helpdesk::helpdesk.errors.not_ratable')]);
        }

        $ticket->forceFill([
            'satisfaction_rating' => max(1, min(5, $rating)),
            'satisfaction_comment' => ($comment = trim((string) $comment)) === '' ? null : mb_substr($comment, 0, 2000),
            'satisfaction_rated_at' => CarbonImmutable::now(),
        ])->save();

        TicketRated::dispatch($ticket);
        HelpdeskBroadcaster::dispatch($ticket, HelpdeskBroadcaster::UPDATED, 'requester');
    }

    /** Mark the conversation as seen by the requester. */
    public static function seenByRequester(Ticket $ticket): void
    {
        $ticket->forceFill(['requester_seen_at' => CarbonImmutable::now()])->saveQuietly();
    }

    // ---------------------------------------------------------------- Internals

    /**
     * @param  array<int, array<string, mixed>>  $attachments
     */
    private static function createMessage(Ticket $ticket, MessageKind $kind, string $html, ?Model $author, bool $fromRequester, array $attachments, string $source): TicketMessage
    {
        $model = AlfakitHelpdesk::messageModel();

        /** @var TicketMessage $message */
        $message = new $model;
        $message->forceFill([
            'ticket_id' => $ticket->getKey(),
            'kind' => $kind->value,
            'source' => mb_substr($source, 0, 20),
            'from_requester' => $fromRequester,
            'author_name' => $author === null ? ($fromRequester ? $ticket->requester_name : null) : self::name($author->getAttribute('name')),
            'author_email' => $author === null ? ($fromRequester ? $ticket->requester_email : null) : self::email($author->getAttribute('email')),
            'body' => $kind === MessageKind::Event ? $html : MessageBody::sanitize($html),
            'attachments' => self::cleanAttachments($attachments) ?: null,
        ]);

        if ($author !== null) {
            $message->author()->associate($author);
        }

        $message->save();
        $message->setRelation('ticket', $ticket);

        return $message;
    }

    private static function applyStatus(Ticket $ticket, TicketStatus $status, ?Model $by): void
    {
        $previous = $ticket->getStatus();
        $now = CarbonImmutable::now();

        if ($previous === TicketStatus::Pending) {
            Sla::resume($ticket, $now);
        }

        match ($status) {
            TicketStatus::Pending => Sla::pause($ticket, $now),
            TicketStatus::Solved => $ticket->solved_at = $now,
            TicketStatus::Closed => $ticket->closed_at = $now,
            TicketStatus::Open => null,
        };

        if ($status === TicketStatus::Open && $previous === TicketStatus::Solved) {
            self::reopen($ticket);
        }

        if ($status === TicketStatus::Closed && $ticket->solved_at === null) {
            $ticket->solved_at = $now;
        }

        $ticket->status = $status->value;
        $ticket->last_activity_at = $now;
        $ticket->save();

        self::event($ticket, (string) __('alfakit-helpdesk::helpdesk.events.status', ['name' => self::actor($by), 'status' => $status->getLabel()]), $by);
    }

    private static function solved(Ticket $ticket, ?Model $by): void
    {
        TicketSolved::dispatch($ticket, $by instanceof Authenticatable ? $by : null);

        self::mailRequester($ticket, new TicketSolvedMail($ticket));
    }

    /** A solved ticket is open again: it gets a fresh resolution target. */
    private static function reopen(Ticket $ticket): void
    {
        $ticket->status = TicketStatus::Open->value;
        $ticket->solved_at = null;
        $ticket->sla_paused_at = null;
        $ticket->satisfaction_rating = null;
        $ticket->satisfaction_comment = null;
        $ticket->satisfaction_rated_at = null;

        $policy = Sla::policyFor($ticket);
        $minutes = $policy?->getResolutionMinutes();
        $ticket->resolution_due_at = $policy === null || $minutes === null ? null : Sla::clockFor($ticket)->addMinutes(CarbonImmutable::now(), $minutes);
    }

    private static function ensureOpenForReplies(Ticket $ticket, bool $fromRequester): void
    {
        if ($ticket->isMerged() || ($fromRequester && $ticket->getStatus() === TicketStatus::Closed)) {
            throw ValidationException::withMessages(['body' => __('alfakit-helpdesk::helpdesk.errors.closed')]);
        }
    }

    private static function actor(?Model $by): string
    {
        return $by === null ? (string) __('alfakit-helpdesk::helpdesk.message.system') : Agents::name($by);
    }

    private static function sameRequester(Ticket $a, Ticket $b): bool
    {
        if ($a->requester_id !== null || $b->requester_id !== null) {
            return (string) $a->requester_id === (string) $b->requester_id;
        }

        return $a->requester_email !== null && mb_strtolower($a->requester_email) === mb_strtolower((string) $b->requester_email);
    }

    public static function mailRequester(Ticket $ticket, Mailable $mail): void
    {
        if (! config('alfakit-helpdesk.mail.enabled', true)) {
            return;
        }

        $user = $ticket->requester_id !== null ? $ticket->requester : null;
        $email = $ticket->getRequesterEmail();

        try {
            if ($user !== null && is_string($user->getAttribute('email')) && $user->getAttribute('email') !== '') {
                Mail::to($user)->send($mail);
            } elseif ($email !== null) {
                Mail::to($email)->send($mail);
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Email the agents of a ticket: its assignee, else its group's agents, else
     * `mail.agent_emails`. One email each, so addresses aren't shared.
     */
    public static function mailAgents(Ticket $ticket, Mailable $mail): void
    {
        if (! config('alfakit-helpdesk.mail.enabled', true)) {
            return;
        }

        $recipients = [];

        if ($ticket->assignee_id !== null && $ticket->assignee !== null) {
            $recipients[] = $ticket->assignee;
        } elseif ($ticket->group_id !== null && $ticket->group !== null) {
            $recipients = $ticket->group->agents()->get()->all();
        } else {
            $emails = config('alfakit-helpdesk.mail.agent_emails', []);
            $recipients = is_array($emails) ? array_values(array_filter($emails, static fn (mixed $email): bool => is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false)) : [];
        }

        foreach ($recipients as $recipient) {
            try {
                Mail::to($recipient)->send(clone $mail);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    /**
     * @template T of \BackedEnum
     *
     * @param  class-string<T>  $enum
     * @param  T  $default
     */
    private static function enumValue(mixed $value, string $enum, BackedEnum $default): string
    {
        if ($value instanceof $enum) {
            return (string) $value->value;
        }

        $case = is_string($value) ? $enum::tryFrom($value) : null;

        return (string) ($case ?? $default)->value;
    }

    /**
     * @return array<int, string>|null
     */
    private static function labels(mixed $labels): ?array
    {
        if (! is_array($labels)) {
            return null;
        }

        $clean = [];

        foreach ($labels as $label) {
            if (is_string($label) && ($label = mb_substr(trim($label), 0, 50)) !== '') {
                $clean[mb_strtolower($label)] = $label;
            }
        }

        return $clean === [] ? null : array_values(array_slice($clean, 0, 20));
    }

    private static function email(mixed $email): ?string
    {
        return is_string($email) && filter_var($email = trim($email), FILTER_VALIDATE_EMAIL) !== false ? mb_substr($email, 0, 255) : null;
    }

    private static function name(mixed $name): ?string
    {
        return is_string($name) && ($name = trim(strip_tags($name))) !== '' ? mb_substr($name, 0, 255) : null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $attachments
     * @return array<int, array{path: string, name: string, size: int|null, mime: string|null}>
     */
    private static function cleanAttachments(array $attachments): array
    {
        $clean = [];

        foreach ($attachments as $attachment) {
            if (! is_array($attachment) || ! is_string($attachment['path'] ?? null) || $attachment['path'] === '') {
                continue;
            }

            $clean[] = [
                'path' => $attachment['path'],
                'name' => is_string($attachment['name'] ?? null) ? mb_substr(basename($attachment['name']), 0, 200) : basename($attachment['path']),
                'size' => is_int($attachment['size'] ?? null) ? $attachment['size'] : null,
                'mime' => is_string($attachment['mime'] ?? null) ? $attachment['mime'] : null,
            ];
        }

        return array_slice($clean, 0, 20);
    }
}
