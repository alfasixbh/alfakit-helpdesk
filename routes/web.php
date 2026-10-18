<?php

declare(strict_types=1);

use AlfaSix\AlfakitHelpdesk\Http\Controllers\AttachmentController;
use AlfaSix\AlfakitHelpdesk\Http\Controllers\ChatController;
use AlfaSix\AlfakitHelpdesk\Http\Controllers\InboundMailController;
use AlfaSix\AlfakitHelpdesk\Http\Controllers\PortalController;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;

$route = Route::middleware((array) config('alfakit-helpdesk.route.middleware', ['web']))
    ->prefix(trim((string) config('alfakit-helpdesk.route.prefix', 'support'), '/'))
    ->name((string) config('alfakit-helpdesk.route.name', 'alfakit-helpdesk.'));

if (is_string($domain = config('alfakit-helpdesk.route.domain')) && $domain !== '') {
    $route->domain($domain);
}

$route->group(function (): void {
    Route::get('/', [PortalController::class, 'index'])->name('portal.index');
    Route::get('tickets/new', [PortalController::class, 'create'])->name('portal.create');
    Route::get('tickets/{ticket}', [PortalController::class, 'show'])->whereNumber('ticket')->name('portal.show');
    Route::get('t/{ticket}', [PortalController::class, 'guest'])->whereNumber('ticket')->middleware('signed')->name('guest.show');
    Route::get('rate/{ticket}', [PortalController::class, 'rate'])->whereNumber('ticket')->middleware('signed')->name('rate');
    Route::get('contact', [PortalController::class, 'contact'])->name('contact');

    Route::get('attachments/{message}/{index}', AttachmentController::class)
        ->whereNumber(['message', 'index'])
        ->middleware('signed')
        ->name('attachments');

    Route::get('chat', [ChatController::class, 'show'])->name('chat.show');
    Route::post('chat', [ChatController::class, 'start'])->name('chat.start');
    Route::post('chat/messages', [ChatController::class, 'message'])->name('chat.message');
    Route::post('chat/typing', [ChatController::class, 'typing'])->name('chat.typing');
    Route::delete('chat', [ChatController::class, 'leave'])->name('chat.leave');

    if ((bool) config('alfakit-helpdesk.inbound.enabled', false)) {
        // Called by your email provider: no session, no CSRF token.
        Route::post('inbound/mail', InboundMailController::class)
            ->withoutMiddleware([VerifyCsrfToken::class, Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class])
            ->name('inbound.mail');
    }
});
