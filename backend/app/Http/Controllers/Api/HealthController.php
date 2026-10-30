<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController
{
    public function __invoke(): JsonResponse
    {
        $kdtph = $this->ping('kdtph');
        $webjmr = $this->ping('webjmr');

        $ok = $kdtph['ok'] && $webjmr['ok'];

        return response()->json([
            'status' => $ok ? 'ok' : 'degraded',
            'databases' => [
                'kdtph' => $kdtph,
                'webjmr' => $webjmr,
            ],
        ], $ok ? 200 : 503);
    }

    /**
     * @return array{ok: bool, error: string|null}
     */
    private function ping(string $connection): array
    {
        try {
            DB::connection($connection)->select('SELECT 1');

            return ['ok' => true, 'error' => null];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
