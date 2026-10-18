<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Policies;

use AlfaSix\AlfakitHelpdesk\Models\HelpdeskGroup;
use AlfaSix\AlfakitHelpdesk\Support\Agents;
use Illuminate\Database\Eloquent\Model;

class HelpdeskGroupPolicy
{
    public function viewAny(Model $user): bool
    {
        return Agents::isAgent($user);
    }

    public function view(Model $user, HelpdeskGroup $group): bool
    {
        return Agents::isAgent($user);
    }

    public function create(Model $user): bool
    {
        return Agents::isAgent($user);
    }

    public function update(Model $user, HelpdeskGroup $group): bool
    {
        return Agents::isAgent($user);
    }

    public function delete(Model $user, HelpdeskGroup $group): bool
    {
        return Agents::isAgent($user);
    }

    public function deleteAny(Model $user): bool
    {
        return Agents::isAgent($user);
    }
}
