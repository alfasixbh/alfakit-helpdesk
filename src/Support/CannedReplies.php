<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Support;

use AlfaSix\AlfakitHelpdesk\HelpdeskPlugin;
use AlfaSix\AlfakitHelpdesk\Models\CannedReply;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The canned replies an agent may use: shared ones and their own, in the current tenant.
 */
final class CannedReplies
{
    /**
     * @return Builder<CannedReply>
     */
    public static function query(?Model $agent): Builder
    {
        /** @var Builder<CannedReply> $query */
        $query = HelpdeskPlugin::get()->getCannedReplyResource()::getEloquentQuery();

        return $query->visibleTo($agent);
    }

    /**
     * @return array<string, string>
     */
    public static function options(?Model $agent): array
    {
        $options = [];

        foreach (self::query($agent)->orderByDesc('uses_count')->orderBy('title')->limit(200)->get() as $reply) {
            $options[(string) $reply->getKey()] = $reply->title;
        }

        return $options;
    }

    public static function find(mixed $key, ?Model $agent): ?CannedReply
    {
        if (! is_int($key) && ! (is_string($key) && ctype_digit($key))) {
            return null;
        }

        $reply = self::query($agent)->whereKey((int) $key)->first();

        return $reply instanceof CannedReply ? $reply : null;
    }
}
