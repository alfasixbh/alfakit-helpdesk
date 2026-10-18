<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Resources\Concerns;

use AlfaSix\AlfakitHelpdesk\HelpdeskPlugin;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Shared by the helpdesk resources: the plugin's navigation group, and polymorphic
 * tenancy (records belong to a tenant of any model through the `tenant` morph).
 *
 * @mixin \AlfaSix\Alfakit\Resources\Resource
 */
trait IsHelpdeskResource
{
    public static function getPlugin(): HelpdeskPlugin
    {
        return HelpdeskPlugin::get();
    }

    public static function getNavigationGroup(): ?string
    {
        return static::getPlugin()->getNavigationGroup();
    }

    public static function scopeEloquentQueryToTenant(Builder $query, Model $tenant): Builder
    {
        $relationship = $query->getModel()->{static::getTenantOwnershipRelationshipName()}();

        if ($relationship instanceof MorphTo) {
            return $query
                ->where($query->qualifyColumn($relationship->getMorphType()), $tenant->getMorphClass())
                ->where($query->qualifyColumn($relationship->getForeignKeyName()), $tenant->getKey());
        }

        return parent::scopeEloquentQueryToTenant($query, $tenant);
    }
}
