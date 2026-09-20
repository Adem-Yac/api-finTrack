<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\Budget;
use App\Models\Goal;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;

class NotificationService
{
    public function afterTransaction(User $user, Transaction $transaction): void
    {
        if ($transaction->type !== 'expense') {
            return;
        }

        $occurred = $transaction->occurred_on instanceof Carbon
            ? $transaction->occurred_on
            : Carbon::parse((string) $transaction->occurred_on);

        $budget = Budget::query()
            ->with('categories.category')
            ->where('user_id', $user->id)
            ->where('year', $occurred->year)
            ->where('month', $occurred->month)
            ->first();

        if (! $budget) {
            return;
        }

        $start = Carbon::create($budget->year, $budget->month, 1)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $spentByCategory = Transaction::query()
            ->where('user_id', $user->id)
            ->where('type', 'expense')
            ->whereBetween('occurred_on', [$start->toDateString(), $end->toDateString()])
            ->selectRaw('category_id, SUM(amount) as spent')
            ->groupBy('category_id')
            ->pluck('spent', 'category_id');

        $totalSpent = (float) $spentByCategory->sum();
        $allocated = (float) $budget->total_amount;

        if ($allocated > 0) {
            $percent = round(($totalSpent / $allocated) * 100, 1);
            if ($percent >= 100) {
                $this->onceToday(
                    $user,
                    'warning',
                    'Budget du mois dépassé',
                    'Vos dépenses ont atteint '.$percent.' % de l’enveloppe du mois.',
                );
            } elseif ($percent >= 80) {
                $this->onceToday(
                    $user,
                    'warning',
                    'Budget du mois presque épuisé',
                    'Vous avez utilisé '.$percent.' % de votre budget mensuel.',
                );
            }
        }

        $line = $budget->categories->firstWhere('category_id', $transaction->category_id);
        if (! $line) {
            return;
        }

        $spent = (float) ($spentByCategory[$line->category_id] ?? 0);
        $categoryAllocated = (float) $line->allocated_amount;
        if ($categoryAllocated <= 0) {
            return;
        }

        $percent = round(($spent / $categoryAllocated) * 100, 1);
        $name = $line->category?->name ?? 'cette catégorie';
        if ($percent >= 100) {
            $this->onceToday(
                $user,
                'warning',
                'Enveloppe '.$name.' dépassée',
                'Vous avez dépassé le budget prévu pour '.$name.'.',
            );
        } elseif ($percent >= 80) {
            $this->onceToday(
                $user,
                'warning',
                'Enveloppe '.$name,
                'Vous avez utilisé '.$percent.' % du budget '.$name.'.',
            );
        }
    }

    public function afterGoalDeposit(User $user, Goal $goal): void
    {
        $target = (float) $goal->target_amount;
        $current = (float) $goal->current_amount;
        if ($target <= 0) {
            return;
        }

        $percent = round(($current / $target) * 100, 1);
        $title = $goal->title;

        if ($percent >= 100) {
            $this->onceToday(
                $user,
                'success',
                'Objectif atteint',
                $title.' est maintenant complètement épargné.',
            );

            return;
        }

        $this->push(
            $user,
            'info',
            'Épargne mise à jour',
            $title.' est à '.$percent.' % de l’objectif.',
        );
    }

    public function push(User $user, string $type, string $title, string $body): AppNotification
    {
        return AppNotification::query()->create([
            'user_id' => $user->id,
            'type' => $type,
            'title' => $title,
            'body' => $body,
        ]);
    }

    private function onceToday(User $user, string $type, string $title, string $body): void
    {
        $exists = AppNotification::query()
            ->where('user_id', $user->id)
            ->where('type', $type)
            ->where('title', $title)
            ->whereDate('created_at', now()->toDateString())
            ->exists();

        if ($exists) {
            return;
        }

        $this->push($user, $type, $title, $body);
    }
}
