<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Jobs\SendSmsJob;
use App\Models\SmsLog;
use App\Models\SmsSetting;
use App\Services\Sms\Contracts\SmsGatewayInterface;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class SmsSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static UnitEnum|string|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'SMS gateway & OTP';

    protected static ?string $title = 'SMS gateway & OTP';

    protected static ?string $slug = 'sms-settings';

    // State
    public bool $sms_enabled = true;

    public string $sms_driver = 'bulksmsbd';

    public string $bulksmsbd_api_key = '';

    public string $bulksmsbd_sender_id = '';

    public string $bulksmsbd_base_url = 'http://bulksmsbd.net/api/smsapi';

    public string $bulksmsbd_balance_url = 'http://bulksmsbd.net/api/getBalanceApi';

    public int $otp_length = 6;

    public int $otp_expiry_minutes = 5;

    public int $otp_cooldown_seconds = 60;

    public int $otp_max_attempts = 5;

    public ?string $default_otp = null;

    public ?string $liveBalance = null;

    public string $testPhone = '';

    public static function fallbackOtpAllowed(): bool
    {
        return app()->environment('local', 'testing');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user && in_array($user->role?->slug, ['super_admin', 'admin'], true);
    }

    public function mount(): void
    {
        $this->sms_enabled = (bool) SmsSetting::get('sms_enabled', config('sms.enabled', true));
        $this->sms_driver = (string) SmsSetting::get('sms_driver', config('sms.default', 'bulksmsbd'));
        $this->bulksmsbd_api_key = (string) SmsSetting::get('bulksmsbd_api_key', config('sms.drivers.bulksmsbd.api_key', ''));
        $this->bulksmsbd_sender_id = (string) SmsSetting::get('bulksmsbd_sender_id', config('sms.drivers.bulksmsbd.sender_id', ''));
        $this->bulksmsbd_base_url = (string) SmsSetting::get('bulksmsbd_base_url', config('sms.drivers.bulksmsbd.base_url', 'http://bulksmsbd.net/api/smsapi'));
        $this->bulksmsbd_balance_url = (string) SmsSetting::get('bulksmsbd_balance_url', config('sms.drivers.bulksmsbd.balance_url', 'http://bulksmsbd.net/api/getBalanceApi'));

        $this->otp_length = (int) SmsSetting::get('otp_length', config('sms.otp.length', 6));
        $this->otp_expiry_minutes = (int) SmsSetting::get('otp_expiry_minutes', config('sms.otp.expiry_minutes', 5));
        $this->otp_cooldown_seconds = (int) SmsSetting::get('otp_cooldown_seconds', config('sms.otp.cooldown_seconds', 60));
        $this->otp_max_attempts = (int) SmsSetting::get('otp_max_attempts', config('sms.otp.max_attempts', 5));
        $this->default_otp = (string) SmsSetting::get('default_otp', config('sms.default_otp'));
    }

    public function getSubheading(): ?string
    {
        return 'Order updates, invoices and login codes are sent by SMS. Check the balance before big sales.';
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
                    Section::make('Account balance')
                        ->icon(Heroicon::OutlinedWallet)
                        ->schema([
                            TextEntry::make('liveBalance')
                                ->hiddenLabel()
                                ->state(fn (): ?string => $this->liveBalance)
                                ->placeholder('Not checked yet')
                                ->size('lg')
                                ->weight('bold'),
                        ])
                        ->footer([
                            Action::make('checkBalance')
                                ->label('Check balance')
                                ->icon(Heroicon::OutlinedArrowPath)
                                ->color('gray')
                                ->action('checkLiveBalance'),
                        ]),

                    Section::make('Send a test SMS')
                        ->description('Saves nothing. Use it after changing the API key or sender ID.')
                        ->icon(Heroicon::OutlinedPaperAirplane)
                        ->footer([
                            Action::make('sendTest')
                                ->label('Send test SMS')
                                ->icon(Heroicon::OutlinedPaperAirplane)
                                ->color('gray')
                                ->modalHeading('Send a test SMS')
                                ->modalSubmitActionLabel('Send')
                                ->schema([
                                    TextInput::make('phone')
                                        ->label('Mobile number')
                                        ->tel()
                                        ->placeholder('01712345678')
                                        ->required(),
                                ])
                                ->action(function (array $data, SmsGatewayInterface $sms): void {
                                    $this->testPhone = (string) $data['phone'];
                                    $this->sendTestSms($sms);
                                }),
                        ]),
                ])->columnSpan(['lg' => 1]),
            ]),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Gateway')
                ->description('Credentials from your BulkSMSBD account.')
                ->columns(['default' => 1, 'sm' => 2])
                ->schema([
                    Toggle::make('sms_enabled')
                        ->label('Send SMS')
                        ->helperText('Turn off to stop every SMS, including login codes. Customers then cannot sign in with OTP.')
                        ->columnSpanFull(),
                    Select::make('sms_driver')
                        ->label('Sending method')
                        ->options([
                            'bulksmsbd' => 'BulkSMSBD (real SMS)',
                            'log' => 'Write to log only (development)',
                            'mock' => 'Mock (automated tests)',
                        ])
                        ->native(false)
                        ->required()
                        ->live()
                        ->columnSpanFull(),
                    TextInput::make('bulksmsbd_api_key')
                        ->label('API key')
                        ->password()
                        ->revealable()
                        ->required(fn (Get $get): bool => $get('sms_driver') === 'bulksmsbd' && (bool) $get('sms_enabled'))
                        ->maxLength(255),
                    TextInput::make('bulksmsbd_sender_id')
                        ->label('Sender ID')
                        ->placeholder('e.g. 8809612345678')
                        ->required(fn (Get $get): bool => $get('sms_driver') === 'bulksmsbd' && (bool) $get('sms_enabled'))
                        ->maxLength(50),
                    TextInput::make('bulksmsbd_base_url')
                        ->label('Send endpoint')
                        ->url()
                        ->required()
                        ->columnSpanFull(),
                    TextInput::make('bulksmsbd_balance_url')
                        ->label('Balance endpoint')
                        ->url()
                        ->required()
                        ->columnSpanFull(),
                ]),

            Section::make('Login codes (OTP)')
                ->description('How the one-time codes customers type to sign in behave.')
                ->columns(['default' => 1, 'sm' => 2])
                ->schema([
                    TextInput::make('otp_length')
                        ->label('Code length')
                        ->suffix('digits')
                        ->integer()
                        ->minValue(4)
                        ->maxValue(8)
                        ->required(),
                    TextInput::make('otp_expiry_minutes')
                        ->label('Code expires after')
                        ->suffix('minutes')
                        ->integer()
                        ->minValue(1)
                        ->maxValue(30)
                        ->required(),
                    TextInput::make('otp_cooldown_seconds')
                        ->label('Wait before resending')
                        ->suffix('seconds')
                        ->integer()
                        ->minValue(15)
                        ->maxValue(600)
                        ->required(),
                    TextInput::make('otp_max_attempts')
                        ->label('Wrong tries before lock')
                        ->integer()
                        ->minValue(1)
                        ->maxValue(10)
                        ->required(),
                    TextInput::make('default_otp')
                        ->label('Fallback code (development only)')
                        ->helperText('Accepted when SMS is down, only on local or test servers. Leave empty to disable. Ignored in production.')
                        ->numeric()
                        ->maxLength(8)
                        ->visible(fn (): bool => self::fallbackOtpAllowed())
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public function save(): void
    {
        $this->form->validate();

        SmsSetting::set('sms_enabled', $this->sms_enabled ? 'true' : 'false');
        SmsSetting::set('sms_driver', $this->sms_driver);
        SmsSetting::set('bulksmsbd_api_key', $this->bulksmsbd_api_key);
        SmsSetting::set('bulksmsbd_sender_id', $this->bulksmsbd_sender_id);
        SmsSetting::set('bulksmsbd_base_url', $this->bulksmsbd_base_url);
        SmsSetting::set('bulksmsbd_balance_url', $this->bulksmsbd_balance_url);

        SmsSetting::set('otp_length', (string) $this->otp_length);
        SmsSetting::set('otp_expiry_minutes', (string) $this->otp_expiry_minutes);
        SmsSetting::set('otp_cooldown_seconds', (string) $this->otp_cooldown_seconds);
        SmsSetting::set('otp_max_attempts', (string) $this->otp_max_attempts);
        if (self::fallbackOtpAllowed()) {
            SmsSetting::set('default_otp', $this->default_otp ?? '');
        }

        Notification::make()
            ->title('SMS settings saved')
            ->success()
            ->send();
    }

    public function checkLiveBalance(SmsGatewayInterface $sms): void
    {
        $balance = $sms->checkBalance();

        if (is_numeric($balance)) {
            $this->liveBalance = 'BDT '.number_format((float) $balance, 2);
            Notification::make()
                ->title('SMS balance updated')
                ->body("BulkSMSBD balance: {$this->liveBalance}")
                ->success()
                ->send();
        } else {
            $this->liveBalance = (string) $balance;
            Notification::make()
                ->title('Balance could not be read')
                ->body((string) $balance)
                ->info()
                ->send();
        }
    }

    public function sendTestSms(SmsGatewayInterface $sms): void
    {
        if (empty($this->testPhone)) {
            Notification::make()->title('Enter a mobile number')->warning()->send();

            return;
        }

        $response = $sms->send($this->testPhone, 'This is a test notification from B2Bhaat Admin SMS Gateway.', 'test_dispatch');

        if ($response->isSuccessful()) {
            Notification::make()
                ->title('Test SMS sent')
                ->body("Message ID: {$response->messageId()}")
                ->success()
                ->send();
        } else {
            Notification::make()
                ->title('Test SMS failed')
                ->body($response->errorMessage() ?? 'Unknown provider error')
                ->danger()
                ->send();
        }
    }

    public function retryFailedSms(int $logId): void
    {
        $log = SmsLog::findOrFail($logId);

        SendSmsJob::dispatch(
            phone: $log->recipient_phone,
            message: $log->message,
            purpose: $log->purpose,
            bypassDeduplication: true
        );

        Notification::make()
            ->title('SMS queued again')
            ->body("Re-dispatched SMS to {$log->recipient_phone}")
            ->success()
            ->send();
    }
}
