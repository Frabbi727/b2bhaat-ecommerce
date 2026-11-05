<?php

declare(strict_types=1);

namespace App\Services\Logistics;

/**
 * The 64 districts of Bangladesh (official spellings), common older spellings, and which delivery areas each district
 * may use. Checkout offers these as a list and saves the official name.
 */
class BangladeshDistricts
{
    /** @var list<string> */
    public const ALL = [
        // Dhaka division
        'Dhaka', 'Faridpur', 'Gazipur', 'Gopalganj', 'Kishoreganj', 'Madaripur', 'Manikganj', 'Munshiganj',
        'Narayanganj', 'Narsingdi', 'Rajbari', 'Shariatpur', 'Tangail',
        // Chattogram division
        'Bandarban', 'Brahmanbaria', 'Chandpur', 'Chattogram', "Cox's Bazar", 'Cumilla', 'Feni', 'Khagrachhari',
        'Lakshmipur', 'Noakhali', 'Rangamati',
        // Rajshahi division
        'Bogura', 'Chapai Nawabganj', 'Joypurhat', 'Naogaon', 'Natore', 'Pabna', 'Rajshahi', 'Sirajganj',
        // Khulna division
        'Bagerhat', 'Chuadanga', 'Jashore', 'Jhenaidah', 'Khulna', 'Kushtia', 'Magura', 'Meherpur', 'Narail', 'Satkhira',
        // Barishal division
        'Barguna', 'Barishal', 'Bhola', 'Jhalokati', 'Patuakhali', 'Pirojpur',
        // Sylhet division
        'Habiganj', 'Moulvibazar', 'Sunamganj', 'Sylhet',
        // Rangpur division
        'Dinajpur', 'Gaibandha', 'Kurigram', 'Lalmonirhat', 'Nilphamari', 'Panchagarh', 'Rangpur', 'Thakurgaon',
        // Mymensingh division
        'Jamalpur', 'Mymensingh', 'Netrokona', 'Sherpur',
    ];

    /** @var array<string, string> lower-case older spelling => official name */
    public const ALIASES = [
        'chittagong' => 'Chattogram',
        'comilla' => 'Cumilla',
        'barisal' => 'Barishal',
        'jessore' => 'Jashore',
        'bogra' => 'Bogura',
        'nawabganj' => 'Chapai Nawabganj',
        'chapainawabganj' => 'Chapai Nawabganj',
        'coxs bazar' => "Cox's Bazar",
        'coxsbazar' => "Cox's Bazar",
        'jhalakati' => 'Jhalokati',
        'moulvi bazar' => 'Moulvibazar',
        'maulvibazar' => 'Moulvibazar',
        'netrakona' => 'Netrokona',
        'khagrachari' => 'Khagrachhari',
        'laxmipur' => 'Lakshmipur',
    ];

    /** @var array<string, list<string>> delivery area => districts allowed to use it (outside Dhaka: any) */
    public const ZONE_DISTRICTS = [
        'inside_dhaka' => ['Dhaka'],
        'sub_dhaka' => ['Dhaka', 'Gazipur', 'Narayanganj'],
    ];

    /**
     * The official district name for what the buyer entered, or null when it is not a Bangladeshi district.
     */
    public static function normalize(?string $district): ?string
    {
        $key = strtolower(trim((string) $district));
        if ($key === '') {
            return null;
        }

        foreach (self::ALL as $official) {
            if (strtolower($official) === $key) {
                return $official;
            }
        }

        return self::ALIASES[$key] ?? self::ALIASES[str_replace("'", '', $key)] ?? null;
    }

    public static function allowsZone(string $district, string $zone): bool
    {
        return ! isset(self::ZONE_DISTRICTS[$zone]) || in_array($district, self::ZONE_DISTRICTS[$zone], true);
    }

    /**
     * The delivery area to pre-select for a district.
     */
    public static function defaultZoneFor(string $district): string
    {
        return match (true) {
            $district === 'Dhaka' => 'inside_dhaka',
            in_array($district, self::ZONE_DISTRICTS['sub_dhaka'], true) => 'sub_dhaka',
            default => 'outside_dhaka',
        };
    }
}
