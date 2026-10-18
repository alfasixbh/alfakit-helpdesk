<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Policies;

use AlfaSix\AlfakitHelpdesk\Models\CannedReply;
use AlfaSix\AlfakitHelpdesk\Support\Agents;
use Illuminate\Database\Eloquent\Model;

/**
 * Agents use shared replies and their own; private replies are their owner's only.
 */
class CannedReplyPolicy
{
    public function viewAny(Model $user): bool
    {
        return Agents::isAgent($user);
    }

    public function view(Model $user, CannedReply $reply): bool
    {
        return Agents::isAgent($user) && ($reply->is_shared || self::owns($user, $reply));
    }

    public function create(Model $user): bool
    {
        return Agents::isAgent($user);
    }

    public function update(Model $user, CannedReply $reply): bool
    {
        return $this->view($user, $reply);
    }

    public function delete(Model $user, CannedReply $reply): bool
    {
        return Agents::isAgent($user) && ($reply->owner_id === null || self::owns($user, $reply));
    }

    public function deleteAny(Model $user): bool
    {
        return Agents::isAgent($user);
    }

    private static function owns(Model $user, CannedReply $reply): bool
    {
        return $reply->owner_id !== null && (string) $reply->owner_id === (string) $user->getKey();
    }
}
