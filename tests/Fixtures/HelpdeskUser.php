<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Tests\Fixtures;

use AlfaSix\Alfakit\Tests\Fixtures\Models\TenantUser;
use Illuminate\Auth\MustVerifyEmail as VerifiesEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail;

/**
 * Agents have an `@agents.test` address; everyone else is a customer.
 */
class HelpdeskUser extends TenantUser implements MustVerifyEmail
{
    use VerifiesEmail;

    protected $table = 'users';

    public function isAgent(): bool
    {
        return str_ends_with((string) $this->getAttribute('email'), '@agents.test');
    }
}
