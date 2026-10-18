<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Resources;

use AlfaSix\Alfakit\Actions\DeleteAction;
use AlfaSix\Alfakit\Actions\EditAction;
use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\Alfakit\Forms\Components\Textarea;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\AlfakitHelpdesk\AlfakitHelpdesk;
use AlfaSix\AlfakitHelpdesk\Models\HelpdeskGroup;
use AlfaSix\AlfakitHelpdesk\Resources\Concerns\IsHelpdeskResource;
use AlfaSix\AlfakitHelpdesk\Resources\HelpdeskGroupResource\Pages;
use AlfaSix\AlfakitHelpdesk\Support\Agents;
use Illuminate\Database\Eloquent\Builder;

/**
 * Teams of agents that tickets are routed to.
 */
class HelpdeskGroupResource extends Resource
{
    use IsHelpdeskResource;

    protected static ?string $slug = 'helpdesk/groups';

    protected static ?string $recordTitleAttribute = 'name';

    protected static bool $isDiscovered = false;

    protected static ?string $navigationIcon = 'users-round';

    protected static ?string $tenantOwnershipRelationshipName = 'tenant';

    public static function getModel(): string
    {
        return AlfakitHelpdesk::groupModel();
    }

    public static function getModelLabel(): string
    {
        return (string) __('alfakit-helpdesk::helpdesk.group.label');
    }

    public static function getPluralModelLabel(): string
    {
        return (string) __('alfakit-helpdesk::helpdesk.group.plural');
    }

    public static function getNavigationSort(): ?int
    {
        return (static::getPlugin()->getNavigationSort() ?? 0) + 4;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label(__('alfakit-helpdesk::helpdesk.fields.name'))
                ->required()
                ->maxLength(255),
            Textarea::make('description')
                ->label(__('alfakit-helpdesk::helpdesk.fields.description'))
                ->rows(2)
                ->maxLength(1000),
            Select::make('agents')
                ->label(__('alfakit-helpdesk::helpdesk.fields.agents'))
                ->multiple()
                ->searchable()
                ->preload()
                ->relationship('agents', 'name', static function (Builder $query): Builder {
                    // Only the panel's agents (the members of the current tenant).
                    $agents = Agents::query();
                    $key = $query->getModel()->getQualifiedKeyName();

                    return $agents === null ? $query : $query->whereIn($key, $agents->toBase()->select($agents->getModel()->getQualifiedKeyName()));
                })
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query->withCount(['agents', 'tickets']))
            ->columns([
                TextColumn::make('name')
                    ->label(__('alfakit-helpdesk::helpdesk.fields.name'))
                    ->description(static fn (HelpdeskGroup $record): ?string => $record->description)
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),
                TextColumn::make('agents_count')
                    ->label(__('alfakit-helpdesk::helpdesk.fields.agents'))
                    ->numeric(),
                TextColumn::make('tickets_count')
                    ->label(__('alfakit-helpdesk::helpdesk.ticket.plural'))
                    ->numeric(),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->defaultSort('name')
            ->emptyStateIcon('users-round')
            ->emptyStateHeading(__('alfakit-helpdesk::helpdesk.group.empty'));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListHelpdeskGroups::route('/'),
            'create' => Pages\CreateHelpdeskGroup::route('/create'),
            'edit' => Pages\EditHelpdeskGroup::route('/{record}/edit'),
        ];
    }
}
