<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Landlord: which touch attributed a registration to an affiliate (§21A.4),
 * so the referral created at verification records the right source. A
 * coupon touch leaves affiliate_click_id null and uses platform_coupon_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_registrations', function (Blueprint $table): void {
            $table->string('affiliate_source', 24)->nullable()->after('affiliate_click_id');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_registrations', function (Blueprint $table): void {
            $table->dropColumn('affiliate_source');
        });
    }
};
