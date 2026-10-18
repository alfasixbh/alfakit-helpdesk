<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Models;

use AlfaSix\AlfakitHelpdesk\AlfakitHelpdesk;
use AlfaSix\AlfakitHelpdesk\Enums\MessageKind;
use AlfaSix\AlfakitHelpdesk\Enums\SlaState;
use AlfaSix\AlfakitHelpdesk\Enums\TicketChannel;
use AlfaSix\AlfakitHelpdesk\Enums\TicketPriority;
use AlfaSix\AlfakitHelpdesk\Enums\TicketStatus;
use AlfaSix\AlfakitHelpdesk\Enums\TicketType;
use AlfaSix\AlfakitHelpdesk\Sla\Sla;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A support ticket: a requester (a user or a guest email), an assignee and group, SLA
 * targets and the conversation (`messages`).
 *
 * @property int $id
 * @property string|null $tenant_type
 * @property int|string|null $tenant_id
 * @property string $subject
 * @property string $status
 * @property string $priority
 * @property string $type
 * @property string $channel
 * @property array<int, string>|null $labels
 * @property int|string|null $requester_id
 * @property string|null $requester_email
 * @property string|null $requester_name
 * @property int|string|null $assignee_id
 * @property int|null $group_id
 * @property int|null $merged_into_id
 * @property string|null $sla_policy
 * @property CarbonImmutable|null $first_response_due_at
 * @property CarbonImmutable|null $resolution_due_at
 * @property CarbonImmutable|null $sla_paused_at
 * @property CarbonImmutable|null $first_responded_at
 * @property CarbonImmutable|null $sla_breach_notified_at
 * @property CarbonImmutable|null $solved_at
 * @property CarbonImmutable|null $closed_at
 * @property CarbonImmutable|null $last_activity_at
 * @property CarbonImmutable|null $requester_seen_at
 * @property int|null $satisfaction_rating
 * @property string|null $satisfaction_comment
 * @property CarbonImmutable|null $satisfaction_rated_at
 * @property string|null $access_token_hash
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Model|null $tenant
 * @property-read Model|null $requester
 * @property-read Model|null $assignee
 * @property-read HelpdeskGroup|null $group
 * @property-read Ticket|null $mergedInto
 * @property-read \Illuminate\Database\Eloquent\Collection<int, TicketMessage> $messages
 */
class Ticket extends Model
{
    protected $table = 'helpdesk_tickets';

    protected $guarded = ['id'];

    protected $hidden = ['access_token_hash'];

    protected $attributes = [
        'status' => 'open',
        'priority' => 'normal',
        'type' => 'question',
        'channel' => 'agent',
    ];

