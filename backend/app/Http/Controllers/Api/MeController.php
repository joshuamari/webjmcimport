<?php

namespace App\Http\Controllers\Api;

use App\Services\Auth\KdtUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var KdtUser $user */
        $user = $request->attributes->get('kdtUser');

        if (! $user->canImport) {
            return response()->json([
                'message' => 'No access in Web JMC Import',
                'data' => $user->toArray(),
            ], 403);
        }

        return response()->json([
            'data' => $user->toArray(),
        ]);
    }
}
