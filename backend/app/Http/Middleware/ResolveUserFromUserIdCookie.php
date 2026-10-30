<?php

namespace App\Http\Middleware;

use App\Services\Auth\KdtUserResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveUserFromUserIdCookie
{
    public function __construct(private readonly KdtUserResolver $resolver)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $hash = $request->cookie('userID');

        if ($hash === null || $hash === '') {
            return response()->json([
                'message' => 'Not signed in. Missing userID cookie.',
            ], 401);
        }

        $user = $this->resolver->resolveFromUserHash((string) $hash);

        if ($user === null) {
            return response()->json([
                'message' => 'Not signed in. Invalid userID cookie.',
            ], 401);
        }

        $request->attributes->set('kdtUser', $user);

        return $next($request);
    }
}
