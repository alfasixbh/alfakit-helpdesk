<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk;

use AlfaSix\Alfakit\Contracts\Plugin;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Support\Concerns\EvaluatesClosures;
use AlfaSix\Alfakit\Support\Concerns\HasPoweredByCredit;
use AlfaSix\AlfakitHelpdesk\Pages\Inbox;
use AlfaSix\AlfakitHelpdesk\Resources\CannedReplyResource;
use AlfaSix\AlfakitHelpdesk\Resources\HelpdeskGroupResource;
use AlfaSix\AlfakitHelpdesk\Resources\TicketResource;
use AlfaSix\AlfakitHelpdesk\Sla\BusinessHours;
use AlfaSix\AlfakitHelpdesk\Sla\SlaPolicy;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * A helpdesk in the panel: tickets, the agent inbox, canned replies, groups and reports.
 * The customer side (portal, contact form, live chat, emails) is configured in
 * `config/alfakit-helpdesk.php` and uses this plugin's options.
 *
 * ```php
 * $panel->plugin(
 *     HelpdeskPlugin::make()
 *         ->authorize(fn (User $user): bool => $user->is_support_agent)
 *         ->businessHours(BusinessHours::make('Asia/Bahrain')->days(['sun', 'mon', 'tue', 'wed', 'thu'], '08:00', '17:00'))
 *         ->slaPolicies([
 *             SlaPolicy::make('urgent')->priorities([TicketPriority::Urgent])->firstResponseWithin(30)->resolveWithin(240),
 *             SlaPolicy::make('standard')->firstResponseWithin(240)->resolveWithin(3 * 24 * 60),
 *         ]),
 * );
 * ```
 */
class HelpdeskPlugin implements Plugin
{
    use EvaluatesClosures;
    use HasPoweredByCredit;

    public const ID = 'helpdesk';

    /** @var class-string<TicketResource> */
    protected string $ticketResource = TicketResource::class;

    /** @var class-string<CannedReplyResource> */
    protected string $cannedReplyResource = CannedReplyResource::class;

    /** @var class-string<HelpdeskGroupResource> */
    protected string $groupResource = HelpdeskGroupResource::class;

    /** @var class-string<Inbox> */
    protected string $inboxPage = Inbox::class;

    protected bool $hasInbox = true;

    protected bool $hasCannedReplies = true;

    protected bool $hasGroups = true;

    protected bool $hasReports = true;

    protected bool|Closure|null $authorizeUsing = null;

    protected ?Closure $agentsUsing = null;

    protected ?BusinessHours $businessHours = null;

    /** @var array<int, SlaPolicy> */
    protected array $slaPolicies = [];

    protected int $dueSoonMinutes = 60;

    protected ?Closure $publicTenantUsing = null;

    protected string|Closure|null $navigationGroup = null;

    protected ?int $navigationSort = null;

    protected string|Closure|null $inboxPollingInterval = '20s';

    protected ?bool $usesSpatieTags = null;

    protected string|Closure|null $chatGreeting = null;

    /** @var array<int, string>|Closure */
    protected array|Closure $toolbarButtons = ['bold', 'italic', 'strike', 'link', '|', 'bulletList', 'orderedList', 'blockquote', 'codeBlock'];

    public static function make(): static
    {
        return new static;
    }

    /**
     * The plugin of the current panel (or of the helpdesk's panel outside panels), or one
     * with the default options.
     */
    public static function get(): static
    {
        $plugin = AlfakitHelpdesk::plugin();

        return $plugin instanceof static ? $plugin : static::make();
    }

    public function getId(): string
    {
        return self::ID;
    }

    public function register(Panel $panel): void
    {
        $resources = [$this->ticketResource];

        if ($this->hasCannedReplies) {
            $resources[] = $this->cannedReplyResource;
        }

        if ($this->hasGroups) {
            $resources[] = $this->groupResource;
        }

        $panel->resources($resources);

        if ($this->hasInbox) {
            $panel->pages([$this->inboxPage]);
        }

        $panel->frontendTranslations(['helpdesk' => 'alfakit-helpdesk::helpdesk.ui']);
    }

    public function boot(Panel $panel): void {}

    protected function getEvaluationIdentifier(): string
    {
        return 'plugin';
    }

    /**
     * @return array<mixed>
     */
    protected function resolveDefaultClosureDependencyForEvaluationByName(string $parameterName): array
    {
        $panel = alfakit()->getCurrentPanel() ?? AlfakitHelpdesk::panel();

        return match ($parameterName) {
            'panel' => [$panel],
            'user' => [$panel?->auth()->user()],
            'tenant' => [alfakit()->getTenant()],
            default => [],
        };
    }

