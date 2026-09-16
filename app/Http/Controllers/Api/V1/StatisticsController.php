<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class StatisticsController extends Controller
{
    public function monthly(Request $request): JsonResponse
    {
        $month = $request->string('month', now()->format('Y-m'))->toString();
        $start = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $previousStart = $start->copy()->subMonth()->startOfMonth();
        $previousEnd = $start->copy()->subMonth()->endOfMonth();

        $userId = $request->user()->id;
        $income = $this->sum($userId, 'income', $start, $end);
        $expenses = $this->sum($userId, 'expense', $start, $end);
        $previousExpenses = $this->sum($userId, 'expense', $previousStart, $previousEnd);
        $previousIncome = $this->sum($userId, 'income', $previousStart, $previousEnd);

        $delta = $previousExpenses > 0
            ? round((($expenses - $previousExpenses) / $previousExpenses) * 100, 1)
            : 0;

        $weeks = [0.0, 0.0, 0.0, 0.0, 0.0];
        $rows = Transaction::query()
            ->where('user_id', $userId)
            ->where('type', 'expense')
            ->whereBetween('occurred_on', [$start->toDateString(), $end->toDateString()])
            ->get(['occurred_on', 'amount']);

        foreach ($rows as $row) {
            $index = (int) floor(($row->occurred_on->day - 1) / 7);
            $weeks[min($index, 4)] += (float) $row->amount;
        }

        $evolution = [];
        for ($i = 5; $i >= 0; $i--) {
            $cursor = $start->copy()->subMonths($i);
            $from = $cursor->copy()->startOfMonth();
            $to = $cursor->copy()->endOfMonth();
            $evolution[] = [
                'month' => $cursor->translatedFormat('M'),
                'key' => $cursor->format('Y-m'),
                'income' => $this->sum($userId, 'income', $from, $to),
                'expenses' => $this->sum($userId, 'expense', $from, $to),
            ];
        }

        return response()->json([
            'data' => [
                'month' => $month,
                'income' => $income,
                'expenses' => $expenses,
                'balance' => round($income - $expenses, 2),
                'previous_income' => $previousIncome,
                'previous_expenses' => $previousExpenses,
                'expenses_delta_percent' => $delta,
                'weekly_expenses' => array_map(fn ($value) => round($value, 2), array_slice($weeks, 0, 4)),
                'balance_evolution' => $evolution,
            ],
        ]);
    }

    public function categories(Request $request): JsonResponse
    {
        $month = $request->string('month', now()->format('Y-m'))->toString();
        $start = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $rows = Transaction::query()
            ->with('category')
            ->where('user_id', $request->user()->id)
            ->where('type', 'expense')
            ->whereBetween('occurred_on', [$start->toDateString(), $end->toDateString()])
            ->get();

        $total = (float) $rows->sum('amount');
        $grouped = $rows->groupBy('category_id')->map(function ($items) use ($total) {
            $category = $items->first()->category;
            $amount = (float) $items->sum('amount');

            return [
                'category_id' => $category?->id,
                'slug' => $category?->slug,
                'name' => $category?->name ?? 'Autre',
                'icon' => $category?->icon,
                'color' => $category?->color,
                'amount' => $amount,
                'percent' => $total > 0 ? round(($amount / $total) * 100, 1) : 0,
            ];
        })->sortByDesc('amount')->values();

        return response()->json([
            'data' => [
                'month' => $month,
                'total' => $total,
                'categories' => $grouped,
            ],
        ]);
    }

    private function sum(int $userId, string $type, Carbon $start, Carbon $end): float
    {
        return round((float) Transaction::query()
            ->where('user_id', $userId)
            ->where('type', $type)
            ->whereBetween('occurred_on', [$start->toDateString(), $end->toDateString()])
            ->sum('amount'), 2);
    }
}
