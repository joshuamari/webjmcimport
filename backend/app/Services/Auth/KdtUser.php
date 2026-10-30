<?php

namespace App\Services\Auth;

class KdtUser
{
    /**
     * @param  list<string>  $groups
     */
    public function __construct(
        public readonly int $employeeNumber,
        public readonly string $username,
        public readonly array $groups,
        public readonly bool $canImport,
    ) {
    }

    /**
     * @return array{username: string, employeeNumber: int, groups: list<string>, canImport: bool}
     */
    public function toArray(): array
    {
        return [
            'username' => $this->username,
            'employeeNumber' => $this->employeeNumber,
            'groups' => $this->groups,
            'canImport' => $this->canImport,
        ];
    }
}
