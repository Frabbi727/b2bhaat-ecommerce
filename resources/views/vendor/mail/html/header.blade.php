@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
<img src="{{ asset('images/brand/logo.png') }}" alt="{{ trim(strip_tags($slot)) ?: 'B2B Haat' }}" width="140" height="78" style="width: 140px; height: auto; max-height: none; border: 0;">
</a>
</td>
</tr>
