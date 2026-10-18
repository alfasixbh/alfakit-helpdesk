<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Pages;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Actions\Exceptions\ActionNotResolvableException;
use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Realtime\RealtimeChannels;
use AlfaSix\Alfakit\Support\Authorization;
use AlfaSix\Alfakit\Support\Enums\Width;
use AlfaSix\AlfakitHelpdesk\Actions\TicketActions;
use AlfaSix\AlfakitHelpdesk\AlfakitHelpdesk;
use AlfaSix\AlfakitHelpdesk\Enums\TicketStatus;
use AlfaSix\AlfakitHelpdesk\HelpdeskPlugin;
use AlfaSix\AlfakitHelpdesk\Models\Ticket;
use AlfaSix\AlfakitHelpdesk\Realtime\HelpdeskBroadcaster;
use AlfaSix\AlfakitHelpdesk\Resources\TicketResource;
use AlfaSix\AlfakitHelpdesk\Support\InboxPayload;
use AlfaSix\AlfakitHelpdesk\Support\MessageBody;
use AlfaSix\AlfakitHelpdesk\Support\Tickets;
use AlfaSix\AlfakitHelpdesk\Support\Typing;
use AlfaSix\AlfakitHelpdesk\Support\Views;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The agent inbox: views (mine, unassigned, due soon, breached…), the ticket list and the
 * conversation side by side, with a quick composer and keyboard shortcuts (`j` / `k` to
 * move, `r` to reply, `n` for a note, `e` to solve). Real time with `Panel::realtime()`
 * (new messages, typing, who else is on the ticket), polling otherwise.
 *
 * Everything runs through the action endpoint as the `helpdesk` component: the list and
 * the ticket are fetched by JSON actions, and the ticket actions are the same as on the
 * ticket page. The context token, panel access, tenant and ticket policy are checked on
 * every request.
 */
class Inbox extends Page
{
    /** The action context of the inbox's requests. */
    public const COMPONENT = 'helpdesk';

    public const LIST_ACTION = 'helpdeskList';

    public const TICKET_ACTION = 'helpdeskTicket';

    public const TYPING_ACTION = 'helpdeskTyping';

    public const QUICK_REPLY_ACTION = 'helpdeskQuickReply';

    /** The renderer sends its parameters under this argument. */
    public const ARGUMENTS_KEY = 'helpdesk';

    protected static ?string $slug = 'helpdesk/inbox';

    protected static ?string $navigationIcon = 'inbox';

    protected static bool $isDiscovered = false;

    protected static Width|string|null $maxContentWidth = Width::Full;

    /** @var array<string, Action>|null */
    protected ?array $cachedInboxActions = null;

    public static function canAccess(): bool
    {
        return static::getTicketResource()::canViewAny();
    }

    /**
     * @return class-string<TicketResource>
     */
    public static function getTicketResource(): string
    {
        return HelpdeskPlugin::get()->getTicketResource();
    }

    public static function getNavigationLabel(): string
    {
        return (string) __('alfakit-helpdesk::helpdesk.inbox.title');
    }

    public static function getNavigationGroup(): ?string
    {
        return HelpdeskPlugin::get()->getNavigationGroup();
    }

    public static function getNavigationSort(): ?int
    {
        return HelpdeskPlugin::get()->getNavigationSort() ?? 0;
    }

    public static function getNavigationBadge(): string|int|null
    {
        try {
            $count = Views::apply(static::getTicketResource()::getEloquentQuery(), Views::MINE, TicketActions::agent())->count();
        } catch (Throwable) {
            return null;
        }

        return $count > 0 ? $count : null;
    }

    public function getTitle(): string
    {
        return (string) __('alfakit-helpdesk::helpdesk.inbox.title');
    }

    // ---------------------------------------------------------------- Content

    protected function getContentBlocks(): array
    {
        return [[
            'type' => 'Schema',
            'key' => 'helpdesk-inbox',
            'components' => [[...$this->serializeInbox(), 'columnSpan' => ['default' => 'full']]],
        ]];
    }

