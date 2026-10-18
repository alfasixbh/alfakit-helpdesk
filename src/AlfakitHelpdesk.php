<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk;

use AlfaSix\Alfakit\Panel;
use AlfaSix\AlfakitHelpdesk\Models\CannedReply;
use AlfaSix\AlfakitHelpdesk\Models\HelpdeskGroup;
use AlfaSix\AlfakitHelpdesk\Models\Ticket;
use AlfaSix\AlfakitHelpdesk\Models\TicketMessage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Throwable;

/**
 * Configuration of the helpdesk that doesn't depend on a panel: models, the public
 * routes, and the plugin whose options the customer side uses.
 */
final class AlfakitHelpdesk
{
    /**
     * @return class-string<Ticket>
     */
    public static function ticketModel(): string
    {
        return self::model('ticket', Ticket::class);
    }

    /**
     * @return class-string<TicketMessage>
     */
    public static function messageModel(): string
    {
        return self::model('message', TicketMessage::class);
    }

    /**
     * @return class-string<CannedReply>
     */
    public static function cannedReplyModel(): string
    {
        return self::model('canned_reply', CannedReply::class);
    }

    /**
     * @return class-string<HelpdeskGroup>
     */
    public static function groupModel(): string
    {
        return self::model('group', HelpdeskGroup::class);
    }

    /**
     * @template T of Model
     *
     * @param  class-string<T>  $base
     * @return class-string<T>
     */
    private static function model(string $key, string $base): string
    {
        $model = config('alfakit-helpdesk.models.'.$key, $base);

        if (! is_string($model) || ! is_a($model, $base, true)) {
            throw new InvalidArgumentException("The helpdesk {$key} model must extend [{$base}].");
        }

        return $model;
    }

    /**
     * The users model (agents and customers): the model of the portal guard's provider.
     *
     * @return class-string<Model>|null
     */
    public static function userModel(?string $guard = null): ?string
    {
        $guard ??= self::portalGuard();
        $provider = config("auth.guards.{$guard}.provider");
        $model = is_string($provider) ? config("auth.providers.{$provider}.model") : null;

        return is_string($model) && is_a($model, Model::class, true) ? $model : null;
    }

    public static function portalGuard(): string
    {
        $guard = config('alfakit-helpdesk.portal.guard');

        if (is_string($guard) && $guard !== '') {
            return $guard;
        }

        // The guard of AlfaKit's standalone components (the portal forms and tables).
        $standalone = config('alfakit.standalone.guard');

        return is_string($standalone) && $standalone !== '' ? $standalone : (string) config('auth.defaults.guard', 'web');
    }

    /**
     * The signed-in customer on the portal guard.
     */
    public static function customer(): ?Model
    {
        try {
            $user = Auth::guard(self::portalGuard())->user();
        } catch (Throwable) {
            return null;
        }

        return $user instanceof Model ? $user : null;
    }

    // ---------------------------------------------------------------- Plugin

    /**
     * The panel whose plugin options the customer side uses: the current panel when it has
     * the plugin, else `config('alfakit-helpdesk.panel')`, else the first panel with it.
     */
    public static function panel(): ?Panel
    {
        $current = alfakit()->getCurrentPanel();

        if ($current !== null && $current->hasPlugin(HelpdeskPlugin::ID)) {
            return $current;
        }

        $id = config('alfakit-helpdesk.panel');

        if (is_string($id) && $id !== '' && alfakit()->hasPanel($id)) {
            return alfakit()->getPanel($id);
        }

        foreach (alfakit()->getPanels() as $panel) {
            if ($panel->hasPlugin(HelpdeskPlugin::ID)) {
                return $panel;
            }
        }

        return null;
    }

    /**
     * The plugin of `panel()`, or one with the default options.
     */
    public static function plugin(): HelpdeskPlugin
    {
        $panel = self::panel();

        if ($panel !== null) {
            $plugin = $panel->getPlugin(HelpdeskPlugin::ID);

            if ($plugin instanceof HelpdeskPlugin) {
                return $plugin;
            }
        }

        return HelpdeskPlugin::make();
    }

    // ---------------------------------------------------------------- Routes

    public static function routeName(string $name): string
    {
        return (string) config('alfakit-helpdesk.route.name', 'alfakit-helpdesk.').$name;
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public static function route(string $name, array $parameters = []): ?string
    {
        $route = self::routeName($name);

        return Route::has($route) ? route($route, $parameters) : null;
    }

    public static function prefix(): string
    {
        return trim((string) config('alfakit-helpdesk.route.prefix', 'support'), '/');
    }
}
