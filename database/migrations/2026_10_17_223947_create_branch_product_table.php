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
    Schema::create('branch_product', function (Blueprint $table) {
        $table->id();
        $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
        $table->foreignId('product_id')->constrained()->cascadeOnDelete();
        $table->integer('stock')->default(0);
        $table->integer('min_stock')->default(5);
        $table->timestamps();

        $table->unique(['branch_id', 'product_id']);
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('branch_product');
    }
};
