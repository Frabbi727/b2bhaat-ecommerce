<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Jobs\SendSmsJob;
use App\Models\SmsLog;
use App\Models\SmsSetting;
use App\Services\Sms\Contracts\SmsGatewayInterface;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use UnitEnum;

class SmsSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationLabel = 'SMS Gateway & OTP';

    protected static ?string $title = 'SMS Gateway Configuration & Live Balance';

    protected static ?string $slug = 'sms-settings';

    protected static UnitEnum|string|null $navigationGroup = 'Settings & Management';

    protected static ?int $navigationSort = 11;

    protected string $view = 'filament.admin.pages.sms-settings';

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

    public ?string $default_otp = '123456';

    public ?string $liveBalance = null;

    public string $testPhone = '';

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
        $this->default_otp = (string) SmsSetting::get('default_otp', config('sms.default_otp', '123456'));
    }

    public function save(): void
    {
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
        SmsSetting::set('default_otp', $this->default_otp ?? '');

        Notification::make()
            ->title('SMS & OTP Settings Saved')
            ->success()
            ->send();
    }

    public function checkLiveBalance(SmsGatewayInterface $sms): void
    {
        $balance = $sms->checkBalance();

        if (is_numeric($balance)) {
            $this->liveBalance = 'BDT '.number_format((float) $balance, 2);
            Notification::make()
                ->title('BulkSMSBD Live Balance')
                ->body("Your current account balance is {$this->liveBalance}")
                ->success()
                ->send();
        } else {
            $this->liveBalance = (string) $balance;
            Notification::make()
                ->title('Balance Query Response')
                ->body((string) $balance)
                ->info()
                ->send();
        }
    }

    public function sendTestSms(SmsGatewayInterface $sms): void
    {
        if (empty($this->testPhone)) {
            Notification::make()->title('Recipient mobile number required')->warning()->send();

            return;
        }

        $response = $sms->send($this->testPhone, 'This is a test notification from B2Bhaat Admin SMS Gateway.', 'test_dispatch');

        if ($response->isSuccessful()) {
            Notification::make()
                ->title('Test SMS Sent Successfully')
                ->body("Message ID: {$response->messageId()}")
                ->success()
                ->send();
        } else {
            Notification::make()
                ->title('Test SMS Failed')
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
            ->title('SMS Retry Dispatched')
            ->body("Re-dispatched SMS to {$log->recipient_phone}")
            ->success()
            ->send();
    }
}
