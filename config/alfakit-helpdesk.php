<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Panel
    |--------------------------------------------------------------------------
    |
    | The customer side (portal, contact form, live chat, emails) runs outside
    | every panel. It uses the HelpdeskPlugin options (SLA policies, business
    | hours, the tenant of public tickets…) of this panel, or of the first panel
    | that registers the plugin when null.
    |
    */

    'panel' => null,

    /*
    |--------------------------------------------------------------------------
    | Public routes
    |--------------------------------------------------------------------------
    |
    | The portal, contact form, chat endpoints, attachment downloads and the
    | inbound mail webhook live under /{prefix}. Portal pages render the Inertia
    | page below (create it with `php artisan alfakit-helpdesk:pages`).
    |
    */

    'route' => [
        'enabled' => true,
        'prefix' => 'support',
        'middleware' => ['web'],
        'name' => 'alfakit-helpdesk.',
        'domain' => null,
    ],

    'page' => 'alfakit-helpdesk/portal',

    /*
    |--------------------------------------------------------------------------
    | Customer portal
    |--------------------------------------------------------------------------
    |
    | Signed-in customers (on `guard`; AlfaKit's standalone guard when null) see and
    | answer their own tickets. Guests are sent to `login_url` (the `login`
    | route when null). With `match_verified_email`, tickets sent from a user's
    | email address before they signed up are theirs too, once the address is
    | verified (`MustVerifyEmail`).
    |
    */

    'portal' => [
        'enabled' => true,
        'guard' => null,
        'login_url' => null,
        'match_verified_email' => true,
        'per_page' => 15,
    ],

    'contact' => [
        'enabled' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Live chat
    |--------------------------------------------------------------------------
    |
    | Visitors start a chat from the widget; it becomes a ticket on the `chat`
    | channel. Guests are recognised by a signed, HTTP-only cookie. The widget
    | polls every `polling_interval` seconds while it is open (agents answer in
    | real time in the panel when it has `realtime()`).
    |
    */

    'chat' => [
        'enabled' => true,
        'cookie' => 'alfakit_helpdesk_chat',
        'cookie_minutes' => 60 * 24 * 30,
        'polling_interval' => 4,
        'require_email' => false,
        'max_length' => 4000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Abuse protection
    |--------------------------------------------------------------------------
    |
    | Rate limits are per IP address (and per chat). Spam (a filled honeypot or a
    | form sent faster than `min_seconds`) gets the normal success answer, so bots
    | learn nothing, and nothing is stored.
    |
    */

    'rate_limits' => [
        'contact' => ['max_attempts' => 5, 'decay_seconds' => 600],
        'portal' => ['max_attempts' => 20, 'decay_seconds' => 600],
        'chat_start' => ['max_attempts' => 5, 'decay_seconds' => 600],
        'chat_message' => ['max_attempts' => 30, 'decay_seconds' => 60],
        'typing' => ['max_attempts' => 30, 'decay_seconds' => 60],
    ],

    'spam' => [
        'honeypot_field' => '_website',
        'min_seconds' => 2,
    ],

    /*
    |--------------------------------------------------------------------------
    | Attachments
    |--------------------------------------------------------------------------
    |
    | Files of replies and notes are stored on this (private) disk and downloaded
    | through short-lived signed URLs, given only to who may see the message.
    |
    */

    'attachments' => [
        'disk' => 'local',
        'directory' => 'helpdesk',
        'max_size' => 10240,
        'max_files' => 5,
        'accepted_file_types' => [
            'image/*', 'application/pdf', 'text/plain', 'text/csv', 'application/zip',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ],
        'url_minutes' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Emails
    |--------------------------------------------------------------------------
    |
    | Queued Mailables to requesters (ticket received, agent replies, solved with
    | a satisfaction link) and to agents (new tickets and replies: the assignee,
    | else the group's agents, else `agent_emails`).
    |
    */

    'mail' => [
        'enabled' => true,
        'connection' => null,
        'queue' => null,
        'agent_emails' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Inbound mail (reply by email)
    |--------------------------------------------------------------------------
    |
    | POST /{prefix}/inbound/mail receives emails from your provider's webhook.
    | The request is verified by the bound `InboundMailVerifier` (HMAC-SHA256 of
    | the raw body with `secret` in the `X-Helpdesk-Signature` header by default)
    | and read by the bound `InboundMailParser` (a documented JSON format by
    | default: adapt your provider's payload in your own parser). Replies are
    | matched to tickets through the signed Reply-To address
    | `{local}+{ticket}.{signature}@{domain}` built from `reply_to`.
    |
    */

    'inbound' => [
        'enabled' => false,
        'secret' => null, // e.g. env('HELPDESK_INBOUND_SECRET') in your published config
        'reply_to' => null, // e.g. 'support@example.com'
        'max_body' => 200000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Housekeeping
    |--------------------------------------------------------------------------
    |
    | `php artisan alfakit-helpdesk:sla` (schedule it every minute) warns about
    | breached SLAs once per ticket and closes tickets solved more than
    | `close_solved_after_days` days ago (null to keep them solved).
    |
    */

    'close_solved_after_days' => 4,

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    */

    'models' => [
        'ticket' => AlfaSix\AlfakitHelpdesk\Models\Ticket::class,
        'message' => AlfaSix\AlfakitHelpdesk\Models\TicketMessage::class,
        'canned_reply' => AlfaSix\AlfakitHelpdesk\Models\CannedReply::class,
        'group' => AlfaSix\AlfakitHelpdesk\Models\HelpdeskGroup::class,
    ],

];
