<?php

namespace App\Services\Jmc;

use RuntimeException;

class ImportConflictException extends RuntimeException
{
    /**
     * @param  array<string, mixed>|null  $committed
     */
    public function __construct(string $message, public readonly ?array $committed = null)
    {
        parent::__construct($message);
    }
}
