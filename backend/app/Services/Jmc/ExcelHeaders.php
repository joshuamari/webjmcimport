<?php

namespace App\Services\Jmc;

class ExcelHeaders
{
    public const PROJECT = 'Project';
    public const GROUP = 'Group';

    public const ORDER_NUMBER = 'Order No. (7 + 4 digits)';
    public const BUDGET_IN_CHARGE = 'Budget in Charge of Order No.';
    public const ITEM_OF_WORKS = 'Item of Works (Dwg. No.)';
    public const NUMBER_OF_SHEET = 'No. of Sheet';
    public const PAPER_SIZE = 'Paper Size';
    public const DOCUMENT_NAME = 'Drawing / Document Name';
    public const DESCRIPTION = 'Job Request Description';
    public const KHI_REQUESTED_DATE = 'KHI Requested Date';
    public const KHI_IN_CHARGE = 'KHI In charge';
    public const KHI_DEADLINE = 'KHI Deadline';
    public const KDT_DEADLINE = 'KDT Deadline';
    public const EXPECTED_MANHOUR = 'Expected Manhour';
    public const KHI_PREP = 'KHI Preparing Manhour (Hr)';

    /**
     * @return list<string>
     */
    public static function baseList(): array
    {
        return [
            self::ORDER_NUMBER,
            self::BUDGET_IN_CHARGE,
            self::ITEM_OF_WORKS,
            self::NUMBER_OF_SHEET,
            self::PAPER_SIZE,
            self::DOCUMENT_NAME,
            self::DESCRIPTION,
            self::KHI_REQUESTED_DATE,
            self::KHI_IN_CHARGE,
            self::KHI_DEADLINE,
            self::KDT_DEADLINE,
            self::EXPECTED_MANHOUR,
        ];
    }

    /**
     * @return list<string>
     */
    public static function ignoreSheetNames(): array
    {
        return [
            '予算所掌部門一覧',
            'Reference',
            'MATERIAL LIST',
            'KAIZEN ACTIVITY',
            'TRAINING AND DEVELOPMENT',
            'GENERAL OFFICE WORK',
            'OTHERS',
            'LEAVE',
            'MANAGEMENT',
            'KDT INTERNAL ACTIVITIES',
            'TRAINING',
            'DEVELOPMENT, ANALYSIS & IT',
            'BUSINESS TRIP & OTHER',
        ];
    }

    /**
     * @return list<string>
     */
    public static function projectDefaults(): array
    {
        return [
            'KAIZEN ACTIVITY',
            'KDT Works',
            'TRAINING AND DEVELOPMENT',
            'GENERAL OFFICE WORK',
            '1',
            '2',
            '3',
        ];
    }

    public static function isIgnoredSheet(string $sheetName): bool
    {
        return in_array(mb_strtoupper($sheetName), array_map('mb_strtoupper', self::ignoreSheetNames()), true);
    }

    public static function isProjectDefault(string $project): bool
    {
        return in_array($project, self::projectDefaults(), true);
    }

    public static function hasSpecialCharacter(string $input): bool
    {
        $special = '\\|!$%=*?»«@£§€{}\'<>';
        $len = mb_strlen($special);
        for ($i = 0; $i < $len; $i++) {
            if (str_contains($input, mb_substr($special, $i, 1))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $headers
     */
    public static function isValidHeaderRow(array $headers): bool
    {
        foreach (self::baseList() as $required) {
            if (! in_array($required, $headers, true)) {
                return false;
            }
        }

        return true;
    }
}
