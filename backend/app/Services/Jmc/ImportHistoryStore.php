<?php

namespace App\Services\Jmc;

use Illuminate\Support\Facades\DB;
use Throwable;

class ImportHistoryStore
{
    private const CONNECTION = 'webjmr';

    private const TABLE = 'jmc_import_history';

    /**
     * @param  array<string, mixed>  $entry
     */
    public function append(array $entry): string
    {
        $id = bin2hex(random_bytes(16));
        $projects = $entry['projects'] ?? [];
        if (! is_array($projects)) {
            $projects = [];
        }

        $employee = $entry['employeeNumber'] ?? null;

        DB::connection(self::CONNECTION)->table(self::TABLE)->insert([
            'id' => $id,
            'imported_at' => $this->storedAt((string) ($entry['at'] ?? gmdate('c'))),
            'filename' => (string) ($entry['filename'] ?? ''),
            'group_name' => (string) ($entry['group'] ?? ''),
            'added' => (int) ($entry['added'] ?? 0),
            'skipped' => (int) ($entry['skipped'] ?? 0),
            'result' => (string) ($entry['result'] ?? 'Completed'),
            'imported_by' => (string) ($entry['importedBy'] ?? ''),
            'employee_number' => $employee === null || $employee === '' ? null : (int) $employee,
            'projects' => json_encode(array_values($projects), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'note' => (string) ($entry['note'] ?? ''),
        ]);

        return $id;
    }

    /**
     * @param  list<string>  $groups
     * @return list<array<string, mixed>>
     */
    public function listForGroups(array $groups): array
    {
        if ($groups === []) {
            return [];
        }

        $limit = (int) config('jmc.history_limit', 100);
        $rows = DB::connection(self::CONNECTION)
            ->table(self::TABLE)
            ->whereIn('group_name', array_values($groups))
            ->orderByDesc('imported_at')
            ->orderByDesc('id')
            ->limit($limit > 0 ? $limit : 100)
            ->get();

        $names = $this->namesByEmployee($rows);
        $summaries = [];
        foreach ($rows as $row) {
            $summaries[] = $this->summary($row, $names);
        }

        return $summaries;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        if (! preg_match('/^[a-f0-9]{32}$/', $id)) {
            return null;
        }

        $row = DB::connection(self::CONNECTION)
            ->table(self::TABLE)
            ->where('id', $id)
            ->first();

        return $row === null ? null : $this->detail($row, $this->namesByEmployee([$row]));
    }

    /**
     * @param  array<int, string>  $names
     * @return array<string, mixed>
     */
    private function summary(object $row, array $names): array
    {
        return [
            'id' => (string) $row->id,
            'at' => $this->iso((string) $row->imported_at),
            'filename' => (string) $row->filename,
            'group' => (string) $row->group_name,
            'added' => (int) $row->added,
            'skipped' => (int) $row->skipped,
            'result' => (string) $row->result,
            'importedBy' => $this->importedBy($row, $names),
        ];
    }

    /**
     * @param  array<int, string>  $names
     * @return array<string, mixed>
     */
    private function detail(object $row, array $names): array
    {
        $employee = $row->employee_number;

        return array_merge($this->summary($row, $names), [
            'employeeNumber' => $employee === null ? null : (int) $employee,
            'projects' => $this->projects($row->projects),
            'note' => (string) $row->note,
        ]);
    }

    /**
     * @param  iterable<int, object>  $rows
     * @return array<int, string>
     */
    private function namesByEmployee(iterable $rows): array
    {
        $numbers = [];
        foreach ($rows as $row) {
            if ($row->employee_number === null || $row->employee_number === '') {
                continue;
            }
            $numbers[] = (int) $row->employee_number;
        }

        $numbers = array_values(array_unique($numbers));
        if ($numbers === []) {
            return [];
        }

        try {
            $people = DB::connection('kdtph')
                ->table('emp_prof')
                ->whereIn('fldEmployeeNum', $numbers)
                ->orderByDesc('fldActive')
                ->get(['fldEmployeeNum', 'fldFirstname', 'fldSurname', 'fldName']);
        } catch (Throwable) {
            return [];
        }

        $names = [];
        foreach ($people as $person) {
            $number = (int) $person->fldEmployeeNum;
            if (! isset($names[$number])) {
                $names[$number] = $this->fullName($person);
            }
        }

        return $names;
    }

    private function fullName(object $person): string
    {
        $name = trim(trim((string) $person->fldFirstname).' '.trim((string) $person->fldSurname));
        if ($name === '') {
            $name = trim(str_replace('_', ' ', (string) $person->fldName));
        }

        return $name;
    }

    /**
     * @param  array<int, string>  $names
     */
    private function importedBy(object $row, array $names): string
    {
        $employee = $row->employee_number;
        $number = $employee === null || $employee === '' ? null : (int) $employee;
        $name = $number !== null ? trim((string) ($names[$number] ?? '')) : '';
        if ($name === '') {
            $name = trim((string) $row->imported_by);
        }
        if ($name === '') {
            return $number === null || $number <= 0 ? '' : (string) $number;
        }
        if ($number === null || $number <= 0) {
            return $name;
        }

        return $name.' ('.$number.')';
    }

    /**
     * @return list<mixed>
     */
    private function projects(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return is_array($value) ? array_values($value) : [];
    }

    private function storedAt(string $at): string
    {
        $timestamp = strtotime($at);

        return gmdate('Y-m-d H:i:s', $timestamp === false ? time() : $timestamp);
    }

    private function iso(string $stored): string
    {
        $timestamp = strtotime($stored.' UTC');
        if ($timestamp === false) {
            return $stored;
        }

        return gmdate('Y-m-d\TH:i:s+00:00', $timestamp);
    }
}
