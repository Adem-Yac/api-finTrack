<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 20)->nullable()->unique();
            $table->string('google_id')->nullable()->unique();
            $table->string('avatar_path')->nullable();
            $table->char('currency', 3)->default('DZD');
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('type'); // income, expense, both
            $table->string('icon')->nullable();
            $table->string('color')->nullable();
            $table->boolean('is_system')->default(true);
            $table->timestamps();
        });

        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->string('type'); // income, expense
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3)->default('DZD');
            $table->date('occurred_on');
            $table->string('note')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('attachment_path')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'occurred_on']);
            $table->index(['user_id', 'type']);
        });

        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'year', 'month']);
        });

        Schema::create('budget_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->decimal('allocated_amount', 14, 2);
            $table->timestamps();

            $table->unique(['budget_id', 'category_id']);
        });

        Schema::create('goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('emoji')->nullable();
            $table->decimal('target_amount', 14, 2);
            $table->decimal('current_amount', 14, 2)->default(0);
            $table->date('deadline')->nullable();
            $table->timestamps();
        });

        Schema::create('goal_deposits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goal_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            $table->date('deposited_on');
            $table->string('note')->nullable();
            $table->timestamps();
        });

        Schema::create('currency_rates', function (Blueprint $table) {
            $table->id();
            $table->string('base', 8);
            $table->string('quote', 8);
            $table->string('provider'); // frankfurter, exdz
            $table->string('market')->nullable(); // official, parallel
            $table->decimal('rate', 16, 6);
            $table->decimal('buy', 16, 6)->nullable();
            $table->decimal('sell', 16, 6)->nullable();
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->index(['base', 'quote', 'provider', 'market']);
        });

        Schema::create('currency_history', function (Blueprint $table) {
            $table->id();
            $table->string('base', 8);
            $table->string('quote', 8);
            $table->string('provider');
            $table->string('market')->nullable();
            $table->date('rate_date');
            $table->decimal('rate', 16, 6);
            $table->decimal('buy', 16, 6)->nullable();
            $table->decimal('sell', 16, 6)->nullable();
            $table->timestamps();

            $table->unique(['base', 'quote', 'provider', 'market', 'rate_date'], 'currency_history_unique');
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('body');
            $table->string('type')->default('info');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('currency_history');
        Schema::dropIfExists('currency_rates');
        Schema::dropIfExists('goal_deposits');
        Schema::dropIfExists('goals');
        Schema::dropIfExists('budget_categories');
        Schema::dropIfExists('budgets');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('categories');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone', 'google_id', 'avatar_path', 'currency']);
        });
    }
};
