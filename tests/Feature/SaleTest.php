<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_cajero_registra_venta_en_efectivo(): void
    {
        $cashier = User::where('email', 'cajero1@pos.com')->first();

        $response = $this->actingAs($cashier)->post('/sales', [
            'branch_id' => 1,
            'items' => [
                ['product_id' => 1, 'quantity' => 2], // Refresco: 2 x $18.50
                ['product_id' => 2, 'quantity' => 1], // Agua: 1 x $12.00
            ],
        ]);

        $response->assertRedirect();

        // Cabecera: pagada, en efectivo, total correcto
        $this->assertDatabaseHas('sales', [
            'status' => 'paid',
            'payment_method' => 'cash',
            'total' => 49.00,
        ]);

        // Stock descontado: refresco 50 -> 48, agua 30 -> 29
        $this->assertDatabaseHas('branch_product', ['product_id' => 1, 'stock' => 48]);
        $this->assertDatabaseHas('branch_product', ['product_id' => 2, 'stock' => 29]);

        // Kardex: un movimiento OUT por producto
        $this->assertDatabaseCount('inventory_movements', 2);
    }

    public function test_rechaza_venta_completa_cuando_falta_stock(): void
    {
        $cashier = User::where('email', 'cajero1@pos.com')->first();

        $response = $this->actingAs($cashier)->post('/sales', [
            'branch_id' => 1,
            'items' => [
                ['product_id' => 1, 'quantity' => 2],    // este SÍ alcanza
                ['product_id' => 2, 'quantity' => 999],   // este NO
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');

        // Rollback total: no se guardó NADA y el stock quedó intacto
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseHas('branch_product', ['product_id' => 1, 'stock' => 50]);
        $this->assertDatabaseHas('branch_product', ['product_id' => 2, 'stock' => 30]);
    }
}