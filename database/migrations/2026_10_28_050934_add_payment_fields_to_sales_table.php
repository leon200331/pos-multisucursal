<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
public function up(): void
{
    Schema::table('sales', function (Blueprint $table) {
        $table->string('status', 12)->default('paid')->after('total');
        $table->string('payment_method', 10)->default('cash')->after('status');
        $table->string('stripe_checkout_session_id')->nullable()->after('payment_method');
        $table->timestamp('paid_at')->nullable()->after('stripe_checkout_session_id');
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            //
        });
    }
};
