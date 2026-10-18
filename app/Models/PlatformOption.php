<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PlatformOptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** @property array<string, mixed> $value */
class PlatformOption extends Model
{
    /** @use HasFactory<PlatformOptionFactory> */
    use HasFactory;

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }
}