    protected function casts(): array
    {
        return [
            'labels' => 'array',
            'satisfaction_rating' => 'integer',
            'first_response_due_at' => 'immutable_datetime',
            'resolution_due_at' => 'immutable_datetime',
            'sla_paused_at' => 'immutable_datetime',
            'first_responded_at' => 'immutable_datetime',
            'sla_breach_notified_at' => 'immutable_datetime',
            'solved_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'last_activity_at' => 'immutable_datetime',
            'requester_seen_at' => 'immutable_datetime',
            'satisfaction_rated_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    // ---------------------------------------------------------------- Relationships

    /**
     * @return MorphTo<Model, $this>
     */
    public function tenant(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(AlfakitHelpdesk::userModel() ?? Model::class, 'requester_id');
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(AlfakitHelpdesk::userModel() ?? Model::class, 'assignee_id');
    }

    /**
     * @return BelongsTo<HelpdeskGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(AlfakitHelpdesk::groupModel(), 'group_id');
    }

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(AlfakitHelpdesk::ticketModel(), 'merged_into_id');
    }

    /**
     * The whole conversation, oldest first (replies, internal notes and events).
     *
     * @return HasMany<TicketMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(AlfakitHelpdesk::messageModel(), 'ticket_id')->orderBy('created_at')->orderBy('id');
    }

    /**
     * What the requester may see: public replies only, never internal notes.
     *
     * @return HasMany<TicketMessage, $this>
     */
    public function publicMessages(): HasMany
    {
        return $this->messages()->where('kind', MessageKind::Reply->value);
    }

    // ---------------------------------------------------------------- Attributes

    public function getStatus(): TicketStatus
    {
        return TicketStatus::tryFrom($this->status) ?? TicketStatus::Open;
    }

    public function getPriority(): TicketPriority
    {
        return TicketPriority::tryFrom($this->priority) ?? TicketPriority::Normal;
    }

    public function getType(): TicketType
    {
        return TicketType::tryFrom($this->type) ?? TicketType::Question;
    }

    public function getChannel(): TicketChannel
    {
        return TicketChannel::tryFrom($this->channel) ?? TicketChannel::Agent;
    }

    /** `#42` */
    public function getReference(): string
    {
        return '#'.$this->getKey();
    }

    public function getRequesterName(): string
    {
        $user = $this->requester_id !== null ? $this->requester : null;
        $name = $user?->getAttribute('name') ?? $this->requester_name ?? $user?->getAttribute('email') ?? $this->requester_email;

        return is_scalar($name) && (string) $name !== '' ? (string) $name : (string) __('alfakit-helpdesk::helpdesk.ticket.guest');
    }

    public function getRequesterEmail(): ?string
    {
        $email = $this->requester_email;

        if (($email === null || $email === '') && $this->requester_id !== null) {
            $email = $this->requester?->getAttribute('email');
        }

        return is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }

    public function getSlaState(?DateTimeInterface $now = null): SlaState
    {
        return Sla::state($this, $now);
    }

    public function isMerged(): bool
    {
        return $this->merged_into_id !== null;
    }

    /** Whether the requester may still reply (closed and merged tickets are final). */
    public function acceptsReplies(): bool
    {
        return $this->getStatus() !== TicketStatus::Closed && ! $this->isMerged();
    }

    public function canBeRated(): bool
    {
        return $this->getStatus() === TicketStatus::Solved || ($this->getStatus() === TicketStatus::Closed && ! $this->isMerged() && $this->solved_at !== null);
    }

    /**
     * Whether this user is the ticket's requester: the same user, or (with
     * `portal.match_verified_email`) a user with the requester's email address, verified.
     */
    public function isRequestedBy(?Model $user): bool
    {
        if ($user === null) {
            return false;
        }

        if ($this->requester_id !== null) {
            return (string) $this->requester_id === (string) $user->getKey();
        }

        return self::matchesVerifiedEmail($user, $this->requester_email);
    }

    public static function matchesVerifiedEmail(Model $user, ?string $email): bool
    {
        if (! config('alfakit-helpdesk.portal.match_verified_email', true) || $email === null || $email === '') {
            return false;
        }

        $userEmail = $user->getAttribute('email');

        if (! is_string($userEmail) || mb_strtolower($userEmail) !== mb_strtolower($email)) {
            return false;
        }

        return $user instanceof MustVerifyEmail && $user->hasVerifiedEmail();
    }

    // ---------------------------------------------------------------- Scopes

    /**
     * @param  Builder<Ticket>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereIn($query->qualifyColumn('status'), array_map(static fn (TicketStatus $status): string => $status->value, TicketStatus::active()));
    }

    /**
     * Tickets of a requester (theirs, or sent from their verified email).
     *
     * @param  Builder<Ticket>  $query
     */
    public function scopeRequestedBy(Builder $query, Model $user): void
    {
        $email = $user->getAttribute('email');
        $matchesEmail = is_string($email) && $email !== '' && config('alfakit-helpdesk.portal.match_verified_email', true)
            && $user instanceof MustVerifyEmail && $user->hasVerifiedEmail();

        $query->where(static function (Builder $query) use ($user, $email, $matchesEmail): void {
            $query->where($query->qualifyColumn('requester_id'), $user->getKey());

            if ($matchesEmail) {
                $query->orWhere(static fn (Builder $query) => $query
                    ->whereNull($query->qualifyColumn('requester_id'))
                    ->whereRaw('LOWER('.$query->getQuery()->getGrammar()->wrap($query->qualifyColumn('requester_email')).') = ?', [mb_strtolower((string) $email)]));
            }
        });
    }

    /**
     * Active tickets with a target past due.
     *
     * @param  Builder<Ticket>  $query
     */
    public function scopeBreached(Builder $query, ?DateTimeInterface $now = null): void
    {
        $now ??= now();

        $query->where(static function (Builder $query): void {
            $query->where('status', TicketStatus::Open->value)->orWhere('status', TicketStatus::Pending->value);
        })->where(static function (Builder $query) use ($now): void {
            $query->where(static fn (Builder $query) => $query->whereNull('first_responded_at')->where('first_response_due_at', '<=', $now))
                ->orWhere(static fn (Builder $query) => $query->whereNull('solved_at')->whereNull('sla_paused_at')->where('status', TicketStatus::Open->value)->where('resolution_due_at', '<=', $now));
        });
    }

    /**
     * Active tickets with a target due within `$minutes` (not breached yet).
     *
     * @param  Builder<Ticket>  $query
     */
    public function scopeDueSoon(Builder $query, ?int $minutes = null, ?DateTimeInterface $now = null): void
    {
        $now = CarbonImmutable::instance($now ?? now());
        $until = $now->addMinutes($minutes ?? Sla::dueSoonMinutes());

        $query->where('status', TicketStatus::Open->value)->where(static function (Builder $query) use ($now, $until): void {
            $query->where(static fn (Builder $query) => $query->whereNull('first_responded_at')->where('first_response_due_at', '>', $now)->where('first_response_due_at', '<=', $until))
                ->orWhere(static fn (Builder $query) => $query->whereNull('solved_at')->whereNull('sla_paused_at')->where('resolution_due_at', '>', $now)->where('resolution_due_at', '<=', $until));
        });
    }
}
