<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Actions;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Actions\BulkAction;
use AlfaSix\Alfakit\Forms\Components\FileUpload;
use AlfaSix\Alfakit\Forms\Components\RichEditor;
use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\Alfakit\Forms\Components\TagsInput;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Schemas\Components\Utilities\Get;
use AlfaSix\Alfakit\Schemas\Components\Utilities\Set;
use AlfaSix\Alfakit\Support\Authorization;
use AlfaSix\AlfakitHelpdesk\AlfakitHelpdesk;
use AlfaSix\AlfakitHelpdesk\Enums\TicketPriority;
use AlfaSix\AlfakitHelpdesk\Enums\TicketStatus;
use AlfaSix\AlfakitHelpdesk\Enums\TicketType;
use AlfaSix\AlfakitHelpdesk\HelpdeskPlugin;
use AlfaSix\AlfakitHelpdesk\Models\CannedReply;
use AlfaSix\AlfakitHelpdesk\Models\Ticket;
use AlfaSix\AlfakitHelpdesk\Rules\HasMessageContent;
use AlfaSix\AlfakitHelpdesk\Support\Agents;
use AlfaSix\AlfakitHelpdesk\Support\Attachments;
use AlfaSix\AlfakitHelpdesk\Support\CannedReplies;
use AlfaSix\AlfakitHelpdesk\Support\Groups;
use AlfaSix\AlfakitHelpdesk\Support\MessageBody;
use AlfaSix\AlfakitHelpdesk\Support\Placeholders;
use AlfaSix\AlfakitHelpdesk\Support\Tickets;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * The ticket actions of the view page, the inbox and the tickets table. Each is
 * authorised with the ticket policy (`update`) for its record.
 */
final class TicketActions
{
    /**
     * Every action of a ticket, keyed by name.
     *
     * @param  Closure(): (Builder<Model>|null)|null  $tickets  the (tenant-scoped) query merge targets are found in
     * @return array<string, Action>
     */
    public static function all(?Closure $tickets = null): array
    {
        return [
            'reply' => self::reply(),
            'note' => self::note(),
            'assign' => self::assign(),
            'take' => self::take(),
            'status' => self::status(),
            'solve' => self::solve(),
            'details' => self::details(),
            'merge' => self::merge($tickets),
        ];
    }

    public static function reply(): Action
    {
        return Action::make('reply')
            ->label(__('alfakit-helpdesk::helpdesk.actions.reply'))
            ->icon('reply')
            ->modalHeading(__('alfakit-helpdesk::helpdesk.actions.reply_heading'))
            ->modalSubmitActionLabel(__('alfakit-helpdesk::helpdesk.actions.send'))
            ->modalWidth('3xl')
            ->authorize(static fn (?Model $record): bool => self::canUpdate($record))
            ->visible(static fn (?Model $record): bool => $record instanceof Ticket && ! $record->isMerged())
            ->schema(static fn (): array => [
                ...self::cannedReplyField(),
                self::editor(),
                self::attachmentsField(),
                Select::make('status')
                    ->label(__('alfakit-helpdesk::helpdesk.fields.status_after_reply'))
                    ->options(TicketStatus::options())
                    ->placeholder(__('alfakit-helpdesk::helpdesk.fields.keep_status')),
            ])
            ->action(static function (array $data, ?Model $record): void {
                $ticket = self::ticket($record);
                $status = is_string($data['status'] ?? null) ? TicketStatus::tryFrom($data['status']) : null;

                Tickets::reply($ticket, (string) ($data['body'] ?? ''), self::agent(), fromRequester: false, attachments: self::attachments($data), source: 'agent', status: $status);

                Notification::make()->success()->title(__('alfakit-helpdesk::helpdesk.notifications.replied'))->send();
            });
    }

