<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Support;

use AlfaSix\Alfakit\AvatarProviders\InitialsAvatarProvider;
use AlfaSix\Alfakit\Contracts\HasTenants;
use AlfaSix\AlfakitHelpdesk\AlfakitHelpdesk;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Who answers tickets: `HelpdeskPlugin::agents()`, or the panel's users (the members of the
 * current tenant on tenancy panels), filtered by `HelpdeskPlugin::authorize()`.
 */
final class Agents
{
    /** Tenant relationships tried, in order, for the members of a tenant. */
    public const MEMBER_RELATIONSHIPS = ['members', 'users'];

    /**
     * @return Builder<Model>|null
     */
    public static function query(?Model $tenant = null): ?Builder
    {
        $tenant ??= self::tenant();
        $query = AlfakitHelpdesk::plugin()->getAgentsQuery($tenant);

        if ($query !== null) {
            return $query;
        }

        $panel = AlfakitHelpdesk::panel();
        $model = AlfakitHelpdesk::userModel($panel?->getAuthGuard());

        if ($model === null) {
            return null;
        }

        /** @var Builder<Model> $query */
        $query = $model::query();

        if ($tenant !== null) {
            foreach (self::MEMBER_RELATIONSHIPS as $relationship) {
                if (! method_exists($tenant, $relationship)) {
                    continue;
                }

                $relation = $tenant->{$relationship}();
                $instance = $query->getModel();

                if ($relation instanceof Relation && $relation->getRelated()->getTable() === $instance->getTable()) {
                    return $query->whereIn(
                        $instance->getQualifiedKeyName(),
                        $relation->getQuery()->toBase()->select($relation->getRelated()->getQualifiedKeyName()),
                    );
                }
            }
        }

        return $query;
    }

    /**
     * Agents as `id => name` for selects.
     *
     * @return array<string, string>
     */
    public static function options(?string $search = null, int $limit = 50): array
    {
        $query = self::query();

        if ($query === null) {
            return [];
        }

        $search = trim((string) $search);

        if ($search !== '') {
            $like = '%'.mb_strtolower($search).'%';
            $query->where(static function (Builder $query) use ($like): void {
                foreach (['name', 'email'] as $column) {
                    $query->orWhereRaw('LOWER('.$query->getQuery()->getGrammar()->wrap($query->qualifyColumn($column)).') LIKE ?', [$like]);
                }
            });
        }

        $options = [];

        foreach ($query->limit($limit * 2)->get() as $agent) {
            if (! self::isAgent($agent)) {
                continue;
            }

            $options[(string) $agent->getKey()] = self::name($agent);

            if (count($options) >= $limit) {
                break;
            }
        }

        return $options;
    }

    /**
     * The agent with this key, if it is one (in the current tenant).
     */
    public static function find(mixed $key): ?Model
    {
        if (! is_int($key) && ! (is_string($key) && $key !== '')) {
            return null;
        }

        $agent = self::query()?->whereKey($key)->first();

        return $agent instanceof Model && self::isAgent($agent) ? $agent : null;
    }

    /**
     * Whether the user works the helpdesk: the plugin's `authorize()` (everyone by default),
     * and a member of the current tenant on tenancy panels.
     */
    public static function isAgent(?Model $user): bool
    {
        if ($user === null) {
            return false;
        }

        $tenant = self::tenant();

        if ($tenant !== null && $user instanceof HasTenants && ! $user->canAccessTenant($tenant)) {
            return false;
        }

        return AlfakitHelpdesk::plugin()->isAgent($user);
    }

    public static function name(?Model $user): string
    {
        if ($user === null) {
            return (string) __('alfakit-helpdesk::helpdesk.ticket.unassigned');
        }

        $panel = AlfakitHelpdesk::panel();

        if ($panel !== null && $user instanceof Authenticatable) {
            return $panel->getUserName($user);
        }

        $name = $user->getAttribute('name') ?? $user->getAttribute('email') ?? $user->getKey();

        return is_scalar($name) ? (string) $name : '';
    }

    public static function avatar(?Model $user): ?string
    {
        if ($user === null) {
            return null;
        }

        $panel = AlfakitHelpdesk::panel();
        $url = $panel !== null && $user instanceof Authenticatable ? $panel->getUserAvatarUrl($user) : null;

        return is_string($url) && $url !== '' ? $url : (new InitialsAvatarProvider)->get($user);
    }

    private static function tenant(): ?Model
    {
        $panel = alfakit()->getCurrentPanel();

        return $panel !== null && $panel->hasTenancy() ? alfakit()->getTenant() : null;
    }
}