    // ---------------------------------------------------------------- Pages and resources

    /**
     * @param  class-string<TicketResource>  $resource
     */
    public function ticketResource(string $resource): static
    {
        if (! is_a($resource, TicketResource::class, true)) {
            throw new InvalidArgumentException("The tickets resource [{$resource}] must extend [".TicketResource::class.'].');
        }

        $this->ticketResource = $resource;

        return $this;
    }

    /**
     * @return class-string<TicketResource>
     */
    public function getTicketResource(): string
    {
        return $this->ticketResource;
    }

    /**
     * @param  class-string<CannedReplyResource>  $resource
     */
    public function cannedReplyResource(string $resource): static
    {
        if (! is_a($resource, CannedReplyResource::class, true)) {
            throw new InvalidArgumentException("The canned replies resource [{$resource}] must extend [".CannedReplyResource::class.'].');
        }

        $this->cannedReplyResource = $resource;

        return $this;
    }

    /**
     * @return class-string<CannedReplyResource>
     */
    public function getCannedReplyResource(): string
    {
        return $this->cannedReplyResource;
    }

    /**
     * @param  class-string<HelpdeskGroupResource>  $resource
     */
    public function groupResource(string $resource): static
    {
        if (! is_a($resource, HelpdeskGroupResource::class, true)) {
            throw new InvalidArgumentException("The groups resource [{$resource}] must extend [".HelpdeskGroupResource::class.'].');
        }

        $this->groupResource = $resource;

        return $this;
    }

    /**
     * @return class-string<HelpdeskGroupResource>
     */
    public function getGroupResource(): string
    {
        return $this->groupResource;
    }

    /**
     * @param  class-string<Inbox>  $page
     */
    public function inboxPage(string $page): static
    {
        if (! is_a($page, Inbox::class, true)) {
            throw new InvalidArgumentException("The inbox page [{$page}] must extend [".Inbox::class.'].');
        }

        $this->inboxPage = $page;

        return $this;
    }

    /**
     * @return class-string<Inbox>
     */
    public function getInboxPage(): string
    {
        return $this->inboxPage;
    }

    public function inbox(bool $condition = true): static
    {
        $this->hasInbox = $condition;

        return $this;
    }

    public function hasInbox(): bool
    {
        return $this->hasInbox;
    }

    public function cannedReplies(bool $condition = true): static
    {
        $this->hasCannedReplies = $condition;

        return $this;
    }

    public function hasCannedReplies(): bool
    {
        return $this->hasCannedReplies;
    }

    public function groups(bool $condition = true): static
    {
        $this->hasGroups = $condition;

        return $this;
    }

    public function hasGroups(): bool
    {
        return $this->hasGroups;
    }

    /** The reports widget above the tickets list. */
    public function reports(bool $condition = true): static
    {
        $this->hasReports = $condition;

        return $this;
    }

    public function hasReports(): bool
    {
        return $this->hasReports;
    }

    public function navigationGroup(string|Closure|null $group): static
    {
        $this->navigationGroup = $group;

        return $this;
    }

    public function getNavigationGroup(): ?string
    {
        $group = $this->evaluate($this->navigationGroup);

        return is_string($group) && $group !== '' ? $group : (string) __('alfakit-helpdesk::helpdesk.navigation.group');
    }

    public function navigationSort(?int $sort): static
    {
        $this->navigationSort = $sort;

        return $this;
    }

    public function getNavigationSort(): ?int
    {
        return $this->navigationSort;
    }

    // ---------------------------------------------------------------- Agents and authorisation

    /**
     * Who may work the helpdesk (see every ticket, reply, assign…): `fn (User $user): bool`.
     * Without it, ticket abilities come from the `Ticket` policy (the plugin's default
     * policy lets every user of the panel in).
     */
    public function authorize(bool|Closure|null $callback = true): static
    {
        $this->authorizeUsing = $callback;

        return $this;
    }

    public function hasAuthorization(): bool
    {
        return $this->authorizeUsing !== null;
    }

    public function isAgent(Model $user): bool
    {
        if ($this->authorizeUsing === null) {
            return true;
        }

        return (bool) $this->evaluate($this->authorizeUsing, ['user' => $user, 'agent' => $user], [Model::class => $user, $user::class => $user]);
    }

