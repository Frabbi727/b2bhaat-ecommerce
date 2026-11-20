<?php

declare(strict_types=1);

namespace App\Notifications\Accounting;

use App\Filament\Admin\Pages\FinancialReconciliationPage;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Roadmap 4.2: the daily check found money records that disagree with the books. One admin bell entry per run.
 */
class BooksDoNotReconcile extends Notification
{
    use Queueable;

    /**
     * @param  list<string>  $problems
     */
    public function __construct(public array $problems) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $bell = FilamentNotification::make()
            ->title($this->toArray($notifiable)['title'])
            ->body(e($this->toArray($notifiable)['body']))
            ->danger()
            ->actions([
                Action::make('open')
                    ->label('Open reconciliation')
                    ->button()
                    ->url(FinancialReconciliationPage::getUrl(panel: 'admin')),
            ]);

        return [...$this->toArray($notifiable), ...$bell->getDatabaseMessage()];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'The books do not match: '.count($this->problems).' '.(count($this->problems) === 1 ? 'problem' : 'problems'),
            'body' => implode('. ', $this->problems).'. Open the reconciliation page to see which records differ.',
            'problems' => $this->problems,
        ];
    }
}
