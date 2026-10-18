<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Portal;

use AlfaSix\Alfakit\Forms\Components\Textarea;
use AlfaSix\Alfakit\Forms\Components\ToggleButtons;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Schemas\Components\Callout;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Standalone\Form;
use AlfaSix\AlfakitHelpdesk\Models\Ticket;
use AlfaSix\AlfakitHelpdesk\Portal\Concerns\InteractsWithPortal;
use AlfaSix\AlfakitHelpdesk\Support\Tickets;

/**
 * "How did we do?": the requester rates a solved ticket from 1 to 5 (CSAT).
 */
class SatisfactionForm extends Form
{
    use InteractsWithPortal;

    public ?Ticket $ticket = null;

    public bool $guest = false;

    public function authorize(): bool
    {
        return $this->mayView($this->ticket, $this->guest);
    }

    public function mount(mixed ...$parameters): void
    {
        $this->heading(__('alfakit-helpdesk::helpdesk.csat.heading'));
        $this->subheading(__('alfakit-helpdesk::helpdesk.csat.description'));
    }

    protected function isRated(): bool
    {
        return $this->ticket?->satisfaction_rated_at !== null;
    }

    public function form(Schema $schema): Schema
    {
        if ($this->ticket === null || $this->isRated() || ! $this->ticket->canBeRated()) {
            return $schema;
        }

        $options = [];

        foreach ([5, 4, 3, 2, 1] as $score) {
            $options[(string) $score] = (string) __('alfakit-helpdesk::helpdesk.csat.options.'.$score);
        }

        return $schema->components([
            ToggleButtons::make('rating')
                ->label(__('alfakit-helpdesk::helpdesk.fields.rating'))
                ->options($options)
                ->colors(['5' => 'success', '4' => 'success', '3' => 'warning', '2' => 'danger', '1' => 'danger'])
                ->inline()
                ->required()
                ->in(array_keys($options)),
            Textarea::make('comment')
                ->label(__('alfakit-helpdesk::helpdesk.fields.rating_comment'))
                ->rows(3)
                ->maxLength(2000),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        if ($this->ticket === null || ! $this->isRated()) {
            return $schema;
        }

        return $schema->components([
            Callout::make(__('alfakit-helpdesk::helpdesk.csat.thanks_heading'))
                ->description(__('alfakit-helpdesk::helpdesk.csat.thanks_description'))
                ->success()
                ->icon('heart'),
        ]);
    }

    protected function getFormActions(): array
    {
        return $this->ticket !== null && ! $this->isRated() && $this->ticket->canBeRated() ? parent::getFormActions() : [];
    }

    public function getSubmitLabel(): string
    {
        return (string) __('alfakit-helpdesk::helpdesk.csat.submit');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function submit(array $data): mixed
    {
        $ticket = $this->ticket;

        abort_if($ticket === null, 404);

        if ($ticket->satisfaction_rated_at !== null) {
            $this->failWith(__('alfakit-helpdesk::helpdesk.csat.already_rated'));
        }

        Tickets::rate($ticket, (int) ($data['rating'] ?? 0), is_string($data['comment'] ?? null) ? $data['comment'] : null);

        Notification::make()->success()->title(__('alfakit-helpdesk::helpdesk.csat.thanks_heading'))->send();

        return back();
    }
}
