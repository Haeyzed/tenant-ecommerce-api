<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Landlord: the billing mode of each MRR movement, so test-mode
 * subscriptions keep their own ledger and the platform dashboard can show
 * test figures on request (§22.1 `mode`). Existing rows were all live.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_mrr_movements', function (Blueprint $table): void {
            $table->string('mode', 8)->default('live')->after('currency_code');
            $table->index(['mode', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::table('subscription_mrr_movements', function (Blueprint $table): void {
            $table->dropIndex(['mode', 'occurred_at']);
            $table->dropColumn('mode');
        });
    }
};
