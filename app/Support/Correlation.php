<?php

namespace App\Support;

use Illuminate\Support\Str;

final class Correlation
{
    private static ?string $id = null;

    public static function id(): string
    {
        return self::$id ??= (string) Str::ulid();
    }

    public static function set(?string $id): void
    {
        self::$id = $id && preg_match('/^[A-Za-z0-9_-]{8,64}$/', $id) ? $id : null;
    }
}
