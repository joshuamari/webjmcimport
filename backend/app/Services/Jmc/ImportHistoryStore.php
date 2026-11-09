<?php

namespace App\Services\Jmc;

class ImportHistoryStore
{
    /**
     * @param  array<string, mixed>  $entry
     */
    public function append(array $entry): string
    {
        $id = bin2hex(random_bytes(16));
        $entry['id'] = $id;
        $entry['at'] = $entry['at'] ?? gmdate('c');
        $encoded = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        file_put_contents($this->directory().DIRECTORY_SEPARATOR.$id.'.json', $encoded, LOCK_EX);

        return $id;
    }

    /**
     * @param  list<string>  $groups
     * @return list<array<string, mixed>>
     */
    public function listForGroups(array $groups): array
    {
        $rows = [];
        foreach (glob($this->directory().DIRECTORY_SEPARATOR.'*.json') ?: [] as $file) {
            $decoded = json_decode((string) file_get_contents($file), true);
            if (! is_array($decoded)) {
                continue;
            }
            if (! in_array((string) ($decoded['group'] ?? ''), $groups, true)) {
                continue;
            }
            $rows[] = $this->summary($decoded);
        }

        usort($rows, fn (array $a, array $b) => strcmp((string) $b['at'], (string) $a['at']));

        return array_slice($rows, 0, (int) config('jmc.history_limit', 100));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        if (! preg_match('/^[a-f0-9]{32}$/', $id)) {
            return null;
        }

        $path = $this->directory().DIRECTORY_SEPARATOR.$id.'.json';
        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function summary(array $entry): array
    {
        return [
            'id' => $entry['id'] ?? '',
            'at' => $entry['at'] ?? '',
            'filename' => $entry['filename'] ?? '',
            'group' => $entry['group'] ?? '',
            'added' => (int) ($entry['added'] ?? 0),
            'skipped' => (int) ($entry['skipped'] ?? 0),
            'result' => $entry['result'] ?? 'Completed',
            'importedBy' => $entry['importedBy'] ?? '',
        ];
    }

    private function directory(): string
    {
        $dir = storage_path('app/private/jmc-history');
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new ImportFailedException('The import was saved, but the history record could not be stored.');
        }

        return $dir;
    }
}