    /**
     * The users tickets can be assigned to: `fn (?Model $tenant): Builder => User::query()->where('is_agent', true)`.
     * By default the panel's users (members of the current tenant on tenancy panels).
     */
    public function agents(?Closure $callback): static
    {
        $this->agentsUsing = $callback;

        return $this;
    }

    /**
     * @return Builder<Model>|null
     */
    public function getAgentsQuery(?Model $tenant): ?Builder
    {
        if ($this->agentsUsing === null) {
            return null;
        }

        $query = $this->evaluate($this->agentsUsing, ['tenant' => $tenant], $tenant === null ? [] : [Model::class => $tenant]);

        return $query instanceof Builder ? $query : null;
    }

    // ---------------------------------------------------------------- SLA

    public function businessHours(?BusinessHours $hours): static
    {
        $this->businessHours = $hours;

        return $this;
    }

    public function getBusinessHours(): BusinessHours
    {
        return $this->businessHours ?? BusinessHours::make();
    }

    /**
     * The SLA policies, in order: the first that applies to a ticket sets its targets.
     *
     * @param  array<int, SlaPolicy>  $policies
     */
    public function slaPolicies(array $policies): static
    {
        $this->slaPolicies = array_values($policies);

        return $this;
    }

    /**
     * @return array<int, SlaPolicy>
     */
    public function getSlaPolicies(): array
    {
        return $this->slaPolicies;
    }

    /** Targets due within this many minutes are "due soon" (60 by default). */
    public function dueSoonMinutes(int $minutes): static
    {
        $this->dueSoonMinutes = max(1, $minutes);

        return $this;
    }

    public function getDueSoonMinutes(): int
    {
        return $this->dueSoonMinutes;
    }

    // ---------------------------------------------------------------- Customer side

    /**
     * The tenant of tickets created outside the panel (portal, contact form, chat, email):
     * `fn (Request $request): ?Model => Team::where('domain', $request->getHost())->first()`.
     * Without it they belong to no tenant.
     */
    public function publicTenant(?Closure $callback): static
    {
        $this->publicTenantUsing = $callback;

        return $this;
    }

    public function getPublicTenant(?Request $request = null): ?Model
    {
        if ($this->publicTenantUsing === null) {
            return null;
        }

        $request ??= request();
        $tenant = $this->evaluate($this->publicTenantUsing, ['request' => $request], [Request::class => $request]);

        return $tenant instanceof Model ? $tenant : null;
    }

    /** The first message visitors see in the chat widget. */
    public function chatGreeting(string|Closure|null $greeting): static
    {
        $this->chatGreeting = $greeting;

        return $this;
    }

    public function getChatGreeting(): string
    {
        $greeting = $this->evaluate($this->chatGreeting);

        return is_string($greeting) && trim($greeting) !== '' ? $greeting : (string) __('alfakit-helpdesk::helpdesk.chat.greeting');
    }

    // ---------------------------------------------------------------- Inbox and editor

    /**
     * How often the inbox refreshes without real-time updates (`Panel::realtime()`); null
     * turns polling off.
     */
    public function inboxPollingInterval(string|Closure|null $interval): static
    {
        $this->inboxPollingInterval = $interval;

        return $this;
    }

    public function getInboxPollingInterval(): ?string
    {
        $interval = $this->evaluate($this->inboxPollingInterval);

        return is_string($interval) && $interval !== '' ? $interval : null;
    }

    /**
     * Tag tickets with spatie/laravel-tags (needs alfasix/alfakit-tags and a ticket model
     * with `Spatie\Tags\HasTags`). By default on when both are there.
     */
    public function spatieTags(bool $condition = true): static
    {
        $this->usesSpatieTags = $condition;

        return $this;
    }

    public function usesSpatieTags(): bool
    {
        $available = class_exists(\AlfaSix\AlfakitTags\Forms\Components\SpatieTagsInput::class)
            && in_array('Spatie\\Tags\\HasTags', class_uses_recursive(AlfakitHelpdesk::ticketModel()), true);

        return $available && ($this->usesSpatieTags ?? true);
    }

    /**
     * The toolbar of the reply and note editors (RichEditor toolbar button names).
     *
     * @param  array<int, string>|Closure  $buttons
     */
    public function toolbarButtons(array|Closure $buttons): static
    {
        $this->toolbarButtons = $buttons;

        return $this;
    }

    /**
     * @return array<int, string>
     */
    public function getToolbarButtons(): array
    {
        $buttons = $this->evaluate($this->toolbarButtons);

        return is_array($buttons) ? array_values(array_filter($buttons, is_string(...))) : [];
    }
}
