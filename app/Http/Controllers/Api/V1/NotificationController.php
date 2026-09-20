<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $items = $request->user()->notifications()->latest()->get();

        return response()->json([
            'data' => $items->map(fn (AppNotification $item) => $this->payload($item)),
            'meta' => [
                'unread_count' => $items->whereNull('read_at')->count(),
            ],
        ]);
    }

    public function markRead(Request $request, AppNotification $notification): JsonResponse
    {
        $this->authorize('update', $notification);
        $notification->forceFill(['read_at' => now()])->save();

        return response()->json(['data' => $this->payload($notification->fresh())]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->notifications()->whereNull('read_at')->update(['read_at' => now()]);

        return $this->index($request);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(AppNotification $notification): array
    {
        return [
            'id' => $notification->id,
            'title' => $notification->title,
            'body' => $notification->body,
            'type' => $notification->type,
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }
}
