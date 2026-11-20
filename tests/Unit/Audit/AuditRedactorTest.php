<?php

declare(strict_types=1);

namespace Tests\Unit\Audit;

use App\Services\Audit\AuditRedactor;
use PHPUnit\Framework\TestCase;

class AuditRedactorTest extends TestCase
{
    public function test_redacts_passwords_and_sensitive_tokens(): void
    {
        $input = [
            'name' => 'John Doe',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'remember_token' => 'token123',
            'handover_code' => '984512',
            'code_hash' => 'sha256hash',
            'otp' => '123456',
            'otp_code' => '123456',
            'sms_gateway_password' => 'supersecret',
            'card_number' => '4111111111111111',
            'cvv' => '123',
            'nested' => [
                'api_key' => 'secret_key',
                'regular_field' => 'value',
                'customer_secret' => 'confidential',
            ],
        ];

        $redactor = new AuditRedactor;
        $sanitized = $redactor->redact($input);

        $this->assertEquals('John Doe', $sanitized['name']);
        $this->assertEquals('[REDACTED]', $sanitized['password']);
        $this->assertEquals('[REDACTED]', $sanitized['password_confirmation']);
        $this->assertEquals('[REDACTED]', $sanitized['remember_token']);
        $this->assertEquals('[REDACTED]', $sanitized['handover_code']);
        $this->assertEquals('[REDACTED]', $sanitized['code_hash']);
        $this->assertEquals('[REDACTED]', $sanitized['otp']);
        $this->assertEquals('[REDACTED]', $sanitized['otp_code']);
        $this->assertEquals('[REDACTED]', $sanitized['sms_gateway_password']);
        $this->assertEquals('[REDACTED]', $sanitized['card_number']);
        $this->assertEquals('[REDACTED]', $sanitized['cvv']);
        $this->assertEquals('[REDACTED]', $sanitized['nested']['api_key']);
        $this->assertEquals('value', $sanitized['nested']['regular_field']);
        $this->assertEquals('[REDACTED]', $sanitized['nested']['customer_secret']);
    }

    public function test_redacts_camel_case_and_kebab_case_sensitive_keys(): void
    {
        $input = [
            'apiKey' => '12345',
            'cardNumber' => '1234-5678-9012-3456',
            'smsGatewayPassword' => 'secret',
            'api-key' => 'key-val',
            'auth-token' => 'bearer-xyz',
            'secret_key' => 'sk_live_xyz',
            'private_key' => 'priv_key_data',
            'encryption_key' => 'enc_key_data',
            'pin' => '9999',
            'userPin' => '1234',
        ];

        $redactor = new AuditRedactor;
        $sanitized = $redactor->redact($input);

        $this->assertEquals('[REDACTED]', $sanitized['apiKey']);
        $this->assertEquals('[REDACTED]', $sanitized['cardNumber']);
        $this->assertEquals('[REDACTED]', $sanitized['smsGatewayPassword']);
        $this->assertEquals('[REDACTED]', $sanitized['api-key']);
        $this->assertEquals('[REDACTED]', $sanitized['auth-token']);
        $this->assertEquals('[REDACTED]', $sanitized['secret_key']);
        $this->assertEquals('[REDACTED]', $sanitized['private_key']);
        $this->assertEquals('[REDACTED]', $sanitized['encryption_key']);
        $this->assertEquals('[REDACTED]', $sanitized['pin']);
        $this->assertEquals('[REDACTED]', $sanitized['userPin']);
    }

    public function test_enforces_recursion_depth_guard(): void
    {
        $deep = ['level' => 1];
        $current = &$deep;
        for ($i = 2; $i <= 25; $i++) {
            $current['nested'] = ['level' => $i];
            $current = &$current['nested'];
        }
        unset($current);

        $redactor = new AuditRedactor;
        $sanitized = $redactor->redact($deep, maxDepth: 5);

        $currentLevel = $sanitized;
        for ($i = 1; $i <= 5; $i++) {
            $this->assertEquals($i, $currentLevel['level']);
            $currentLevel = $currentLevel['nested'];
        }

        $this->assertEquals(['[MAX_DEPTH_REACHED]'], $currentLevel);
    }

    public function test_returns_null_when_input_is_null(): void
    {
        $redactor = new AuditRedactor;
        $this->assertNull($redactor->redact(null));
    }
}
