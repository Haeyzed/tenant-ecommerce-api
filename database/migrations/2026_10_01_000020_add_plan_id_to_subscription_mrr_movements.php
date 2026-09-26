<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Landlord: the plan a subscription is on after each MRR movement, so the
 * plan movement matrix (§22.6 "plans") can pair a plan change with the plan
 * of the tenant's preceding movement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_mrr_movements', function (Blueprint $table): void {
            $table->foreignId('plan_id')->nullable()->after('subscription_id')->constrained('plans');
            $table->index(['type', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::table('subscription_mrr_movements', function (Blueprint $table): void {
            $table->dropIndex(['type', 'occurred_at']);
            $table->dropConstrainedForeignId('plan_id');
        });
    }
};