    /**
     * The inbox component (`helpdesk:inbox` in every renderer's registry).
     *
     * @return array<string, mixed>
     */
    public function serializeInbox(): array
    {
        $view = $this->requestedView();
        $selected = request()->query('ticket');

        return array_filter([
            'type' => 'helpdesk:inbox',
            'key' => 'helpdesk-inbox',
            'actionComponent' => self::COMPONENT,
            'argumentsKey' => self::ARGUMENTS_KEY,
            'listAction' => self::LIST_ACTION,
            'ticketAction' => self::TICKET_ACTION,
            'typingAction' => self::TYPING_ACTION,
            'replyAction' => self::QUICK_REPLY_ACTION,
            'view' => $view,
            'list' => $this->listPayload($view, '', 1),
            'selected' => is_string($selected) && ctype_digit($selected) ? $selected : null,
            'live' => $this->liveSchema(),
            'pollingInterval' => HelpdeskPlugin::get()->getInboxPollingInterval(),
            'ticketsUrl' => static::getTicketResource()::getUrl(),
        ], static fn (mixed $value): bool => $value !== null);
    }

    protected function requestedView(): string
    {
        $view = request()->query('view');

        return is_string($view) && in_array($view, Views::keys(), true) ? $view : Views::MINE;
    }

    /**
     * `{channels, debounce}`: the ticket model's channel of this panel and tenant (empty
     * without `realtime()`: the renderer polls instead).
     *
     * @return array<string, mixed>
     */
    protected function liveSchema(): array
    {
        $panel = $this->getPanel();
        $channels = [];

        if ($panel->hasRealtime() && $panel->auth()->user() !== null) {
            $channel = RealtimeChannels::forModel($panel, AlfakitHelpdesk::ticketModel(), $panel->getTenant());

            if ($channel !== null) {
                $channels[] = $channel;
            }
        }

        return ['channels' => $channels, 'debounce' => 400];
    }

    /**
     * @return array<string, mixed>
     */
    public function listPayload(string $view, string $search, int $page): array
    {
        return InboxPayload::list($this->ticketQuery(), $view, $search, $page, TicketActions::agent());
    }

    /**
     * @return Builder<Model>
     */
    public function ticketQuery(): Builder
    {
        return static::getTicketResource()::getEloquentQuery();
    }

    public function findTicket(mixed $key): ?Ticket
    {
        if (! is_int($key) && ! (is_string($key) && ctype_digit($key))) {
            return null;
        }

        $ticket = $this->ticketQuery()->whereKey((int) $key)->first();

        return $ticket instanceof Ticket ? $ticket : null;
    }

    // ---------------------------------------------------------------- Actions

