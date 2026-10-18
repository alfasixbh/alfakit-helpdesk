<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Tests;

use AlfaSix\Alfakit\Tests\Fixtures\Models\Team;
use AlfaSix\AlfakitHelpdesk\Enums\TicketChannel;
use AlfaSix\AlfakitHelpdesk\Models\Ticket;
use AlfaSix\AlfakitHelpdesk\Support\Tickets;
use AlfaSix\AlfakitHelpdesk\Tests\Fixtures\HelpdeskUser;

function test(): TestCase
{
    return TestCase::current();
}

function agent(string $name = 'Dana Agent'): HelpdeskUser
{
    return HelpdeskUser::query()->create(['name' => $name, 'email' => uniqid('agent', true).'@agents.test', 'password' => 'password', 'email_verified_at' => now()]);
}

function customer(string $name = 'Casey Customer', bool $verified = true, ?string $email = null): HelpdeskUser
{
    return HelpdeskUser::query()->create(['name' => $name, 'email' => $email ?? uniqid('customer', true).'@example.com', 'password' => 'password', 'email_verified_at' => $verified ? now() : null]);
}

function team(string $name): Team
{
    return Team::query()->create(['name' => $name, 'slug' => strtolower($name)]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function ticket(array $attributes = [], string $body = '<p>My printer is on fire.</p>'): Ticket
{
    return Tickets::open([
        'subject' => 'Printer on fire',
        'channel' => TicketChannel::Portal,
        ...$attributes,
    ], $body, ['notify' => false]);
}

/**
 * @return array<string, string>
 */
function inertiaHeaders(): array
{
    return ['X-Inertia' => 'true', 'X-Inertia-Version' => ''];
}

/**
 * The inbox component of the inbox page.
 *
 * @return array{token: string, inbox: array<string, mixed>}
 */
function inbox(string $url = '/helpdesk/helpdesk/inbox'): array
{
    $props = test()->inertiaProps($url);
    $inbox = $props['page']['content'][0]['components'][0] ?? [];

    return ['token' => $props['page']['token'], 'inbox' => $inbox];
}

/**
 * Every component in a serialised schema tree, depth-first.
 *
 * @param  array<int|string, mixed>  $nodes
 * @return array<int, array<string, mixed>>
 */
function flatten(array $nodes): array
{
    $flat = [];

    foreach ($nodes as $node) {
        if (! is_array($node)) {
            continue;
        }

        if (isset($node['type']) && is_string($node['type'])) {
            $flat[] = $node;
        }

        foreach (['components', 'schema', 'children', 'items', 'actions', 'content', 'widgets'] as $key) {
            if (isset($node[$key]) && is_array($node[$key])) {
                array_push($flat, ...flatten(array_is_list($node[$key]) ? $node[$key] : [$node[$key]]));
            }
        }
    }

    return $flat;
}
