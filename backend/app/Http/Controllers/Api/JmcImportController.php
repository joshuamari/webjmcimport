<?php

namespace App\Http\Controllers\Api;

use App\Services\Auth\KdtUser;
use App\Services\Jmc\ImportConflictException;
use App\Services\Jmc\ImportFailedException;
use App\Services\Jmc\ImportRejectedException;
use App\Services\Jmc\JmcErrorReportExporter;
use App\Services\Jmc\JmcImportService;
use App\Services\Jmc\JmcTemplateExporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class JmcImportController
{
    public function template(Request $request, JmcTemplateExporter $exporter): Response
    {
        $this->user($request);

        return $this->xlsx($exporter->binary(), 'JMC-Import-Template.xlsx');
    }

    public function preview(Request $request, JmcImportService $importer): JsonResponse
    {
        $user = $this->user($request);
        $validated = $request->validate([
            'group' => ['required', 'string'],
            'file' => ['required', 'file', 'mimes:xlsx', 'max:'.(int) config('jmc.max_upload_kb', 10240)],
        ], [
            'file.mimes' => 'This file format is not supported. Please upload an Excel (.xlsx) file.',
            'file.max' => 'This file is larger than the allowed upload size.',
        ]);

        $group = $this->authorizedGroup($user, $validated['group']);
        $path = $request->file('file')?->getRealPath();
        if ($path === false || $path === null) {
            return response()->json([
                'message' => 'Unable to read the uploaded Excel file.',
            ], 422);
        }

        try {
            return response()->json([
                'data' => $importer->preview($path, (string) $request->file('file')?->getClientOriginalName(), $group, $user),
            ]);
        } catch (ImportFailedException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'The file could not be checked. Save it as an .xlsx file and try again.',
            ], 422);
        }
    }

    public function show(Request $request, JmcImportService $importer, string $token): JsonResponse
    {
        $user = $this->user($request);

        try {
            return response()->json([
                'data' => $importer->previewPage(
                    $token,
                    $user,
                    (string) $request->query('filter', 'all'),
                    (string) $request->query('q', ''),
                    (int) $request->query('page', 1),
                    (int) $request->query('perPage', 20),
                ),
            ]);
        } catch (ImportRejectedException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }
    }

    public function errors(Request $request, JmcImportService $importer, JmcErrorReportExporter $exporter, string $token): Response
    {
        $user = $this->user($request);

        try {
            $report = $importer->errorReport($token, $user, $exporter);
        } catch (ImportRejectedException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        return $this->xlsx($report['binary'], $report['filename']);
    }

    public function confirm(Request $request, JmcImportService $importer, string $token): JsonResponse
    {
        $user = $this->user($request);

        try {
            return response()->json([
                'data' => $importer->confirm($token, $user),
            ]);
        } catch (ImportConflictException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'data' => $e->committed,
            ], 409);
        } catch (ImportRejectedException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'data' => ['stale' => $e->stale],
            ], 422);
        } catch (ImportFailedException $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function history(Request $request, JmcImportService $importer): JsonResponse
    {
        $user = $this->user($request);
        $group = trim((string) $request->query('group', ''));
        $groups = $user->groups;
        if ($group !== '') {
            $groups = [$this->authorizedGroup($user, $group)];
        }

        return response()->json([
            'data' => $importer->history($groups),
        ]);
    }

    public function historyDetail(Request $request, JmcImportService $importer, string $id): JsonResponse
    {
        $user = $this->user($request);

        try {
            return response()->json([
                'data' => $importer->historyDetail($id, $user),
            ]);
        } catch (ImportRejectedException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }
    }

    public function store(Request $request, JmcImportService $importer): JsonResponse
    {
        $user = $this->user($request);
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

    private function user(Request $request): KdtUser
    {
        /** @var KdtUser $user */
        $user = $request->attributes->get('kdtUser');

        if (! $user->canImport) {
            abort(response()->json([
                'message' => 'No access in Web JMC Import',
            ], 403));
        }

        return $user;
    }

    private function authorizedGroup(KdtUser $user, string $group): string
    {
        if (! in_array($group, $user->groups, true)) {
            abort(response()->json([
                'message' => 'Selected group is not allowed for this user.',
            ], 403));
        }

        return $group;
    }

    private function xlsx(string $binary, string $filename): Response
    {
        return response($binary, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store',
        ]);
    }
}