    /**
     * The inbox's JSON actions and the ticket actions, by name.
     *
     * @return array<string, Action>
     */
    protected function getInboxActions(): array
    {
        if ($this->cachedInboxActions !== null) {
            return $this->cachedInboxActions;
        }

        $actions = TicketActions::all(fn (): Builder => $this->ticketQuery());

        $actions[self::LIST_ACTION] = Action::make(self::LIST_ACTION)
            ->action(function (array $arguments): array {
                $raw = $this->arguments($arguments);
                $view = is_string($raw['view'] ?? null) && in_array($raw['view'], Views::keys(), true) ? $raw['view'] : Views::MINE;
                $search = is_string($raw['search'] ?? null) ? mb_substr(trim($raw['search']), 0, 200) : '';
                $page = is_numeric($raw['page'] ?? null) ? max(1, min(500, (int) $raw['page'])) : 1;

                return ['list' => $this->listPayload($view, $search, $page)];
            });

        $actions[self::TICKET_ACTION] = Action::make(self::TICKET_ACTION)
            ->authorize(static fn (?Model $record): bool => $record instanceof Ticket && Authorization::allows('view', $record))
            ->action(function (?Model $record): array {
                abort_unless($record instanceof Ticket, 404);

                return ['ticket' => InboxPayload::ticket($record, $this, TicketActions::agent())];
            });

        $actions[self::TYPING_ACTION] = Action::make(self::TYPING_ACTION)
            ->authorize(static fn (?Model $record): bool => TicketActions::canUpdate($record))
            ->action(function (?Model $record): array {
                abort_unless($record instanceof Ticket, 404);

                $agent = TicketActions::agent();
                $key = 'alfakit-helpdesk:agent-typing:'.$record->getKey().':'.($agent?->getKey() ?? 'guest');

                // One broadcast every few seconds per agent and ticket is plenty.
                if (! RateLimiter::tooManyAttempts($key, 1)) {
                    RateLimiter::hit($key, 3);
                    Typing::agent($record, \AlfaSix\AlfakitHelpdesk\Support\Agents::name($agent));
                    HelpdeskBroadcaster::dispatch($record, HelpdeskBroadcaster::TYPING, 'agent', $agent);
                }

                return ['ok' => true];
            });

        $actions[self::QUICK_REPLY_ACTION] = Action::make(self::QUICK_REPLY_ACTION)
            ->authorize(static fn (?Model $record): bool => TicketActions::canUpdate($record))
            ->action(function (array $arguments, ?Model $record): array {
                abort_unless($record instanceof Ticket, 404);

                $raw = $this->arguments($arguments);
                $text = is_string($raw['text'] ?? null) ? $raw['text'] : '';
                $internal = ($raw['internal'] ?? false) === true;
                $status = is_string($raw['status'] ?? null) ? TicketStatus::tryFrom($raw['status']) : null;

                if (trim($text) === '') {
                    throw ValidationException::withMessages([self::ARGUMENTS_KEY => __('alfakit-helpdesk::helpdesk.errors.empty')]);
                }

                if (mb_strlen($text) > 20000) {
                    throw ValidationException::withMessages([self::ARGUMENTS_KEY => __('validation.max.string', ['attribute' => 'text', 'max' => 20000])]);
                }

                $html = MessageBody::fromText($text);

                if ($internal) {
                    Tickets::note($record, $html, TicketActions::agent());
                } else {
                    Tickets::reply($record, $html, TicketActions::agent(), status: $status);
                }

                return ['ticket' => InboxPayload::ticket($record->refresh(), $this, TicketActions::agent())];
            });

        foreach ($actions as $action) {
            $action->context($this);
        }

        return $this->cachedInboxActions = $actions;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    protected function arguments(array $arguments): array
    {
        $raw = $arguments[self::ARGUMENTS_KEY] ?? null;

        return is_array($raw) ? $raw : [];
    }

    /**
     * Inbox actions resolve from the `helpdesk` component (with the posted ticket, found in
     * the tenant-scoped ticket query); everything else as usual.
     */
    public function resolveAction(?string $component, string $name, bool $isBulk = false): Action
    {
        if ($component !== self::COMPONENT) {
            return parent::resolveAction($component, $name, $isBulk);
        }

        $path = explode(Action::PATH_SEPARATOR, $name);
        $root = (string) array_shift($path);
        $action = $isBulk ? null : ($this->getInboxActions()[$root] ?? null);

        if ($action === null) {
            throw new ActionNotResolvableException("Action [{$name}] could not be resolved on [".static::class.'] for the inbox.');
        }

        if ($root !== self::LIST_ACTION) {
            $ticket = $this->findTicket(request()->input('record'));

            abort_if($ticket === null, 404);

            $action->record($ticket);
        }

        if ($path !== []) {
            $action = $action->getNestedModalAction($path) ?? throw new ActionNotResolvableException("Modal action [{$name}] could not be resolved on [".static::class.'].');
        }

        return $this->applyPageDatabaseTransactions($action);
    }

    /**
     * Serialised actions of a ticket (visible ones), for the reading pane.
     *
     * @return array<int, array<string, mixed>>
     */
    public function serializeTicketActions(Ticket $ticket): array
    {
        $serialized = [];

        foreach (['reply', 'note', 'solve', 'take', 'assign', 'status', 'details', 'merge'] as $name) {
            $action = $this->getInboxActions()[$name];
            $action->record($ticket);

            if ($action->isVisible() && $action->isAuthorized()) {
                $serialized[] = $action->toSchema();
            }
        }

        return $serialized;
    }

    protected function flushCaches(): void
    {
        parent::flushCaches();

        $this->cachedInboxActions = null;
    }
}
