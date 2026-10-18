<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Portal;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Standalone\Table as StandaloneTable;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Filters\SelectFilter;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\AlfakitHelpdesk\AlfakitHelpdesk;
use AlfaSix\AlfakitHelpdesk\Enums\TicketStatus;
use AlfaSix\AlfakitHelpdesk\Models\Ticket;
use AlfaSix\AlfakitHelpdesk\Portal\Concerns\InteractsWithPortal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The signed-in customer's tickets (and those sent from their verified email address).
 */
class MyTicketsTable extends StandaloneTable
{
    use InteractsWithPortal;

    public static function requiresAuthentication(): bool
    {
        return true;
    }

    public function authorize(): bool
    {
        return $this->customer() !== null;
    }

    public function mount(mixed ...$parameters): void
    {
        $this->heading(__('alfakit-helpdesk::helpdesk.portal.heading'));
    }

    /**
     * @return Builder<Ticket>
     */
    public function query(): Builder
    {
        $model = AlfakitHelpdesk::ticketModel();
        $customer = $this->customer();
        $query = $model::query()->whereNull('merged_into_id');

        if ($customer === null) {
            return $query->whereRaw('1 = 0');
        }

        $query->requestedBy($customer);
        $tenant = AlfakitHelpdesk::plugin()->getPublicTenant(request());

        if ($tenant instanceof Model) {
            $query->where('tenant_type', $tenant->getMorphClass())->where('tenant_id', $tenant->getKey());
        }

        return $query;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->query())
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->color('gray'),
                TextColumn::make('subject')
                    ->label(__('alfakit-helpdesk::helpdesk.fields.subject'))
                    ->searchable()
                    ->weight('medium')
                    ->wrap(),
                TextColumn::make('status')
                    ->label(__('alfakit-helpdesk::helpdesk.fields.status'))
                    ->state(static fn (Ticket $record): TicketStatus => $record->getStatus())
                    ->badge(),
                TextColumn::make('last_activity_at')
                    ->label(__('alfakit-helpdesk::helpdesk.fields.last_activity'))
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('alfakit-helpdesk::helpdesk.fields.status'))
                    ->options(TicketStatus::options()),
            ])
            ->recordUrl(static fn (Ticket $record): ?string => AlfakitHelpdesk::route('portal.show', ['ticket' => $record->getKey()]))
            ->headerActions([
                Action::make('new')
                    ->label(__('alfakit-helpdesk::helpdesk.portal.new_heading'))
                    ->icon('plus')
                    ->url(static fn (): ?string => AlfakitHelpdesk::route('portal.create')),
            ])
            ->defaultSort('last_activity_at', 'desc')
            ->emptyStateIcon('life-buoy')
            ->emptyStateHeading(__('alfakit-helpdesk::helpdesk.portal.empty_heading'))
            ->emptyStateDescription(__('alfakit-helpdesk::helpdesk.portal.empty_description'));
    }
}
