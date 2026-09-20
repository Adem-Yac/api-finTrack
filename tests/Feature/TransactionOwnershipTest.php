<?php

namespace Tests\Feature;

use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TransactionOwnershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_cannot_view_another_users_transaction(): void
    {
        $category = Category::query()->create([
            'slug' => 'food',
            'name' => 'Alimentation',
            'type' => 'expense',
            'is_system' => true,
        ]);
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $transaction = Transaction::query()->create([
            'user_id' => $owner->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 1200,
            'currency' => 'DZD',
            'occurred_on' => now()->toDateString(),
            'note' => 'Courses',
        ]);

        Sanctum::actingAs($other);
        $this->getJson('/api/v1/transactions/'.$transaction->id)
            ->assertForbidden();
    }

    public function test_expense_over_budget_creates_a_warning_notification(): void
    {
        $category = Category::query()->create([
            'slug' => 'food',
            'name' => 'Alimentation',
            'type' => 'expense',
            'is_system' => true,
        ]);
        $user = User::factory()->create();
        Budget::query()->create([
            'user_id' => $user->id,
            'year' => now()->year,
            'month' => now()->month,
            'total_amount' => 1000,
        ]);

        Sanctum::actingAs($user);
        $this->postJson('/api/v1/transactions', [
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 1500,
            'occurred_on' => now()->toDateString(),
            'note' => 'Courses',
        ])->assertCreated();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'type' => 'warning',
            'title' => 'Budget du mois dépassé',
        ]);
    }
}
