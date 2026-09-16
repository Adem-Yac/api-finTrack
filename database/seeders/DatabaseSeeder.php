<?php

namespace Database\Seeders;

use App\Models\AppNotification;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Goal;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['slug' => 'food', 'name' => 'Alimentation', 'type' => 'expense', 'icon' => 'shopping_cart', 'color' => '#F97316'],
            ['slug' => 'restaurant', 'name' => 'Restauration', 'type' => 'expense', 'icon' => 'restaurant', 'color' => '#FB7185'],
            ['slug' => 'transport', 'name' => 'Transport', 'type' => 'expense', 'icon' => 'directions_car', 'color' => '#38BDF8'],
            ['slug' => 'shopping', 'name' => 'Shopping', 'type' => 'expense', 'icon' => 'shopping_bag', 'color' => '#A78BFA'],
            ['slug' => 'bills', 'name' => 'Factures', 'type' => 'expense', 'icon' => 'receipt_long', 'color' => '#F59E0B'],
            ['slug' => 'housing', 'name' => 'Logement', 'type' => 'expense', 'icon' => 'home', 'color' => '#14B8A6'],
            ['slug' => 'health', 'name' => 'Santé', 'type' => 'expense', 'icon' => 'medical_services', 'color' => '#F43F5E'],
            ['slug' => 'education', 'name' => 'Éducation', 'type' => 'expense', 'icon' => 'school', 'color' => '#6366F1'],
            ['slug' => 'entertainment', 'name' => 'Divertissement', 'type' => 'expense', 'icon' => 'movie', 'color' => '#EF4444'],
            ['slug' => 'salary', 'name' => 'Salaire', 'type' => 'income', 'icon' => 'payments', 'color' => '#10B981'],
            ['slug' => 'other', 'name' => 'Autre', 'type' => 'both', 'icon' => 'more_horiz', 'color' => '#64748B'],
        ];

        foreach ($categories as $category) {
            Category::query()->updateOrCreate(['slug' => $category['slug']], $category + ['is_system' => true]);
        }

        $user = User::query()->updateOrCreate(
            ['email' => 'adem@email.com'],
            [
                'name' => 'Adem Benali',
                'phone' => '0550123456',
                'password' => '123456',
                'currency' => 'DZD',
            ]
        );

        $bySlug = Category::query()->pluck('id', 'slug');

        Transaction::query()->where('user_id', $user->id)->delete();

        $rows = [
            ['food', 'expense', 4500, '2026-09-13', 'Courses Alimentation', 'cash'],
            ['transport', 'expense', 2000, '2026-09-13', 'Essence Carburant', 'cib'],
            ['salary', 'income', 180000, '2026-09-12', 'Virement Salaire', 'transfer'],
            ['health', 'expense', 1200, '2026-09-12', 'Pharmacie Centrale', 'cash'],
            ['entertainment', 'expense', 1800, '2026-09-05', 'Abonnement Divertissement', 'cib'],
            ['restaurant', 'expense', 3200, '2026-09-02', 'Déjeuner Restaurant', 'cash'],
            ['food', 'expense', 11000, '2026-09-04', 'Courses semaine 1', 'cash'],
            ['transport', 'expense', 6000, '2026-09-08', 'Essence & taxi', 'cib'],
            ['entertainment', 'expense', 4850, '2026-09-10', 'Sorties', 'cash'],
            ['bills', 'expense', 8200, '2026-09-06', 'Factures maison', 'cib'],
            ['food', 'expense', 24500, '2026-08-20', 'Courses août', 'cash'],
            ['transport', 'expense', 13600, '2026-08-18', 'Transport août', 'cib'],
            ['salary', 'income', 180000, '2026-08-01', 'Salaire août', 'transfer'],
        ];

        foreach ($rows as [$slug, $type, $amount, $date, $note, $method]) {
            Transaction::query()->create([
                'user_id' => $user->id,
                'category_id' => $bySlug[$slug],
                'type' => $type,
                'amount' => $amount,
                'currency' => 'DZD',
                'occurred_on' => $date,
                'note' => $note,
                'payment_method' => $method,
            ]);
        }

        $budget = Budget::query()->updateOrCreate(
            ['user_id' => $user->id, 'year' => 2026, 'month' => 9],
            ['total_amount' => 45000]
        );
        $budget->categories()->delete();
        $budget->categories()->createMany([
            ['category_id' => $bySlug['food'], 'allocated_amount' => 10000],
            ['category_id' => $bySlug['transport'], 'allocated_amount' => 8000],
            ['category_id' => $bySlug['entertainment'], 'allocated_amount' => 8000],
            ['category_id' => $bySlug['housing'], 'allocated_amount' => 19000],
        ]);

        Goal::query()->where('user_id', $user->id)->delete();
        $mac = Goal::query()->create([
            'user_id' => $user->id,
            'title' => 'MacBook Pro M-Chip',
            'emoji' => '💻',
            'target_amount' => 250000,
            'current_amount' => 150000,
            'deadline' => '2026-12-31',
        ]);
        $mac->deposits()->create([
            'amount' => 15000,
            'deposited_on' => '2026-09-01',
            'note' => 'Épargne du mois',
        ]);
        Goal::query()->create([
            'user_id' => $user->id,
            'title' => 'Voyage Vacances',
            'emoji' => '✈️',
            'target_amount' => 120000,
            'current_amount' => 45000,
            'deadline' => '2027-07-01',
        ]);

        AppNotification::query()->where('user_id', $user->id)->delete();
        AppNotification::query()->create([
            'user_id' => $user->id,
            'title' => 'Budget divertissement',
            'body' => 'Vous avez utilisé 98% de votre enveloppe Divertissement.',
            'type' => 'warning',
        ]);
        AppNotification::query()->create([
            'user_id' => $user->id,
            'title' => 'Marché algérien',
            'body' => 'Le spread EUR/DZD parallèle vs officiel reste élevé cette semaine.',
            'type' => 'info',
        ]);
    }
}