    public static function note(): Action
    {
        return Action::make('note')
            ->label(__('alfakit-helpdesk::helpdesk.actions.note'))
            ->icon('sticky-note')
            ->color('warning')
            ->modalHeading(__('alfakit-helpdesk::helpdesk.actions.note_heading'))
            ->modalDescription(__('alfakit-helpdesk::helpdesk.actions.note_description'))
            ->modalSubmitActionLabel(__('alfakit-helpdesk::helpdesk.actions.add_note'))
            ->modalWidth('3xl')
            ->authorize(static fn (?Model $record): bool => self::canUpdate($record))
            ->visible(static fn (?Model $record): bool => $record instanceof Ticket && ! $record->isMerged())
            ->schema(static fn (): array => [self::editor(), self::attachmentsField()])
            ->action(static function (array $data, ?Model $record): void {
                Tickets::note(self::ticket($record), (string) ($data['body'] ?? ''), self::agent(), self::attachments($data));

                Notification::make()->success()->title(__('alfakit-helpdesk::helpdesk.notifications.noted'))->send();
            });
    }

    public static function assign(): Action
    {
        return Action::make('assign')
            ->label(__('alfakit-helpdesk::helpdesk.actions.assign'))
            ->icon('user-round-plus')
            ->color('gray')
            ->modalHeading(__('alfakit-helpdesk::helpdesk.actions.assign_heading'))
            ->modalWidth('md')
            ->authorize(static fn (?Model $record): bool => self::canUpdate($record))
            ->visible(static fn (?Model $record): bool => $record instanceof Ticket && ! $record->isMerged())
            ->fillForm(static fn (?Model $record): array => $record instanceof Ticket ? [
                'assignee_id' => $record->assignee_id === null ? null : (string) $record->assignee_id,
                'group_id' => $record->group_id === null ? null : (string) $record->group_id,
            ] : [])
            ->schema(static fn (): array => self::assignmentFields())
            ->action(static function (array $data, ?Model $record): void {
                $ticket = self::ticket($record);
                [$agent, $group] = self::resolveAssignment($data);

                Tickets::assign($ticket, $agent, self::agent(), changeGroup: Groups::isEnabled(), groupId: $group);

                Notification::make()->success()->title(__('alfakit-helpdesk::helpdesk.notifications.assigned'))->send();
            });
    }

    public static function take(): Action
    {
        return Action::make('take')
            ->label(__('alfakit-helpdesk::helpdesk.actions.take'))
            ->icon('hand')
            ->color('gray')
            ->authorize(static fn (?Model $record): bool => self::canUpdate($record))
            ->visible(static fn (?Model $record): bool => $record instanceof Ticket && ! $record->isMerged() && (string) $record->assignee_id !== (string) self::agent()?->getKey())
            ->action(static function (?Model $record): void {
                $agent = self::agent();

                abort_if($agent === null, 403);

                Tickets::assign(self::ticket($record), $agent, $agent);

                Notification::make()->success()->title(__('alfakit-helpdesk::helpdesk.notifications.taken'))->send();
            });
    }

    public static function status(): Action
    {
        return Action::make('status')
            ->label(__('alfakit-helpdesk::helpdesk.actions.status'))
            ->icon('circle-dot')
            ->color('gray')
            ->modalWidth('md')
            ->authorize(static fn (?Model $record): bool => self::canUpdate($record))
            ->visible(static fn (?Model $record): bool => $record instanceof Ticket && ! $record->isMerged())
            ->fillForm(static fn (?Model $record): array => $record instanceof Ticket ? ['status' => $record->status] : [])
            ->schema([
                Select::make('status')
                    ->label(__('alfakit-helpdesk::helpdesk.fields.status'))
                    ->options(TicketStatus::options())
                    ->required()
                    ->selectablePlaceholder(false),
            ])
            ->action(static function (array $data, ?Model $record): void {
                $status = TicketStatus::tryFrom((string) ($data['status'] ?? '')) ?? throw ValidationException::withMessages(['status' => __('validation.in', ['attribute' => 'status'])]);

                Tickets::changeStatus(self::ticket($record), $status, self::agent());

                Notification::make()->success()->title(__('alfakit-helpdesk::helpdesk.notifications.updated'))->send();
            });
    }

