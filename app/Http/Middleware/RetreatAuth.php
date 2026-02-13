<?php

namespace App\Http\Middleware;

use App\Models\RetreatParticipant;
use App\Services\RetreatIdentityService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RetreatAuth
{
    public function __construct(private readonly RetreatIdentityService $identityService) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->header('X-Device-Token');

        if (! $token) {
            return response()->json(['error' => 'Device token required'], 401);
        }

        $participant = RetreatParticipant::where('device_token', $token)
            ->whereHas('retreat', function ($query) {
                $query->where('is_active', true)
                    ->where('ends_at', '>=', now());
            })
            ->first();

        if (! $participant) {
            return response()->json(['error' => 'Invalid or expired session'], 401);
        }

        $this->identityService->syncLeaderRole($participant);

        // Update last_seen timestamp
        $participant->update(['last_seen_at' => now()]);
        $participant->refresh();

        // Bind participant and retreat to request for controller access
        $request->attributes->set('participant', $participant);
        $request->attributes->set('retreat', $participant->retreat);

        return $next($request);
    }
}
