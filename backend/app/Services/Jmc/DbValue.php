<?php

namespace App\Services\Jmc;

use Carbon\Carbon;
use Throwable;

class DbValue
{
    public static function nullableString(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    public static function nullableInt(?string $value): ?int
    {
        $trimmed = trim((string) $value);
        if ($trimmed === '' || ! is_numeric($trimmed)) {
            return null;
        }

        return (int) $trimmed;
    }

    public static function nullableDate(?string $value): ?string
    {
        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return null;
        }

        try {
            return Carbon::parse($trimmed)->format('Y-m-d');
        } catch (Throwable) {
            return null;
        }
    }
}
