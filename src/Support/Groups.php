<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Support;

use AlfaSix\AlfakitHelpdesk\HelpdeskPlugin;
use AlfaSix\AlfakitHelpdesk\Models\HelpdeskGroup;
use Illuminate\Database\Eloquent\Builder;

/**
 * Agent groups of the current tenant.
 */
final class Groups
{
    public static function isEnabled(): bool
    {
        return HelpdeskPlugin::get()->hasGroups();
    }

    /**
     * @return Builder<HelpdeskGroup>
     */
    public static function query(): Builder
    {
        /** @var Builder<HelpdeskGroup> $query */
        $query = HelpdeskPlugin::get()->getGroupResource()::getEloquentQuery();

        return $query;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        if (! self::isEnabled()) {
            return [];
        }

        $options = [];

        foreach (self::query()->orderBy('name')->limit(200)->get() as $group) {
            $options[(string) $group->getKey()] = $group->name;
        }

        return $options;
    }

    public static function find(mixed $key): ?HelpdeskGroup
    {
        if (! self::isEnabled() || (! is_int($key) && ! (is_string($key) && ctype_digit($key)))) {
            return null;
        }

        $group = self::query()->whereKey((int) $key)->first();

        return $group instanceof HelpdeskGroup ? $group : null;
    }
}
