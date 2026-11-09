<?php

namespace App\Services\Jmc;

class JmcPreviewQuery
{
    /**
     * @param  array<string, mixed>  $preview
     * @return array<string, mixed>
     */
    public function page(array $preview, string $filter, string $search, int $page, int $perPage): array
    {
        $filter = in_array($filter, ['all', 'new', 'exists', 'error'], true) ? $filter : 'all';
        $perPage = min(100, max(1, $perPage));
        $page = max(1, $page);
        $needle = mb_strtolower(trim($search));

        $rows = $preview['rows'] ?? [];
        $matched = [];
        foreach ($rows as $row) {
            if ($filter !== 'all' && ($row['result'] ?? '') !== $filter) {
                continue;
            }
            if ($needle !== '') {
                $haystack = mb_strtolower(
                    ($row['project'] ?? '').' '.($row['itemOfWorks'] ?? '').' '.($row['jobRequest'] ?? '')
                );
                if (! str_contains($haystack, $needle)) {
                    continue;
                }
            }
            $matched[] = $row;
        }

        $total = count($matched);
        $offset = ($page - 1) * $perPage;
        if ($offset >= $total && $total > 0) {
            $page = (int) ceil($total / $perPage);
            $offset = ($page - 1) * $perPage;
        }

        return [
            'token' => $preview['token'],
            'filename' => $preview['filename'],
            'group' => $preview['group'],
            'summary' => $preview['summary'],
            'highlightedIssues' => $preview['highlightedIssues'],
            'issueTotal' => $preview['issueTotal'],
            'rows' => array_slice($matched, $offset, $perPage),
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'filter' => $filter,
            'q' => trim($search),
            'operationStatus' => (string) ($preview['status'] ?? 'ready'),
            'committed' => is_array($preview['committed'] ?? null) ? $preview['committed'] : null,
        ];
    }
}
