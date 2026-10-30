<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\DB;
use Throwable;

class KdtUserResolver
{
    private const JMC_IMPORT_PERMISSION_ID = 2;

    public function resolveFromUserHash(string $userHash): ?KdtUser
    {
        try {
            $login = DB::connection('kdtph')
                ->table('kdtlogin')
                ->where('fldUserHash', $userHash)
                ->first(['fldEmployeeNum', 'fldUserHash']);
        } catch (Throwable) {
            return null;
        }

        if ($login === null || $login->fldEmployeeNum === null) {
            return null;
        }

        $employeeNumber = (int) $login->fldEmployeeNum;

        try {
            $emp = DB::connection('kdtph')
                ->table('emp_prof')
                ->where('fldEmployeeNum', $employeeNumber)
                ->where('fldActive', '1')
                ->first(['fldEmployeeNum', 'fldUser', 'fldGroup', 'fldGroups']);
        } catch (Throwable) {
            return null;
        }

        if ($emp === null) {
            return null;
        }

        $groups = $this->parseGroups(
            (string) ($emp->fldGroups ?? ''),
            (string) ($emp->fldGroup ?? '')
        );

        $canImport = $this->hasJmcImport($employeeNumber);

        return new KdtUser(
            employeeNumber: $employeeNumber,
            username: (string) ($emp->fldUser ?? ''),
            groups: $groups,
            canImport: $canImport,
        );
    }

    private function hasJmcImport(int $employeeNumber): bool
    {
        try {
            return DB::connection('kdtph')
                ->table('user_permissions')
                ->where('fldEmployeeNum', $employeeNumber)
                ->where('permission_id', self::JMC_IMPORT_PERMISSION_ID)
                ->exists();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return list<string>
     */
    private function parseGroups(string $groupsField, string $groupField): array
    {
        if ($groupsField !== '') {
            return array_values(array_filter(array_map('trim', explode('/', $groupsField))));
        }

        $group = trim($groupField);

        return $group === '' ? [] : [$group];
    }
}
