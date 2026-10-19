# AlfaKit Helpdesk

<p><picture><source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/alfasixbh/alfakit/main/art/alfasix/alfa-six-micro-white.svg"><img src="https://raw.githubusercontent.com/alfasixbh/alfakit/main/art/alfasix/alfa-six-micro-navy.svg" alt="" width="24" height="18" align="top"></picture> <sub>by <a href="https://alfasixbh.com">Alfa Six</a></sub></p>

> This is a read-only split of the [AlfaKit monorepo](https://github.com/alfasixbh/alfakit/tree/main/packages/plugins/helpdesk). Please open issues and pull requests there.

A Zendesk / Intercom-style helpdesk for [AlfaKit](https://alfakit.dev) panels: tickets with SLAs on business hours, internal notes, canned replies, merging and CSAT; an agent inbox with keyboard shortcuts and real-time updates; a customer portal, a spam-protected contact form and an embeddable live chat widget; queued emails, reply by email and reports. Renders in React, Vue and Svelte.

## Installation

```bash
composer require alfasix/alfakit-helpdesk
php artisan vendor:publish --tag=alfakit-helpdesk-migrations
php artisan migrate
php artisan alfakit-helpdesk:pages
```

```php
use AlfaSix\AlfakitHelpdesk\HelpdeskPlugin;

$panel->plugin(HelpdeskPlugin::make()->authorize(fn (User $user): bool => $user->is_support_agent));
```

Schedule `php artisan alfakit-helpdesk:sla` every minute and run a queue worker.

Add the chat widget to your public pages:

```tsx
import { HelpdeskChat } from '@alfakit/react'

;<HelpdeskChat endpoint="/support/chat" />
```

Full documentation: [alfakit.dev/docs/plugins/helpdesk](https://alfakit.dev/docs/plugins/helpdesk).

## License

MIT. See [LICENSE.md](LICENSE.md).
