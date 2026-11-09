<?php

namespace App\Services\Jmc;

use RuntimeException;

class ImportRejectedException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $stale = false)
    {
        parent::__construct($message);
    }
}