    public static function solve(): Action
    {
        return Action::make('solve')
            ->label(__('alfakit-helpdesk::helpdesk.actions.solve'))
            ->icon('circle-check')
            ->color('success')
            ->authorize(static fn (?Model $record): bool => self::canUpdate($record))
            ->visible(static fn (?Model $record): bool => $record instanceof Ticket && $record->getStatus()->isActive() && ! $record->isMerged())
            ->action(static function (?Model $record): void {
                Tickets::changeStatus(self::ticket($record), TicketStatus::Solved, self::agent());

                Notification::make()->success()->title(__('alfakit-helpdesk::helpdesk.notifications.solved'))->send();
            });
    }

    public static function details(): Action
    {
        return Action::make('details')
            ->label(__('alfakit-helpdesk::helpdesk.actions.details'))
            ->icon('pencil')
            ->color('gray')
            ->modalHeading(__('alfakit-helpdesk::helpdesk.actions.details_heading'))
            ->modalWidth('lg')
            ->authorize(static fn (?Model $record): bool => self::canUpdate($record))
            ->visible(static fn (?Model $record): bool => $record instanceof Ticket && ! $record->isMerged())
            ->fillForm(static fn (?Model $record): array => $record instanceof Ticket ? [
                'subject' => $record->subject,
                'priority' => $record->priority,
                'type' => $record->type,
                'labels' => $record->labels ?? [],
            ] : [])
            ->schema(static fn (): array => [
                TextInput::make('subject')->label(__('alfakit-helpdesk::helpdesk.fields.subject'))->required()->maxLength(255),
                Select::make('priority')->label(__('alfakit-helpdesk::helpdesk.fields.priority'))->options(TicketPriority::options())->required()->selectablePlaceholder(false),
                Select::make('type')->label(__('alfakit-helpdesk::helpdesk.fields.type'))->options(TicketType::options())->required()->selectablePlaceholder(false),
                ...(HelpdeskPlugin::get()->usesSpatieTags() ? [] : [
                    TagsInput::make('labels')->label(__('alfakit-helpdesk::helpdesk.fields.tags'))->nestedRecursiveRules(['string', 'max:50'])->rule('max:20'),
                ]),
            ])
            ->action(static function (array $data, ?Model $record): void {
                $changes = [
                    'subject' => (string) ($data['subject'] ?? ''),
                    'priority' => (string) ($data['priority'] ?? ''),
                    'type' => (string) ($data['type'] ?? ''),
                ];

                if (array_key_exists('labels', $data)) {
                    $changes['labels'] = is_array($data['labels']) ? array_values(array_filter($data['labels'], is_string(...))) : null;
                }

                Tickets::update(self::ticket($record), $changes, self::agent());

                Notification::make()->success()->title(__('alfakit-helpdesk::helpdesk.notifications.updated'))->send();
            });
    }

    /**
     * @param  Closure(): (Builder<Model>|null)|null  $tickets
     */
    public static function merge(?Closure $tickets = null): Action
    {
        $query = static function (?Model $record) use ($tickets): ?Builder {
            $query = $tickets !== null ? $tickets() : HelpdeskPlugin::get()->getTicketResource()::getEloquentQuery();

            if (! $query instanceof Builder || ! $record instanceof Ticket) {
                return null;
            }

            $query->whereKeyNot($record->getKey())->whereNull('merged_into_id')->where('status', '!=', TicketStatus::Closed->value);

            if ($record->requester_id !== null) {
                $query->where('requester_id', $record->requester_id);
            } else {
                $query->whereNull('requester_id')->where('requester_email', $record->requester_email ?? '');
            }

            return $query;
        };

        return Action::make('merge')
            ->label(__('alfakit-helpdesk::helpdesk.actions.merge'))
            ->icon('merge')
            ->color('gray')
            ->modalHeading(__('alfakit-helpdesk::helpdesk.actions.merge_heading'))
            ->modalDescription(__('alfakit-helpdesk::helpdesk.actions.merge_description'))
            ->modalSubmitActionLabel(__('alfakit-helpdesk::helpdesk.actions.merge'))
            ->modalWidth('lg')
            ->authorize(static fn (?Model $record): bool => self::canUpdate($record))
            ->visible(static fn (?Model $record): bool => $record instanceof Ticket && ! $record->isMerged())
            ->schema(static fn (?Model $record): array => [
                Select::make('target')
                    ->label(__('alfakit-helpdesk::helpdesk.fields.merge_into'))
                    ->required()
                    ->searchable()
                    ->options(static function () use ($query, $record): array {
                        $options = [];

                        foreach ($query($record)?->latest('last_activity_at')->limit(50)->get() ?? [] as $ticket) {
                            if ($ticket instanceof Ticket) {
                                $options[(string) $ticket->getKey()] = $ticket->getReference().' · '.$ticket->subject;
                            }
                        }

                        return $options;
                    }),
            ])
            ->action(static function (array $data, ?Model $record) use ($query): void {
                $source = self::ticket($record);
                $target = $query($source)?->whereKey($data['target'] ?? null)->first();

                if (! $target instanceof Ticket || ! self::canUpdate($target)) {
                    throw ValidationException::withMessages(['target' => __('alfakit-helpdesk::helpdesk.errors.merge_requester')]);
                }

                Tickets::merge($source, $target, self::agent());

                Notification::make()->success()->title(__('alfakit-helpdesk::helpdesk.notifications.merged', ['reference' => $target->getReference()]))->send();
            });
    }

