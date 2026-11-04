<?php

declare(strict_types=1);

namespace Tests\Unit\Sms;

use App\Services\Sms\Support\PhoneNormalizer;
use InvalidArgumentException;
use Tests\TestCase;

class PhoneNormalizerTest extends TestCase
{
    public function test_normalizes_standard_bangladeshi_numbers(): void
    {
        $this->assertSame('8801712345678', PhoneNormalizer::normalize('01712345678'));
        $this->assertSame('8801712345678', PhoneNormalizer::normalize('+8801712345678'));
        $this->assertSame('8801712345678', PhoneNormalizer::normalize('8801712345678'));
        $this->assertSame('8801912345678', PhoneNormalizer::normalize('019 1234 5678'));
        $this->assertSame('8801812345678', PhoneNormalizer::normalize('018-1234-5678'));
    }

    public function test_is_valid_helper_correctly_validates(): void
    {
        $this->assertTrue(PhoneNormalizer::isValid('01712345678'));
        $this->assertTrue(PhoneNormalizer::isValid('+8801812345678'));
        $this->assertFalse(PhoneNormalizer::isValid('12345'));
        $this->assertFalse(PhoneNormalizer::isValid('01212345678')); // Invalid prefix
    }

    public function test_throws_exception_on_invalid_number(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PhoneNormalizer::normalize('12345');
    }
}
