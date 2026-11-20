<?php

declare(strict_types=1);

namespace Tests\Unit\Audit;

use App\Services\Audit\Support\UserAgentParser;
use Tests\TestCase;

class UserAgentParserTest extends TestCase
{
    public function test_detects_macos_and_chrome(): void
    {
        $ua = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
        $parsed = UserAgentParser::parse($ua);

        $this->assertEquals('desktop', $parsed['device_type']);
        $this->assertEquals('Chrome', $parsed['browser']);
        $this->assertEquals('macOS', $parsed['os']);
    }

    public function test_detects_android_mobile(): void
    {
        $ua = 'Mozilla/5.0 (Linux; Android 13; SM-S901B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/112.0.0.0 Mobile Safari/537.36';
        $parsed = UserAgentParser::parse($ua);

        $this->assertEquals('mobile', $parsed['device_type']);
        $this->assertEquals('Chrome', $parsed['browser']);
        $this->assertEquals('Android', $parsed['os']);
    }

    public function test_detects_ios_tablet(): void
    {
        $ua = 'Mozilla/5.0 (iPad; CPU OS 16_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.5 Mobile/15E148 Safari/604.1';
        $parsed = UserAgentParser::parse($ua);

        $this->assertEquals('tablet', $parsed['device_type']);
        $this->assertEquals('Safari', $parsed['browser']);
        $this->assertEquals('iOS', $parsed['os']);
    }

    public function test_handles_null_or_empty_user_agent(): void
    {
        $parsed = UserAgentParser::parse(null);
        $this->assertEquals('unknown', $parsed['device_type']);
        $this->assertEquals('Unknown', $parsed['browser']);
        $this->assertEquals('Unknown', $parsed['os']);

        $empty = UserAgentParser::parse('   ');
        $this->assertEquals('unknown', $empty['device_type']);
        $this->assertEquals('Unknown', $empty['browser']);
        $this->assertEquals('Unknown', $empty['os']);
    }

    public function test_detects_windows_and_edge(): void
    {
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Edg/120.0.0.0';
        $parsed = UserAgentParser::parse($ua);

        $this->assertEquals('desktop', $parsed['device_type']);
        $this->assertEquals('Edge', $parsed['browser']);
        $this->assertEquals('Windows', $parsed['os']);
    }

    public function test_detects_linux_and_firefox(): void
    {
        $ua = 'Mozilla/5.0 (X11; Linux x86_64; rv:109.0) Gecko/20100101 Firefox/119.0';
        $parsed = UserAgentParser::parse($ua);

        $this->assertEquals('desktop', $parsed['device_type']);
        $this->assertEquals('Firefox', $parsed['browser']);
        $this->assertEquals('Linux', $parsed['os']);
    }

    public function test_detects_opera(): void
    {
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Safari/537.36 OPR/105.0.0.0';
        $parsed = UserAgentParser::parse($ua);

        $this->assertEquals('desktop', $parsed['device_type']);
        $this->assertEquals('Opera', $parsed['browser']);
        $this->assertEquals('Windows', $parsed['os']);
    }

    public function test_detects_android_tablet(): void
    {
        $ua = 'Mozilla/5.0 (Linux; Android 12; SM-T870) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/112.0.0.0 Safari/537.36';
        $parsed = UserAgentParser::parse($ua);

        $this->assertEquals('tablet', $parsed['device_type']);
        $this->assertEquals('Chrome', $parsed['browser']);
        $this->assertEquals('Android', $parsed['os']);
    }
}