    // ---------------------------------------------------------------- Bulk actions

    public static function bulkAssign(): BulkAction
    {
        return BulkAction::make('bulkAssign')
            ->label(__('alfakit-helpdesk::helpdesk.actions.assign'))
            ->icon('user-round-plus')
            ->color('gray')
            ->modalHeading(__('alfakit-helpdesk::helpdesk.actions.bulk_assign_heading'))
            ->modalWidth('md')
            ->schema(static fn (): array => self::assignmentFields())
            ->action(static function (array $data, Collection $records): void {
                [$agent, $group] = self::resolveAssignment($data);
                $count = 0;

                foreach ($records as $ticket) {
                    if ($ticket instanceof Ticket && ! $ticket->isMerged() && self::canUpdate($ticket)) {
                        Tickets::assign($ticket, $agent, self::agent(), changeGroup: Groups::isEnabled(), groupId: $group);
                        $count++;
                    }
                }

                Notification::make()->success()->title(trans_choice('alfakit-helpdesk::helpdesk.notifications.bulk_assigned', $count, ['count' => $count]))->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    public static function bulkClose(): BulkAction
    {
        return BulkAction::make('bulkClose')
            ->label(__('alfakit-helpdesk::helpdesk.actions.close'))
            ->icon('archive')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading(__('alfakit-helpdesk::helpdesk.actions.bulk_close_heading'))
            ->modalDescription(__('alfakit-helpdesk::helpdesk.actions.bulk_close_description'))
            ->action(static function (Collection $records): void {
                $count = 0;

                foreach ($records as $ticket) {
                    if ($ticket instanceof Ticket && ! $ticket->isMerged() && $ticket->getStatus() !== TicketStatus::Closed && self::canUpdate($ticket)) {
                        Tickets::changeStatus($ticket, TicketStatus::Closed, self::agent());
                        $count++;
                    }
                }

                Notification::make()->success()->title(trans_choice('alfakit-helpdesk::helpdesk.notifications.bulk_closed', $count, ['count' => $count]))->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    // ---------------------------------------------------------------- Fields

    public static function editor(string $name = 'body'): RichEditor
    {
        return RichEditor::make($name)
            ->hiddenLabel()
            ->placeholder(__('alfakit-helpdesk::helpdesk.fields.body_placeholder'))
            ->required()
            ->rule(new HasMessageContent)
            ->toolbarButtons(HelpdeskPlugin::get()->getToolbarButtons());
    }

    public static function attachmentsField(): FileUpload
    {
        return FileUpload::make('attachments')
            ->label(__('alfakit-helpdesk::helpdesk.fields.attachments'))
            ->multiple()
            ->disk(Attachments::disk())
            ->directory(Attachments::directory().'/'.now()->format('Y/m'))
            ->visibility('private')
            ->maxFiles(Attachments::maxFiles())
            ->maxSize(Attachments::maxSize())
            ->acceptedFileTypes(Attachments::acceptedFileTypes())
            ->storeFileNamesIn('attachment_names')
            ->visible(Attachments::maxFiles() > 0);
    }

    /**
     * The canned reply picker: choosing one adds it (placeholders filled in for this
     * ticket) to the editor.
     *
     * @return array<int, Select>
     */
    public static function cannedReplyField(): array
    {
        if (! HelpdeskPlugin::get()->hasCannedReplies()) {
            return [];
        }

        return [
            Select::make('canned_reply')
                ->label(__('alfakit-helpdesk::helpdesk.fields.canned_reply'))
                ->placeholder(__('alfakit-helpdesk::helpdesk.fields.canned_reply_placeholder'))
                ->options(static fn (): array => CannedReplies::options(self::agent()))
                ->searchable()
                ->live()
                ->dehydrated(false)
                ->afterStateUpdated(static function (mixed $state, Get $get, Set $set, ?Model $record): void {
                    $reply = CannedReplies::find($state, self::agent());

                    if ($reply instanceof CannedReply && $record instanceof Ticket) {
                        $body = $get('body');
                        $set('body', trim((is_string($body) && ! MessageBody::isBlank($body) ? $body : '').Placeholders::render($reply->body, $record, self::agent())));
                        $reply->increment('uses_count');
                    }

                    $set('canned_reply', null);
                }),
        ];
    }

    /**
     * @return array<int, Select>
     */
    public static function assignmentFields(): array
    {
        return [
            Select::make('assignee_id')
                ->label(__('alfakit-helpdesk::helpdesk.fields.assignee'))
                ->placeholder(__('alfakit-helpdesk::helpdesk.ticket.unassigned'))
                ->options(static fn (): array => Agents::options())
                ->searchable(),
            ...(Groups::isEnabled() ? [
                Select::make('group_id')
                    ->label(__('alfakit-helpdesk::helpdesk.fields.group'))
                    ->placeholder(__('alfakit-helpdesk::helpdesk.ticket.no_group'))
                    ->options(static fn (): array => Groups::options()),
            ] : []),
        ];
    }

    /**
     * The agent and group of an assignment form, checked against the allowed options (an
     * agent of another tenant, or a group of another tenant, is refused).
     *
     * @param  array<string, mixed>  $data
     * @return array{0: Model|null, 1: int|null}
     */
    public static function resolveAssignment(array $data): array
    {
        $agentKey = $data['assignee_id'] ?? null;
        $agent = $agentKey === null || $agentKey === '' ? null : Agents::find($agentKey);

        if ($agentKey !== null && $agentKey !== '' && $agent === null) {
            throw ValidationException::withMessages(['assignee_id' => __('alfakit-helpdesk::helpdesk.errors.not_an_agent')]);
        }

        $groupKey = $data['group_id'] ?? null;
        $group = $groupKey === null || $groupKey === '' ? null : Groups::find($groupKey);

        if ($groupKey !== null && $groupKey !== '' && $group === null) {
            throw ValidationException::withMessages(['group_id' => __('alfakit-helpdesk::helpdesk.errors.unknown_group')]);
        }

        return [$agent, $group === null ? null : (int) $group->getKey()];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array{path: string, name: string, size: int|null, mime: string|null}>
     */
    public static function attachments(array $data): array
    {
        $paths = $data['attachments'] ?? null;
        $names = $data['attachment_names'] ?? null;

        return Attachments::describe(is_array($paths) || is_string($paths) ? $paths : null, is_array($names) ? $names : null);
    }

    // ---------------------------------------------------------------- Helpers

    public static function agent(): ?Model
    {
        $user = (alfakit()->getCurrentPanel() ?? AlfakitHelpdesk::panel())?->auth()->user();

        return $user instanceof Model ? $user : null;
    }

    public static function canUpdate(?Model $record): bool
    {
        return $record instanceof Ticket && Authorization::allows('update', $record);
    }

    private static function ticket(?Model $record): Ticket
    {
        abort_unless($record instanceof Ticket, 404);

        return $record;
    }
}
