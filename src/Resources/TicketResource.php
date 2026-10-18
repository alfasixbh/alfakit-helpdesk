<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Resources;

use AlfaSix\Alfakit\Actions\ViewAction;
use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\Alfakit\Forms\Components\TagsInput;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Infolists\Components\RepeatableEntry;
use AlfaSix\Alfakit\Infolists\Components\TextEntry;
use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Schemas\Components\Grid;
use AlfaSix\Alfakit\Schemas\Components\Section;
use AlfaSix\Alfakit\Schemas\Components\Utilities\Get;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Filters\Filter;
use AlfaSix\Alfakit\Tables\Filters\SelectFilter;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\AlfakitHelpdesk\Actions\TicketActions;
use AlfaSix\AlfakitHelpdesk\AlfakitHelpdesk;
use AlfaSix\AlfakitHelpdesk\Enums\MessageKind;
use AlfaSix\AlfakitHelpdesk\Enums\SlaState;
use AlfaSix\AlfakitHelpdesk\Enums\TicketPriority;
use AlfaSix\AlfakitHelpdesk\Enums\TicketStatus;
use AlfaSix\AlfakitHelpdesk\Enums\TicketType;
use AlfaSix\AlfakitHelpdesk\Models\Ticket;
use AlfaSix\AlfakitHelpdesk\Models\TicketMessage;
use AlfaSix\AlfakitHelpdesk\Resources\Concerns\IsHelpdeskResource;
use AlfaSix\AlfakitHelpdesk\Resources\TicketResource\Pages;
use AlfaSix\AlfakitHelpdesk\Sla\Sla;
use AlfaSix\AlfakitHelpdesk\Support\Agents;
use AlfaSix\AlfakitHelpdesk\Support\Attachments;
use AlfaSix\AlfakitHelpdesk\Support\Groups;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Tickets for agents: the list with views and SLA filters, a create page (a ticket on
 * behalf of a requester) and the ticket page with its conversation and actions. Extend
 * it and pass your class to `HelpdeskPlugin::make()->ticketResource(...)`.
 */
class TicketResource extends Resource
{
    use IsHelpdeskResource;

    protected static ?string $slug = 'helpdesk/tickets';

    protected static ?string $recordTitleAttribute = 'subject';

    protected static bool $isDiscovered = false;

    protected static ?string $navigationIcon = 'life-buoy';

    protected static ?string $tenantOwnershipRelationshipName = 'tenant';

    public static function getModel(): string
    {
        return AlfakitHelpdesk::ticketModel();
    }

    public static function getModelLabel(): string
    {
        return (string) __('alfakit-helpdesk::helpdesk.ticket.label');
    }

    public static function getPluralModelLabel(): string
    {
        return (string) __('alfakit-helpdesk::helpdesk.ticket.plural');
    }

    public static function getNavigationSort(): ?int
    {
        return (static::getPlugin()->getNavigationSort() ?? 0) + 1;
    }

