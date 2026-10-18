<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Models;

use AlfaSix\AlfakitHelpdesk\AlfakitHelpdesk;
use AlfaSix\AlfakitHelpdesk\Enums\MessageKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A message of a ticket: a public reply (from an agent or the requester), an internal
 * note (agents only) or an event (status changes, merges).
 *
 * @property int $id
 * @property int $ticket_id
 * @property string $kind
 * @property string $source
 * @property string|null $author_type
 * @property int|string|null $author_id
 * @property bool $from_requester
 * @property string|null $author_name
 * @property string|null $author_email
 * @property string $body
 * @property array<int, array<string, mixed>>|null $attachments
 * @property string|null $message_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Ticket $ticket
 * @property-read Model|null $author
 */
class TicketMessage extends Model
{
    protected $table = 'helpdesk_messages';

    protected $guarded = ['id'];

    protected $attributes = [
        'kind' => 'reply',
        'source' => 'agent',
        'from_requester' => false,
    ];

    protected function casts(): array
    {
        return [
            'attachments' => 'array',
            'from_requester' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(AlfakitHelpdesk::ticketModel(), 'ticket_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function author(): MorphTo
    {
        return $this->morphTo();
    }

    public function getKind(): MessageKind
    {
        return MessageKind::tryFrom($this->kind) ?? MessageKind::Reply;
    }

    public function isPublic(): bool
    {
        return $this->getKind()->isPublic();
    }

    public function isInternal(): bool
    {
        return $this->getKind() === MessageKind::Note;
    }

    public function getAuthorName(): string
    {
        $author = $this->author_id !== null ? $this->author : null;
        $name = $author?->getAttribute('name') ?? $this->author_name ?? $author?->getAttribute('email') ?? $this->author_email;

        return is_scalar($name) && (string) $name !== '' ? (string) $name : (string) __($this->from_requester ? 'alfakit-helpdesk::helpdesk.ticket.guest' : 'alfakit-helpdesk::helpdesk.message.system');
    }

    /**
     * @return array<int, array{path: string, name: string, size: int|null, mime: string|null}>
     */
    public function getAttachments(): array
    {
        $attachments = [];

        foreach (is_array($this->attachments) ? $this->attachments : [] as $attachment) {
            if (! is_array($attachment) || ! is_string($attachment['path'] ?? null) || $attachment['path'] === '') {
                continue;
            }

            $attachments[] = [
                'path' => $attachment['path'],
                'name' => is_string($attachment['name'] ?? null) && $attachment['name'] !== '' ? $attachment['name'] : basename($attachment['path']),
                'size' => is_int($attachment['size'] ?? null) ? $attachment['size'] : null,
                'mime' => is_string($attachment['mime'] ?? null) ? $attachment['mime'] : null,
            ];
        }

        return $attachments;
    }
}
