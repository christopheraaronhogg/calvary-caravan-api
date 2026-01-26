<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SendMessageRequest;
use App\Models\RetreatMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RetreatMessageController extends Controller
{
    public function send(SendMessageRequest $request): JsonResponse
    {
        $participant = $request->attributes->get('participant');
        $retreat = $request->attributes->get('retreat');

        $messageType = $request->validated('message_type') ?? 'chat';

        // Only leaders can send alerts
        if ($messageType === 'alert' && !$participant->is_leader) {
            return response()->json(['error' => 'Only leaders can send alerts'], 403);
        }

        $message = RetreatMessage::create([
            'retreat_id' => $retreat->id,
            'participant_id' => $participant->id,
            'message_type' => $messageType,
            'content' => $request->validated('content'),
            'latitude' => $request->validated('latitude'),
            'longitude' => $request->validated('longitude'),
            'created_at' => now(),
        ]);

        return response()->json([
            'data' => [
                'id' => $message->id,
                'message_type' => $message->message_type,
                'content' => $message->content,
                'sender' => $participant->name,
                'created_at' => $message->created_at->toIso8601String(),
            ],
        ], 201);
    }

    public function list(Request $request): JsonResponse
    {
        $retreat = $request->attributes->get('retreat');
        $sinceId = $request->query('since_id');
        $limit = min((int) ($request->query('limit') ?? 50), 100);

        $query = $retreat->messages()
            ->with('participant')
            ->orderBy('created_at', 'desc')
            ->limit($limit);

        if ($sinceId) {
            $query->where('id', '>', $sinceId);
        }

        $messages = $query->get()->map(function ($message) {
            return [
                'id' => $message->id,
                'message_type' => $message->message_type,
                'content' => $message->content,
                'sender' => [
                    'id' => $message->participant->id,
                    'name' => $message->participant->name,
                    'is_leader' => (bool) $message->participant->is_leader,
                    'gender' => $message->participant->gender ?? null,
                ],
                'location' => $message->latitude ? [
                    'lat' => (float) $message->latitude,
                    'lng' => (float) $message->longitude,
                ] : null,
                'created_at' => $message->created_at->toIso8601String(),
            ];
        })->reverse()->values();

        $latestId = $messages->last()['id'] ?? null;

        return response()->json([
            'data' => $messages,
            'meta' => [
                'latest_id' => $latestId,
                'count' => $messages->count(),
            ],
        ]);
    }
}
