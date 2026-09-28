<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Sales agents and their commissions (§52.1), and the orders.sales_agent_id
| constraint the core migration left to this module (§39.1).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_agents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
            $table->string('name', 120);
            $table->string('phone', 32);
            $table->string('email')->nullable();
            $table->string('agent_code', 32)->unique();
            $table->decimal('commission_rate', 7, 4)->nullable();
            $table->string('status', 8)->default('active');
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('sales_agent_commissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_agent_id')->constrained('sales_agents')->restrictOnDelete();
            $table->foreignId('order_id')->unique()->constrained('orders')->restrictOnDelete();
            $table->decimal('gross_amount', 18, 4);
            $table->decimal('commission_rate_applied', 7, 4);
            $table->decimal('commission_amount', 18, 4);
            $table->string('status', 10)->default('pending');
            $table->dateTime('earned_at');
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->timestamps();

            $table->index(['sales_agent_id', 'status']);
            $table->index(['status', 'earned_at']);
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->foreign('sales_agent_id')->references('id')->on('sales_agents')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropForeign(['sales_agent_id']);
        });

        Schema::dropIfExists('sales_agent_commissions');
        Schema::dropIfExists('sales_agents');
    }
};
