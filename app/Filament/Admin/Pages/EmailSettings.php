<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Models\StoreSetting;
use App\Services\Email\EmailNotificationService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class EmailSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-at-symbol';

    protected static UnitEnum|string|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Email notifications';

    protected static ?string $title = 'Email notifications';

    protected static ?string $slug = 'email-settings';

    // State
    public bool $email_enabled = true;

    public string $admin_notification_email = 'admin@b2bhaat.com';

    public string $testEmail = '';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user && in_array($user->role?->slug, ['super_admin', 'admin'], true);
    }

    public function mount(): void
    {
        $this->email_enabled = (bool) StoreSetting::get('email_enabled', config('mail.enabled', true));
        $this->admin_notification_email = (string) StoreSetting::get('admin_notification_email', config('mail.admin_address', 'admin@b2bhaat.com'));
    }

    public function getSubheading(): ?string
    {
        return 'Configure transactional email notifications, admin alert addresses, and test SMTP connectivity.';
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['default' => 1, 'lg' => 3])->schema([
                Form::make([EmbeddedSchema::make('form')])
                    ->id('form')
                    ->livewireSubmitHandler('save')
                    ->footer([
                        Actions::make([
                            Action::make('save')
                                ->label('Save settings')
                                ->submit('save')
                                ->keyBindings(['mod+s']),
                        ]),
                    ])
                    ->columnSpan(['lg' => 2]),

                Group::make([
                    Section::make('SMTP Diagnostics')
                        ->icon(Heroicon::OutlinedPaperAirplane)
                        ->schema([
                            TextInput::make('testEmail')
                                ->label('Test recipient email')
                                ->placeholder('you@example.com')
                                ->email(),
                        ])
                        ->footer([
                            Actions::make([
                                Action::make('sendTestEmail')
                                    ->label('Send Test Email')
                                    ->color('primary')
                                    ->action('sendTestEmail'),
                            ]),
                        ]),
                ])->columnSpan(['lg' => 1]),
            ]),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Email Dispatch Controls')
                ->schema([
                    Toggle::make('email_enabled')
                        ->label('Enable email notifications')
                        ->helperText('Master kill switch. When turned off, all outgoing email dispatches are safely skipped and logged.'),
                    TextInput::make('admin_notification_email')
                        ->label('Administrative notification recipients')
                        ->helperText('Email address to receive platform notifications for new vendor registrations, dispute filings, and large bulk orders.')
                        ->required()
                        ->email(),
                ]),
        ]);
    }

    public function save(): void
    {
        $this->form->validate();

        StoreSetting::set('email_enabled', $this->email_enabled ? '1' : '0', 'email', 'bool');
        StoreSetting::set('admin_notification_email', $this->admin_notification_email, 'email', 'string');

        Notification::make()
            ->title('Email settings saved')
            ->success()
            ->send();
    }

    public function sendTestEmail(EmailNotificationService $emailService): void
    {
        if (empty($this->testEmail)) {
            Notification::make()->title('Please provide an email address')->warning()->send();

            return;
        }

        $sent = $emailService->sendRaw(
            recipientEmail: $this->testEmail,
            subject: 'B2B Haat - SMTP Diagnostic Test',
            body: '<p>This is a test notification confirming that the B2B Haat SMTP mail server is operating correctly.</p>',
            templateKey: 'admin_test_diagnostic'
        );

        if ($sent) {
            Notification::make()
                ->title('Test email dispatched successfully')
                ->body("A test message was dispatched to {$this->testEmail}")
                ->success()
                ->send();
        } else {
            Notification::make()
                ->title('Test email failed to dispatch')
                ->body('Check email_logs or SMTP configuration.')
                ->danger()
                ->send();
        }
    }
}
