<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Models;

use AlfaSix\AlfakitHelpdesk\AlfakitHelpdesk;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A team of agents tickets can be routed to.
 *
 * @property int $id
 * @property string|null $tenant_type
 * @property int|string|null $tenant_id
 * @property string $name
 * @property string|null $description
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Model> $agents
 */
class HelpdeskGroup extends Model
{
    protected $table = 'helpdesk_groups';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function tenant(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsToMany<Model, $this>
     */
    public function agents(): BelongsToMany
    {
        return $this->belongsToMany(AlfakitHelpdesk::userModel() ?? Model::class, 'helpdesk_group_agent', 'group_id', 'agent_id');
    }

    /**
     * @return HasMany<Ticket, $this>
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(AlfakitHelpdesk::ticketModel(), 'group_id');
    }
}
