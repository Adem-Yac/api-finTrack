<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Budget;
use App\Models\BudgetCategory;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class BudgetController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $year = (int) $request->integer('year', now()->year);
        $month = (int) $request->integer('month', now()->month);

        $budget = Budget::query()
            ->with('categories.category')
            ->where('user_id', $request->user()->id)
            ->where('year', $year)
            ->where('month', $month)
            ->first();

        return response()->json([
            'data' => $budget ? $this->payload($request, $budget) : null,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year' => ['required', 'integer', 'min:2020'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'total_amount' => ['required', 'numeric', 'min:0'],
            'categories' => ['nullable', 'array'],
            'categories.*.category_id' => ['required', 'integer', 'exists:categories,id'],
            'categories.*.allocated_amount' => ['required', 'numeric', 'min:0'],
        ]);

        $budget = Budget::query()->updateOrCreate(
            [
                'user_id' => $request->user()->id,
                'year' => $data['year'],
                'month' => $data['month'],
            ],
            ['total_amount' => $data['total_amount']]
        );

        if (isset($data['categories'])) {
            $budget->categories()->delete();
            foreach ($data['categories'] as $row) {
                $budget->categories()->create($row);
            }
        }

        return response()->json(['data' => $this->payload($request, $budget->fresh('categories.category'))], 201);
    }

    public function update(Request $request, Budget $budget): JsonResponse
    {
        abort_unless($budget->user_id === $request->user()->id, 403);

        $data = $request->validate([
            'total_amount' => ['sometimes', 'numeric', 'min:0'],
            'categories' => ['nullable', 'array'],
            'categories.*.category_id' => ['required', 'integer', 'exists:categories,id'],
            'categories.*.allocated_amount' => ['required', 'numeric', 'min:0'],
        ]);

        $budget->update($data);

        if (isset($data['categories'])) {
            $budget->categories()->delete();
            foreach ($data['categories'] as $row) {
                $budget->categories()->create($row);
            }
        }

        return response()->json(['data' => $this->payload($request, $budget->fresh('categories.category'))]);
    }

    public function destroy(Request $request, Budget $budget): JsonResponse
    {
        abort_unless($budget->user_id === $request->user()->id, 403);
        $budget->delete();

        return response()->json(['message' => 'Budget supprimé.']);
    }

    public function show(Request $request, Budget $budget): JsonResponse
    {
        abort_unless($budget->user_id === $request->user()->id, 403);

        return response()->json(['data' => $this->payload($request, $budget->load('categories.category'))]);
    }

    private function payload(Request $request, Budget $budget): array
    {
        $start = Carbon::create($budget->year, $budget->month, 1)->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $spentByCategory = Transaction::query()
            ->where('user_id', $request->user()->id)
            ->where('type', 'expense')
            ->whereBetween('occurred_on', [$start->toDateString(), $end->toDateString()])
            ->selectRaw('category_id, SUM(amount) as spent')
            ->groupBy('category_id')
            ->pluck('spent', 'category_id');

        $categories = $budget->categories->map(function (BudgetCategory $item) use ($spentByCategory) {
            $spent = (float) ($spentByCategory[$item->category_id] ?? 0);
            $allocated = (float) $item->allocated_amount;
            $percent = $allocated > 0 ? round(($spent / $allocated) * 100, 1) : 0;

            return [
                'id' => $item->id,
                'allocated_amount' => $allocated,
                'spent' => $spent,
                'remaining' => round($allocated - $spent, 2),
                'percent' => $percent,
                'category' => $item->category ? [
                    'id' => $item->category->id,
                    'slug' => $item->category->slug,
                    'name' => $item->category->name,
                    'icon' => $item->category->icon,
                    'color' => $item->category->color,
                ] : null,
            ];
        });

        $spent = (float) $spentByCategory->sum();
        $allocated = (float) $budget->total_amount;

        return [
            'id' => $budget->id,
            'year' => $budget->year,
            'month' => $budget->month,
            'total_amount' => $allocated,
            'spent' => $spent,
            'remaining' => round($allocated - $spent, 2),
            'percent' => $allocated > 0 ? round(($spent / $allocated) * 100, 1) : 0,
            'days_left' => max(0, now()->diffInDays($end, false)),
            'categories' => $categories,
        ];
    }
}
