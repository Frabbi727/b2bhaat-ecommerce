<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmailTemplate extends Model
{
    use Auditable;
    use HasFactory;

    protected $fillable = [
        'key',
        'name',
        'subject_en',
        'subject_bn',
        'content_en',
        'content_bn',
        'placeholders',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'placeholders' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
