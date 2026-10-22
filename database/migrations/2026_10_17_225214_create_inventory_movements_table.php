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
    Schema::create('inventory_movements', function (Blueprint $table) {
    $table->id();
    $table->foreignId('branch_id')->constrained()->restrictOnDelete();
    $table->foreignId('product_id')->constrained()->restrictOnDelete();
    $table->foreignId('user_id')->constrained()->restrictOnDelete();
    $table->enum('type', ['in', 'out', 'adjustment']);
    $table->integer('quantity');
    $table->integer('previous_stock');
    $table->integer('new_stock');
    $table->string('reference')->nullable();
    $table->timestamps();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};
