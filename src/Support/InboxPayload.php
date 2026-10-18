<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Support;

use AlfaSix\AlfakitHelpdesk\Actions\TicketActions;
use AlfaSix\AlfakitHelpdesk\Enums\MessageKind;
use AlfaSix\AlfakitHelpdesk\Enums\SlaState;
use AlfaSix\AlfakitHelpdesk\HelpdeskPlugin;
use AlfaSix\AlfakitHelpdesk\Models\Ticket;
use AlfaSix\AlfakitHelpdesk\Models\TicketMessage;
use AlfaSix\AlfakitHelpdesk\Pages\Inbox;
use AlfaSix\AlfakitHelpdesk\Sla\Sla;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * What the inbox renders: the views with their counts, a page of tickets, and one
 * ticket with its whole conversation (agents see internal notes too).
 */
final class InboxPayload
{
    public const PER_PAGE = 30;

    /**
     * @param  Builder<Model>  $query  the tenant-scoped ticket query
     * @return array<string, mixed>
     */
    public static function list(Builder $query, string $view, string $search, int $page, ?Model $agent): array
    {
        $views = [];

        foreach (Views::keys() as $key) {
            $views[] = array_filter([
                'key' => $key,
                'label' => Views::label($key),
                'icon' => Views::icon($key),
                'color' => Views::color($key),
                'count' => in_array($key, [Views::MINE, Views::UNASSIGNED, Views::DUE_SOON, Views::BREACHED, Views::ACTIVE], true)
                    ? Views::apply(clone $query, $key, $agent)->count()
                    : null,
            ], static fn (mixed $value): bool => $value !== null);
        }

        $tickets = Views::apply(clone $query, $view, $agent)->with(['assignee', 'requester']);

        if ($search !== '') {
            $like = '%'.mb_strtolower($search).'%';
            $grammar = $tickets->getQuery()->getGrammar();

            $tickets->where(static function (Builder $query) use ($like, $search, $grammar): void {
                foreach (['subject', 'requester_name', 'requester_email'] as $column) {
                    $query->orWhereRaw('LOWER('.$grammar->wrap($query->qualifyColumn($column)).') LIKE ?', [$like]);
                }

                if (ctype_digit(ltrim($search, '#'))) {
                    $query->orWhere($query->qualifyColumn('id'), (int) ltrim($search, '#'));
                }
            });
        }

        $records = Views::order($tickets, $view)->offset(($page - 1) * self::PER_PAGE)->limit(self::PER_PAGE + 1)->get();
        $hasMore = $records->count() > self::PER_PAGE;
        $items = [];

        foreach ($records->take(self::PER_PAGE) as $ticket) {
            if ($ticket instanceof Ticket) {
                $items[] = self::listItem($ticket);
            }
        }

        return array_filter([
            'view' => $view,
            'search' => $search !== '' ? $search : null,
            'page' => $page,
            'hasMore' => $hasMore ?: null,
            'views' => $views,
            'tickets' => $items,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    public static function listItem(Ticket $ticket): array
    {
        $last = $ticket->messages()->reorder()->where('kind', '!=', MessageKind::Event->value)->latest('created_at')->latest('id')->first();
        $summary = Sla::summary($ticket);

        return array_filter([
            'key' => (string) $ticket->getKey(),
            'reference' => $ticket->getReference(),
            'subject' => $ticket->subject,
            'requester' => $ticket->getRequesterName(),
            'excerpt' => $last instanceof TicketMessage ? MessageBody::excerpt($last->body, 120) : null,
            'excerptInternal' => $last instanceof TicketMessage && $last->isInternal() ? true : null,
            'awaitingReply' => $last instanceof TicketMessage && $last->from_requester && $ticket->getStatus()->isActive() ? true : null,
            'status' => self::enum($ticket->getStatus()->value, $ticket->getStatus()->getLabel(), $ticket->getStatus()->getColor()),
            'priority' => self::enum($ticket->getPriority()->value, $ticket->getPriority()->getLabel(), $ticket->getPriority()->getColor()),
            'sla' => self::sla($summary['state'], $summary['due']),
            'assignee' => $ticket->assignee_id !== null && $ticket->assignee !== null ? Agents::name($ticket->assignee) : null,
            'channel' => $ticket->getChannel()->value,
            'channelIcon' => $ticket->getChannel()->getIcon(),
            'lastActivityAt' => self::date($ticket->last_activity_at ?? $ticket->created_at),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    public static function ticket(Ticket $ticket, Inbox $page, ?Model $agent): array
    {
        $ticket->loadMissing(['assignee', 'group', 'requester']);
        $summary = Sla::summary($ticket);
        $panel = $page->getPanel();
        $messages = [];

        foreach ($ticket->messages()->with('author')->get() as $message) {
            $messages[] = self::message($message);
        }

        $tags = self::tags($ticket);

        return array_filter([
            'key' => (string) $ticket->getKey(),
            'reference' => $ticket->getReference(),
            'subject' => $ticket->subject,
            'url' => Links::forAgent($ticket),
            'status' => self::enum($ticket->getStatus()->value, $ticket->getStatus()->getLabel(), $ticket->getStatus()->getColor()),
            'priority' => self::enum($ticket->getPriority()->value, $ticket->getPriority()->getLabel(), $ticket->getPriority()->getColor()),
            'type' => ['value' => $ticket->getType()->value, 'label' => $ticket->getType()->getLabel()],
            'channel' => ['value' => $ticket->getChannel()->value, 'label' => $ticket->getChannel()->getLabel(), 'icon' => $ticket->getChannel()->getIcon()],
            'requester' => array_filter(['name' => $ticket->getRequesterName(), 'email' => $ticket->getRequesterEmail()], static fn (mixed $value): bool => $value !== null),
            'assignee' => $ticket->assignee_id !== null && $ticket->assignee !== null ? ['key' => (string) $ticket->assignee->getKey(), 'name' => Agents::name($ticket->assignee), 'avatar' => Agents::avatar($ticket->assignee)] : null,
            'group' => $ticket->group === null ? null : ['key' => (string) $ticket->group->getKey(), 'name' => $ticket->group->name],
            'tags' => $tags === [] ? null : $tags,
            'sla' => array_filter([
                'summary' => self::sla($summary['state'], $summary['due']),
                'firstResponse' => $ticket->first_response_due_at === null ? null : self::sla(Sla::firstResponseState($ticket), $ticket->first_response_due_at),
                'resolution' => $ticket->resolution_due_at === null ? null : self::sla(Sla::resolutionState($ticket), $ticket->resolution_due_at),
                'policy' => $ticket->sla_policy,
            ], static fn (mixed $value): bool => $value !== null),
            'satisfaction' => $ticket->satisfaction_rated_at === null ? null : array_filter([
                'rating' => $ticket->satisfaction_rating,
                'comment' => $ticket->satisfaction_comment,
            ], static fn (mixed $value): bool => $value !== null),
            'merged' => $ticket->isMerged() ?: null,
            'createdAt' => self::date($ticket->created_at),
            'messages' => $messages,
            'typing' => Typing::requesterTyping($ticket) ? true : null,
            'actions' => $page->serializeTicketActions($ticket),
            'canReply' => TicketActions::canUpdate($ticket) && ! $ticket->isMerged() ? true : null,
            'presence' => $panel->getRecordPresenceSchema($ticket, 'viewing', $agent instanceof Authenticatable ? $agent : null),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    public static function message(TicketMessage $message): array
    {
        $author = $message->author_id !== null ? $message->author : null;

        return array_filter([
            'key' => (string) $message->getKey(),
            'kind' => $message->getKind()->value,
            'fromRequester' => $message->from_requester ?: null,
            'author' => array_filter([
                'name' => $message->getAuthorName(),
                'avatar' => $message->getKind() === MessageKind::Event ? null : Agents::avatar($author),
            ], static fn (mixed $value): bool => $value !== null),
            'html' => $message->body,
            'source' => $message->source,
            'createdAt' => self::date($message->created_at),
            'attachments' => Attachments::forDisplay($message) ?: null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * The ticket's tags: spatie/laravel-tags names, or the `labels` column.
     *
     * @return array<int, string>
     */
    public static function tags(Ticket $ticket): array
    {
        if (HelpdeskPlugin::get()->usesSpatieTags() && method_exists($ticket, 'tags')) {
            $names = [];

            foreach ($ticket->getRelationValue('tags') ?? [] as $tag) {
                $name = $tag instanceof Model ? $tag->getAttribute('name') : null;
                $name = is_array($name) ? ($name[app()->getLocale()] ?? reset($name)) : $name;

                if (is_string($name) && $name !== '') {
                    $names[] = $name;
                }
            }

            return $names;
        }

        return array_values(array_filter($ticket->labels ?? [], is_string(...)));
    }

    /**
     * @return array{value: string, label: string, color: string}
     */
    private static function enum(string $value, string|Htmlable|null $label, string $color): array
    {
        return ['value' => $value, 'label' => $label instanceof Htmlable ? $label->toHtml() : (string) $label, 'color' => $color];
    }

    /**
     * @return array<string, string>
     */
    private static function sla(SlaState $state, ?DateTimeInterface $due): array
    {
        return array_filter([
            'state' => $state->value,
            'label' => $state->getLabel(),
            'color' => $state->getColor(),
            'dueAt' => self::date($due),
        ], static fn (mixed $value): bool => $value !== null);
    }

    private static function date(?DateTimeInterface $date): ?string
    {
        return $date?->format(DateTimeInterface::ATOM);
    }
}
