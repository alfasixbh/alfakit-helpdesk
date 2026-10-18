<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Resources;

use AlfaSix\Alfakit\Actions\DeleteAction;
use AlfaSix\Alfakit\Actions\DeleteBulkAction;
use AlfaSix\Alfakit\Actions\EditAction;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Forms\Components\Toggle;
use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Tables\Columns\IconColumn;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\AlfakitHelpdesk\Actions\TicketActions;
use AlfaSix\AlfakitHelpdesk\AlfakitHelpdesk;
use AlfaSix\AlfakitHelpdesk\Models\CannedReply;
use AlfaSix\AlfakitHelpdesk\Resources\CannedReplyResource\Pages;
use AlfaSix\AlfakitHelpdesk\Resources\Concerns\IsHelpdeskResource;
use AlfaSix\AlfakitHelpdesk\Support\MessageBody;
use AlfaSix\AlfakitHelpdesk\Support\Placeholders;
use Illuminate\Database\Eloquent\Builder;

/**
 * Saved replies agents insert while answering. `{{ requester.name }}`-style placeholders
 * are filled in for the ticket (escaped, never evaluated).
 */
class CannedReplyResource extends Resource
{
    use IsHelpdeskResource;

    protected static ?string $slug = 'helpdesk/canned-replies';

    protected static ?string $recordTitleAttribute = 'title';

    protected static bool $isDiscovered = false;

    protected static ?string $navigationIcon = 'message-square-quote';

    protected static ?string $tenantOwnershipRelationshipName = 'tenant';

    public static function getModel(): string
    {
        return AlfakitHelpdesk::cannedReplyModel();
    }

    public static function getModelLabel(): string
    {
        return (string) __('alfakit-helpdesk::helpdesk.canned.label');
    }

    public static function getPluralModelLabel(): string
    {
        return (string) __('alfakit-helpdesk::helpdesk.canned.plural');
    }

    public static function getNavigationSort(): ?int
    {
        return (static::getPlugin()->getNavigationSort() ?? 0) + 3;
    }

    /**
     * Shared replies and the agent's own.
     *
     * @return Builder<CannedReply>
     */
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<CannedReply> $query */
        $query = parent::getEloquentQuery();

        return $query->visibleTo(TicketActions::agent());
    }

    public static function form(Schema $schema): Schema
    {
        $placeholders = implode(', ', array_map(static fn (string $name): string => '{{ '.$name.' }}', Placeholders::names()));

        return $schema->components([
            TextInput::make('title')
                ->label(__('alfakit-helpdesk::helpdesk.fields.title'))
                ->required()
                ->maxLength(255),
            TicketActions::editor()
                ->label(__('alfakit-helpdesk::helpdesk.fields.message'))
                ->hiddenLabel(false)
                ->helperText(__('alfakit-helpdesk::helpdesk.canned.placeholders_help', ['placeholders' => $placeholders]))
                ->columnSpanFull(),
            Toggle::make('is_shared')
                ->label(__('alfakit-helpdesk::helpdesk.fields.shared'))
                ->helperText(__('alfakit-helpdesk::helpdesk.canned.shared_help'))
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label(__('alfakit-helpdesk::helpdesk.fields.title'))
                    ->description(static fn (CannedReply $record): string => MessageBody::excerpt($record->body, 90))
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),
                IconColumn::make('is_shared')
                    ->label(__('alfakit-helpdesk::helpdesk.fields.shared'))
                    ->boolean(),
                TextColumn::make('uses_count')
                    ->label(__('alfakit-helpdesk::helpdesk.fields.uses'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label(__('alfakit-helpdesk::helpdesk.fields.updated_at'))
                    ->since()
                    ->sortable()
                    ->toggleable(),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->toolbarActions([DeleteBulkAction::make()])
            ->defaultSort('title')
            ->emptyStateIcon('message-square-quote')
            ->emptyStateHeading(__('alfakit-helpdesk::helpdesk.canned.empty'));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCannedReplies::route('/'),
            'create' => Pages\CreateCannedReply::route('/create'),
            'edit' => Pages\EditCannedReply::route('/{record}/edit'),
        ];
    }
}
