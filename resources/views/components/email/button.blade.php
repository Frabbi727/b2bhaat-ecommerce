@props(['url', 'color' => 'primary', 'align' => 'center'])

@php
    $bg = match($color) {
        'secondary' => '#0F172A',
        'success' => '#10B981',
        'warning' => '#F59E0B',
        default => '#0D9488', // Emerald/Teal brand
    };
@endphp

<table width="100%" border="0" cellspacing="0" cellpadding="0" style="margin: 24px 0;">
    <tr>
        <td align="{{ $align }}">
            <a href="{{ $url }}" target="_blank" style="display: inline-block; background-color: {{ $bg }}; color: #FFFFFF; font-weight: 600; font-size: 14px; text-decoration: none; padding: 12px 24px; border-radius: 6px; text-align: center;">
                {{ $slot }}
            </a>
        </td>
    </tr>
</table>
