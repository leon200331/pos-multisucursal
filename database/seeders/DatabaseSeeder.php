<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Branch;
use App\Models\User;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $norte = Branch::create(['name' => 'Sucursal Norte', 'code' => 'SUC-01', 'address' => 'Av. Norte 123']);
        $sur   = Branch::create(['name' => 'Sucursal Sur', 'code' => 'SUC-02', 'address' => 'Calle Sur 456']);

        User::create([
            'name' => 'Admin General',
            'email' => 'admin@pos.com',
            'password' => Hash::make('password123'),
            'role' => 'admin',
            'branch_id' => null,
        ]);

        User::create([
            'name' => 'Cajero Norte',
            'email' => 'cajero1@pos.com',
            'password' => Hash::make('password123'),
            'role' => 'cashier',
            'branch_id' => $norte->id,
        ]);

        $bebidas = Category::create(['name' => 'Bebidas']);

        $prod1 = Product::create(['category_id' => $bebidas->id, 'sku' => '7501001', 'name' => 'Refresco 600ml', 'base_price' => 18.50]);
        $prod2 = Product::create(['category_id' => $bebidas->id, 'sku' => '7501002', 'name' => 'Agua Natural 1L', 'base_price' => 12.00]);

        // Asignar stock en la tabla pivote (branch_product)
        $norte->products()->attach($prod1->id, ['stock' => 50, 'min_stock' => 10]);
        $norte->products()->attach($prod2->id, ['stock' => 30, 'min_stock' => 5]);
        $sur->products()->attach($prod1->id, ['stock' => 8, 'min_stock' => 10]);
    }
}