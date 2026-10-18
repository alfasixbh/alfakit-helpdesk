<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Models;

use AlfaSix\AlfakitHelpdesk\AlfakitHelpdesk;
use AlfaSix\AlfakitHelpdesk\Support\MessageBody;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A saved reply with placeholders (`{{ requester.name }}`), shared with every agent or
 * private to its owner.
 *
 * @property int $id
 * @property string|null $tenant_type
 * @property int|string|null $tenant_id
 * @property string $title
 * @property string $body
 * @property int|string|null $owner_id
 * @property bool $is_shared
 * @property int $uses_count
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Model|null $owner
 */
class CannedReply extends Model
{
    protected $table = 'helpdesk_canned_replies';

    protected $guarded = ['id'];

    protected $attributes = [
        'is_shared' => true,
        'uses_count' => 0,
    ];

    protected function casts(): array
    {
        return [
            'is_shared' => 'boolean',
            'uses_count' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(static function (CannedReply $reply): void {
            $reply->body = MessageBody::sanitize($reply->body);
        });
    }

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
    public function owner(): BelongsTo
    {
        return $this->belongsTo(AlfakitHelpdesk::userModel() ?? Model::class, 'owner_id');
    }

    /**
     * Shared replies and the agent's own.
     *
     * @param  Builder<CannedReply>  $query
     */
    public function scopeVisibleTo(Builder $query, ?Model $agent): void
    {
        $query->where(static function (Builder $query) use ($agent): void {
            $query->where('is_shared', true);

            if ($agent !== null) {
                $query->orWhere('owner_id', $agent->getKey());
            }
        });
    }
}
