<?php

declare(strict_types=1);

namespace App\Services\Audit\Support;

class UserAgentParser
{
    /**
     * @return array{device_type: string, browser: string, os: string}
     */
    public static function parse(?string $ua): array
    {
        if (blank($ua)) {
            return ['device_type' => 'unknown', 'browser' => 'Unknown', 'os' => 'Unknown'];
        }

        $ua = substr($ua, 0, 500);

        $deviceType = 'desktop';
        if (preg_match('/(ipad|tablet|(android(?!.*mobile))|(windows(?!.*phone)(.*touch))|kindle|playbook|silk)/i', $ua)) {
            $deviceType = 'tablet';
        } elseif (preg_match('/(mobi|iphone|ipod|phone|blackberry|opera mini|fennec|minimo)/i', $ua)) {
            $deviceType = 'mobile';
        }

        $os = 'Unknown';
        if (preg_match('/windows nt/i', $ua)) {
            $os = 'Windows';
        } elseif (preg_match('/iphone|ipad|ipod/i', $ua)) {
            $os = 'iOS';
        } elseif (preg_match('/macintosh|mac os x/i', $ua)) {
            $os = 'macOS';
        } elseif (preg_match('/android/i', $ua)) {
            $os = 'Android';
        } elseif (preg_match('/linux/i', $ua)) {
            $os = 'Linux';
        }

        $browser = 'Unknown';
        if (preg_match('/edg\//i', $ua)) {
            $browser = 'Edge';
        } elseif (preg_match('/opr|opera/i', $ua)) {
            $browser = 'Opera';
        } elseif (preg_match('/chrome|crios/i', $ua)) {
            $browser = 'Chrome';
        } elseif (preg_match('/firefox|fxios/i', $ua)) {
            $browser = 'Firefox';
        } elseif (preg_match('/safari/i', $ua)) {
            $browser = 'Safari';
        }

        return [
            'device_type' => $deviceType,
            'browser' => $browser,
            'os' => $os,
        ];
    }
}
