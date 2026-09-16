<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Goal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GoalController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $goals = $request->user()->goals()->with('deposits')->latest()->get();

        return response()->json([
            'data' => $goals->map(fn (Goal $goal) => $this->payload($goal)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'emoji' => ['nullable', 'string', 'max:8'],
            'target_amount' => ['required', 'numeric', 'min:1'],
            'current_amount' => ['sometimes', 'numeric', 'min:0'],
            'deadline' => ['nullable', 'date'],
        ]);
        $data['user_id'] = $request->user()->id;
        $goal = Goal::query()->create($data);

        return response()->json(['data' => $this->payload($goal)], 201);
    }

    public function show(Request $request, Goal $goal): JsonResponse
    {
        abort_unless($goal->user_id === $request->user()->id, 403);

        return response()->json(['data' => $this->payload($goal->load('deposits'))]);
    }

    public function update(Request $request, Goal $goal): JsonResponse
    {
        abort_unless($goal->user_id === $request->user()->id, 403);
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:160'],
            'emoji' => ['nullable', 'string', 'max:8'],
            'target_amount' => ['sometimes', 'numeric', 'min:1'],
            'deadline' => ['nullable', 'date'],
        ]);
        $goal->update($data);

        return response()->json(['data' => $this->payload($goal->fresh('deposits'))]);
    }

    public function destroy(Request $request, Goal $goal): JsonResponse
    {
        abort_unless($goal->user_id === $request->user()->id, 403);
        $goal->delete();

        return response()->json(['message' => 'Objectif supprimé.']);
    }

    public function deposit(Request $request, Goal $goal): JsonResponse
    {
        abort_unless($goal->user_id === $request->user()->id, 403);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'deposited_on' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $deposit = $goal->deposits()->create([
            'amount' => $data['amount'],
            'deposited_on' => $data['deposited_on'] ?? now()->toDateString(),
            'note' => $data['note'] ?? null,
        ]);
        $goal->increment('current_amount', $data['amount']);

        return response()->json([
            'data' => [
                'deposit' => $deposit,
                'goal' => $this->payload($goal->fresh('deposits')),
            ],
        ], 201);
    }

    private function payload(Goal $goal): array
    {
        $target = (float) $goal->target_amount;
        $current = (float) $goal->current_amount;

        return [
            'id' => $goal->id,
            'title' => $goal->title,
            'emoji' => $goal->emoji,
            'target_amount' => $target,
            'current_amount' => $current,
            'remaining' => round($target - $current, 2),
            'percent' => $target > 0 ? round(($current / $target) * 100, 1) : 0,
            'deadline' => $goal->deadline?->toDateString(),
            'deposits' => $goal->relationLoaded('deposits')
                ? $goal->deposits->map(fn ($deposit) => [
                    'id' => $deposit->id,
                    'amount' => (float) $deposit->amount,
                    'deposited_on' => $deposit->deposited_on?->toDateString(),
                    'note' => $deposit->note,
                ])
                : [],
        ];
    }
}
