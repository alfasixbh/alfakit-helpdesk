<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Support;

use AlfaSix\AlfakitHelpdesk\AlfakitHelpdesk;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * The users an agent may pick as the requester of a ticket.
 */
final class Requesters
{
    /**
     * The plugin's `requesters()` query when set. Otherwise every user of the panel's guard,
     * and on a tenancy panel only the tenant's members and the people who already have a
     * ticket with this tenant (anyone else is entered by name and email).
     *
     * @return Builder<Model>|null
     */
    public static function query(?Model $tenant = null): ?Builder
    {
        $panel = alfakit()->getCurrentPanel();
        $tenant ??= $panel !== null && $panel->hasTenancy() ? alfakit()->getTenant() : null;

        $query = AlfakitHelpdesk::plugin()->getRequestersQuery($tenant);

        if ($query !== null) {
            return $query;
        }

        $model = AlfakitHelpdesk::userModel((AlfakitHelpdesk::panel() ?? $panel)?->getAuthGuard());

        if ($model === null) {
            return null;
        }

        $query = $model::query();

        if ($tenant === null) {
            return $query;
        }

        $instance = $query->getModel();
        $key = $instance->getQualifiedKeyName();
        $tickets = AlfakitHelpdesk::ticketModel();

        $known = $tickets::query()
            ->withoutGlobalScopes()
            ->where('tenant_type', $tenant->getMorphClass())
            ->where('tenant_id', $tenant->getKey())
            ->whereNotNull('requester_id')
            ->toBase()
            ->select('requester_id');

        $members = null;

        foreach (Agents::MEMBER_RELATIONSHIPS as $relationship) {
            if (! method_exists($tenant, $relationship)) {
                continue;
            }

            $relation = $tenant->{$relationship}();

            if ($relation instanceof Relation && $relation->getRelated()->getTable() === $instance->getTable()) {
                $members = $relation->getQuery()->toBase()->select($relation->getRelated()->getQualifiedKeyName());

                break;
            }
        }

        return $query->where(static function (Builder $query) use ($key, $known, $members): void {
            $query->whereIn($key, $known);

            if ($members !== null) {
                $query->orWhereIn($key, $members);
            }
        });
    }
}
