<?php

declare(strict_types=1);

namespace Tests\Unit\Audit;

use App\Services\Audit\AuditRedactor;
use Tests\TestCase;

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

    public function test_returns_null_when_input_is_null(): void
    {
        $redactor = new AuditRedactor;
        $this->assertNull($redactor->redact(null));
    }
}
