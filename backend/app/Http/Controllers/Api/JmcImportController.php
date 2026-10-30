<?php

namespace App\Http\Controllers\Api;

use App\Services\Auth\KdtUser;
use App\Services\Jmc\JmcImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

class JmcImportController
{
    public function store(Request $request, JmcImportService $importer): JsonResponse
    {
        /** @var KdtUser $user */
        $user = $request->attributes->get('kdtUser');

        if (! $user->canImport) {
            return response()->json([
                'message' => 'No access in Web JMC Import',
            ], 403);
        }

        $validated = $request->validate([
            'group' => ['required', 'string'],
            'file' => ['required', 'file', 'mimes:xlsx'],
        ]);

        $group = $validated['group'];
        if (! in_array($group, $user->groups, true)) {
            return response()->json([
                'message' => 'Selected group is not allowed for this user.',
            ], 403);
        }

        $path = $request->file('file')?->getRealPath();
        if ($path === false || $path === null) {
            return response()->json([
                'message' => 'Unable to read uploaded Excel file.',
            ], 422);
        }

        try {
            $result = $importer->import($path, $group);

            return response()->json([
                'data' => $result,
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'data' => [
                    'completedProjects' => [],
                    'logs' => [$e->getMessage()],
                ],
            ], 422);
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Import failed.',
                'data' => [
                    'completedProjects' => [],
                    'logs' => [$e->getMessage()],
                ],
            ], 500);
        }
    }
}