    /** Open tickets nobody works on yet. */
    public static function getNavigationBadge(): string|int|null
    {
        try {
            /** @var Builder<Ticket> $query */
            $query = static::getEloquentQuery();
            $count = $query->where('status', TicketStatus::Open->value)->whereNull('assignee_id')->whereNull('merged_into_id')->count();
        } catch (Throwable) {
            return null;
        }

        return $count > 0 ? $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    // ---------------------------------------------------------------- Create form

    public static function form(Schema $schema): Schema
    {
        $tags = static::getPlugin()->usesSpatieTags()
            ? \AlfaSix\AlfakitTags\Forms\Components\SpatieTagsInput::make('tags')->type('helpdesk')->label(__('alfakit-helpdesk::helpdesk.fields.tags'))
            : TagsInput::make('labels')->label(__('alfakit-helpdesk::helpdesk.fields.tags'))->nestedRecursiveRules(['string', 'max:50'])->rule('max:20');

        return $schema->columns(['default' => 1, 'lg' => 3])->components([
            Section::make(__('alfakit-helpdesk::helpdesk.sections.conversation'))
                ->columnSpan(['default' => 1, 'lg' => 2])
                ->schema([
                    TextInput::make('subject')
                        ->label(__('alfakit-helpdesk::helpdesk.fields.subject'))
                        ->required()
                        ->maxLength(255),
                    TicketActions::editor()->label(__('alfakit-helpdesk::helpdesk.fields.message')),
                    TicketActions::attachmentsField(),
                ]),
            Section::make(__('alfakit-helpdesk::helpdesk.sections.details'))
                ->columnSpan(['default' => 1, 'lg' => 1])
                ->schema([
                    Select::make('requester_id')
                        ->label(__('alfakit-helpdesk::helpdesk.fields.requester'))
                        ->helperText(__('alfakit-helpdesk::helpdesk.fields.requester_help'))
                        ->searchable()
                        ->getSearchResultsUsing(static fn (string $search): array => static::searchRequesters($search))
                        ->getOptionLabelUsing(static fn (mixed $value): ?string => static::requesterLabel($value))
                        ->live(),
                    TextInput::make('requester_email')
                        ->label(__('alfakit-helpdesk::helpdesk.fields.requester_email'))
                        ->email()
                        ->maxLength(255)
                        ->required(static fn (Get $get): bool => blank($get('requester_id')))
                        ->visible(static fn (Get $get): bool => blank($get('requester_id'))),
                    TextInput::make('requester_name')
                        ->label(__('alfakit-helpdesk::helpdesk.fields.requester_name'))
                        ->maxLength(255)
                        ->visible(static fn (Get $get): bool => blank($get('requester_id'))),
                    Select::make('priority')
                        ->label(__('alfakit-helpdesk::helpdesk.fields.priority'))
                        ->options(TicketPriority::options())
                        ->default(TicketPriority::Normal->value)
                        ->required()
                        ->selectablePlaceholder(false),
                    Select::make('type')
                        ->label(__('alfakit-helpdesk::helpdesk.fields.type'))
                        ->options(TicketType::options())
                        ->default(TicketType::Question->value)
                        ->required()
                        ->selectablePlaceholder(false),
                    ...TicketActions::assignmentFields(),
                    $tags,
                ]),
        ]);
    }

    /**
     * Users matching a search, for the requester select.
     *
     * @return array<string, string>
     */
    public static function searchRequesters(string $search): array
    {
        $model = AlfakitHelpdesk::userModel(alfakit()->getCurrentOrDefaultPanel()->getAuthGuard());
        $search = trim($search);

        if ($model === null || mb_strlen($search) < 2) {
            return [];
        }

        $like = '%'.mb_strtolower($search).'%';
        $query = $model::query()->where(static function (Builder $query) use ($like): void {
            foreach (['name', 'email'] as $column) {
                $query->orWhereRaw('LOWER('.$query->getQuery()->getGrammar()->wrap($query->qualifyColumn($column)).') LIKE ?', [$like]);
            }
        });

        $options = [];

        foreach ($query->limit(20)->get() as $user) {
            $options[(string) $user->getKey()] = static::formatRequester($user);
        }

        return $options;
    }

    public static function requesterLabel(mixed $value): ?string
    {
        $model = AlfakitHelpdesk::userModel(alfakit()->getCurrentOrDefaultPanel()->getAuthGuard());
        $user = $model === null || ! is_scalar($value) ? null : $model::query()->whereKey($value)->first();

        return $user instanceof Model ? static::formatRequester($user) : null;
    }

    protected static function formatRequester(Model $user): string
    {
        $name = $user->getAttribute('name');
        $email = $user->getAttribute('email');

        return trim((is_scalar($name) ? (string) $name : '').(is_scalar($email) && $email !== '' ? ' <'.$email.'>' : ''));
    }

    // ---------------------------------------------------------------- Ticket page

    public static function infolist(Schema $schema): Schema
    {
        $tags = static::getPlugin()->usesSpatieTags()
            ? \AlfaSix\AlfakitTags\Infolists\Components\SpatieTagsEntry::make('tags')->type('helpdesk')->label(__('alfakit-helpdesk::helpdesk.fields.tags'))
            : TextEntry::make('labels')->label(__('alfakit-helpdesk::helpdesk.fields.tags'))->badge()->color('gray')->placeholder(__('alfakit-helpdesk::helpdesk.ticket.none'));

        return $schema->columns(['default' => 1, 'lg' => 3])->components([
            Section::make(__('alfakit-helpdesk::helpdesk.sections.conversation'))
                ->key('conversation')
                ->columnSpan(['default' => 1, 'lg' => 2])
                ->schema([
                    RepeatableEntry::make('messages')
                        ->hiddenLabel()
                        ->state(static fn (Ticket $record): mixed => $record->messages()->with('author')->get())
                        ->columns(['default' => 1, 'sm' => 3])
                        ->schema(static::messageEntries()),
                ]),
            Grid::make(1)
                ->columnSpan(['default' => 1, 'lg' => 1])
                ->schema([
                    Section::make(__('alfakit-helpdesk::helpdesk.sections.details'))
                        ->key('details')
                        ->schema([
                            TextEntry::make('status')
                                ->label(__('alfakit-helpdesk::helpdesk.fields.status'))
                                ->state(static fn (Ticket $record): TicketStatus => $record->getStatus())
                                ->badge(),
                            TextEntry::make('priority')
                                ->label(__('alfakit-helpdesk::helpdesk.fields.priority'))
                                ->state(static fn (Ticket $record): TicketPriority => $record->getPriority())
                                ->badge(),
                            TextEntry::make('type')
                                ->label(__('alfakit-helpdesk::helpdesk.fields.type'))
                                ->state(static fn (Ticket $record): TicketType => $record->getType()),
                            TextEntry::make('channel')
                                ->label(__('alfakit-helpdesk::helpdesk.fields.channel'))
                                ->state(static fn (Ticket $record): string => $record->getChannel()->getLabel())
                                ->icon(static fn (Ticket $record): string => $record->getChannel()->getIcon()),
                            TextEntry::make('requester')
                                ->label(__('alfakit-helpdesk::helpdesk.fields.requester'))
                                ->state(static fn (Ticket $record): string => $record->getRequesterName())
                                ->helperText(static fn (Ticket $record): ?string => $record->getRequesterEmail()),
                            TextEntry::make('assignee')
                                ->label(__('alfakit-helpdesk::helpdesk.fields.assignee'))
                                ->state(static fn (Ticket $record): string => Agents::name($record->assignee_id === null ? null : $record->assignee)),
                            TextEntry::make('group')
                                ->label(__('alfakit-helpdesk::helpdesk.fields.group'))
                                ->state(static fn (Ticket $record): ?string => $record->group?->name)
                                ->placeholder(__('alfakit-helpdesk::helpdesk.ticket.no_group'))
                                ->visible(static fn (): bool => Groups::isEnabled()),
                            $tags,
                            TextEntry::make('merged_into')
                                ->label(__('alfakit-helpdesk::helpdesk.fields.merged_into'))
                                ->state(static fn (Ticket $record): ?string => $record->mergedInto?->getReference())
                                ->url(static fn (Ticket $record): ?string => $record->mergedInto === null ? null : static::getUrl('view', ['record' => $record->mergedInto]))
                                ->visible(static fn (Ticket $record): bool => $record->isMerged()),
                        ]),
                    Section::make(__('alfakit-helpdesk::helpdesk.sections.sla'))
                        ->key('sla')
                        ->schema([
                            TextEntry::make('sla_policy')
                                ->label(__('alfakit-helpdesk::helpdesk.fields.sla_policy'))
                                ->placeholder(__('alfakit-helpdesk::helpdesk.sla.no_policy')),
                            TextEntry::make('first_response_state')
                                ->label(__('alfakit-helpdesk::helpdesk.sla.first_response'))
                                ->state(static fn (Ticket $record): SlaState => Sla::firstResponseState($record))
                                ->badge()
                                ->visible(static fn (Ticket $record): bool => $record->first_response_due_at !== null),
                            TextEntry::make('first_response_due_at')
                                ->label(__('alfakit-helpdesk::helpdesk.sla.due'))
                                ->since()
                                ->dateTimeTooltip()
                                ->visible(static fn (Ticket $record): bool => $record->first_response_due_at !== null && $record->first_responded_at === null),
                            TextEntry::make('resolution_state')
                                ->label(__('alfakit-helpdesk::helpdesk.sla.resolution'))
                                ->state(static fn (Ticket $record): SlaState => Sla::resolutionState($record))
                                ->badge()
                                ->visible(static fn (Ticket $record): bool => $record->resolution_due_at !== null),
                            TextEntry::make('resolution_due_at')
                                ->label(__('alfakit-helpdesk::helpdesk.sla.due'))
                                ->since()
                                ->dateTimeTooltip()
                                ->visible(static fn (Ticket $record): bool => $record->resolution_due_at !== null && $record->solved_at === null && $record->sla_paused_at === null),
                        ]),
                    Section::make(__('alfakit-helpdesk::helpdesk.sections.satisfaction'))
                        ->key('satisfaction')
                        ->visible(static fn (Ticket $record): bool => $record->satisfaction_rated_at !== null)
                        ->schema([
                            TextEntry::make('satisfaction_rating')
                                ->label(__('alfakit-helpdesk::helpdesk.fields.rating'))
                                ->state(static fn (Ticket $record): string => __('alfakit-helpdesk::helpdesk.csat.score', ['rating' => (int) $record->satisfaction_rating]))
                                ->badge()
                                ->color(static fn (Ticket $record): string => (int) $record->satisfaction_rating >= 4 ? 'success' : ((int) $record->satisfaction_rating === 3 ? 'warning' : 'danger')),
                            TextEntry::make('satisfaction_comment')
                                ->label(__('alfakit-helpdesk::helpdesk.fields.rating_comment'))
                                ->placeholder(__('alfakit-helpdesk::helpdesk.ticket.none')),
                        ]),
                ]),
        ]);
    }

    /**
     * The entries of one message of the conversation.
     *
     * @return array<int, TextEntry>
     */
    public static function messageEntries(): array
    {
        return [
            TextEntry::make('author_label')
                ->hiddenLabel()
                ->state(static fn (mixed $record): string => $record instanceof TicketMessage ? $record->getAuthorName() : '')
                ->weight('semibold')
                ->icon(static fn (mixed $record): string => ! $record instanceof TicketMessage ? '' : match (true) {
                    $record->getKind() === MessageKind::Event => 'history',
                    $record->from_requester => 'user-round',
                    default => 'headset',
                }),
            TextEntry::make('kind_label')
                ->hiddenLabel()
                ->state(static fn (mixed $record): string => ! $record instanceof TicketMessage ? '' : match (true) {
                    $record->isInternal() => (string) __('alfakit-helpdesk::helpdesk.message.internal'),
                    $record->getKind() === MessageKind::Event => (string) __('alfakit-helpdesk::helpdesk.message.event'),
                    $record->from_requester => (string) __('alfakit-helpdesk::helpdesk.message.requester'),
                    default => (string) __('alfakit-helpdesk::helpdesk.message.public'),
                })
                ->badge()
                ->color(static fn (mixed $record): string => ! $record instanceof TicketMessage ? '' : match (true) {
                    $record->isInternal() => 'warning',
                    $record->getKind() === MessageKind::Event => 'gray',
                    $record->from_requester => 'info',
                    default => 'success',
                }),
            TextEntry::make('created_at')
                ->hiddenLabel()
                ->since()
                ->dateTimeTooltip()
                ->color('gray')
                ->alignEnd(),
            TextEntry::make('body')
                ->hiddenLabel()
                ->html()
                ->prose()
                ->columnSpanFull(),
            TextEntry::make('files')
                ->hiddenLabel()
                ->state(static fn (mixed $record): ?string => $record instanceof TicketMessage ? static::attachmentLinks($record) : null)
                ->html()
                ->columnSpanFull()
                ->visible(static fn (mixed $record): bool => $record instanceof TicketMessage && $record->getAttachments() !== []),
        ];
    }

    protected static function attachmentLinks(TicketMessage $message): ?string
    {
        $links = [];

        foreach (Attachments::forDisplay($message) as $file) {
            $links[] = $file['url'] === null
                ? '<li>'.e($file['name']).'</li>'
                : '<li><a href="'.e($file['url']).'" target="_blank" rel="noopener">'.e($file['name']).'</a></li>';
        }

        return $links === [] ? null : '<ul>'.implode('', $links).'</ul>';
    }

    // ---------------------------------------------------------------- List

    public static function table(Table $table): Table
    {
        $tags = static::getPlugin()->usesSpatieTags()
            ? \AlfaSix\AlfakitTags\Tables\Columns\SpatieTagsColumn::make('tags')->type('helpdesk')->label(__('alfakit-helpdesk::helpdesk.fields.tags'))->toggleable(isToggledHiddenByDefault: true)
            : TextColumn::make('labels')->label(__('alfakit-helpdesk::helpdesk.fields.tags'))->badge()->color('gray')->toggleable(isToggledHiddenByDefault: true);

        return $table
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query->with(['assignee', 'group', 'requester']))
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable()
                    ->searchable()
                    ->color('gray'),
                TextColumn::make('subject')
                    ->label(__('alfakit-helpdesk::helpdesk.fields.subject'))
                    ->description(static fn (Ticket $record): string => $record->getRequesterName())
                    ->searchable(['subject', 'requester_name', 'requester_email'])
                    ->weight('medium')
                    ->wrap(),
                TextColumn::make('status')
                    ->label(__('alfakit-helpdesk::helpdesk.fields.status'))
                    ->state(static fn (Ticket $record): TicketStatus => $record->getStatus())
                    ->badge()
                    ->sortable(),
                TextColumn::make('priority')
                    ->label(__('alfakit-helpdesk::helpdesk.fields.priority'))
                    ->state(static fn (Ticket $record): TicketPriority => $record->getPriority())
                    ->badge()
                    ->sortable(),
                TextColumn::make('sla')
                    ->label(__('alfakit-helpdesk::helpdesk.fields.sla'))
                    ->state(static fn (Ticket $record): SlaState => Sla::state($record))
                    ->description(static function (Ticket $record): ?string {
                        $due = Sla::summary($record)['due'];

                        return $due === null ? null : $due->diffForHumans();
                    })
                    ->badge(),
                TextColumn::make('assignee')
                    ->label(__('alfakit-helpdesk::helpdesk.fields.assignee'))
                    ->state(static fn (Ticket $record): ?string => $record->assignee_id === null ? null : Agents::name($record->assignee))
                    ->placeholder(__('alfakit-helpdesk::helpdesk.ticket.unassigned')),
                TextColumn::make('group.name')
                    ->label(__('alfakit-helpdesk::helpdesk.fields.group'))
                    ->placeholder(__('alfakit-helpdesk::helpdesk.ticket.no_group'))
                    ->toggleable()
                    ->visible(static fn (): bool => Groups::isEnabled()),
                TextColumn::make('channel')
                    ->label(__('alfakit-helpdesk::helpdesk.fields.channel'))
                    ->state(static fn (Ticket $record): string => $record->getChannel()->getLabel())
                    ->icon(static fn (Ticket $record): string => $record->getChannel()->getIcon())
                    ->toggleable(isToggledHiddenByDefault: true),
                $tags,
                TextColumn::make('last_activity_at')
                    ->label(__('alfakit-helpdesk::helpdesk.fields.last_activity'))
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label(__('alfakit-helpdesk::helpdesk.fields.created_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('alfakit-helpdesk::helpdesk.fields.status'))
                    ->options(TicketStatus::options())
                    ->multiple(),
                SelectFilter::make('priority')
                    ->label(__('alfakit-helpdesk::helpdesk.fields.priority'))
                    ->options(TicketPriority::options())
                    ->multiple(),
                SelectFilter::make('assignee_id')
                    ->label(__('alfakit-helpdesk::helpdesk.fields.assignee'))
                    ->options(static fn (): array => Agents::options()),
                SelectFilter::make('group_id')
                    ->label(__('alfakit-helpdesk::helpdesk.fields.group'))
                    ->options(static fn (): array => Groups::options())
                    ->hidden(static fn (): bool => ! Groups::isEnabled()),
                Filter::make('due_soon')
                    ->label(__('alfakit-helpdesk::helpdesk.views.due_soon'))
                    ->toggle()
                    ->query(static fn (Builder $query): Builder => $query->scopes('dueSoon')),
                Filter::make('breached')
                    ->label(__('alfakit-helpdesk::helpdesk.views.breached'))
                    ->toggle()
                    ->query(static fn (Builder $query): Builder => $query->scopes('breached')),
                Filter::make('unassigned')
                    ->label(__('alfakit-helpdesk::helpdesk.views.unassigned'))
                    ->toggle()
                    ->query(static fn (Builder $query): Builder => $query->whereNull('assignee_id')),
            ])
            ->recordActions([
                ViewAction::make(),
                TicketActions::take(),
            ])
            ->toolbarActions([
                TicketActions::bulkAssign(),
                TicketActions::bulkClose(),
            ])
            ->defaultSort('last_activity_at', 'desc')
            ->stackedOnMobile()
            ->emptyStateIcon('life-buoy')
            ->emptyStateHeading(__('alfakit-helpdesk::helpdesk.empty.heading'))
            ->emptyStateDescription(__('alfakit-helpdesk::helpdesk.empty.description'));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTickets::route('/'),
            'create' => Pages\CreateTicket::route('/create'),
            'view' => Pages\ViewTicket::route('/{record}'),
        ];
    }
}
