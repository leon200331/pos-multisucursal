<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\InventoryMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class SaleController extends Controller
{
    public function index(Request $request)
    {
        $branches = Branch::where('is_active', true)->get();
        $branchId = $request->get('branch_id', $branches->first()->id);

        $currentBranch = Branch::findOrFail($branchId);
        $products = $currentBranch->products()->with('category')->get();
        $sales = Sale::with('user')->where('branch_id', $branchId)->latest('id')->take(5)->get();
        $totalSales = Sale::where('branch_id', $branchId)->sum('total');

        $movements = InventoryMovement::with(['product', 'user'])
            ->where('branch_id', $branchId)
            ->latest('id')
            ->take(5)
            ->get();

        return view('pos.index', compact('branches', 'currentBranch', 'products', 'sales', 'movements', 'totalSales'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1',
        ]);

        try {
            DB::transaction(function () use ($request) {
                // 1. Bloquear fila en tabla pivote para evitar ventas duplicadas simultáneas
                $pivot = DB::table('branch_product')
                    ->where('branch_id', $request->branch_id)
                    ->where('product_id', $request->product_id)
                    ->lockForUpdate()
                    ->first();

                if (!$pivot || $pivot->stock < $request->quantity) {
                    throw new \Exception('Stock insuficiente en esta sucursal.');
                }

                $product = \App\Models\Product::findOrFail($request->product_id);
                $user = User::first(); // Usamos nuestro usuario creado en el Seeder
                $newStock = $pivot->stock - $request->quantity;
                $total = $product->base_price * $request->quantity;

                // 2. Actualizar stock en la sucursal
                DB::table('branch_product')
                    ->where('id', $pivot->id)
                    ->update(['stock' => $newStock, 'updated_at' => now()]);

                // 3. Crear cabecera y detalle de la venta
                $sale = Sale::create([
                    'branch_id' => $request->branch_id,
                    'user_id' => $user->id,
                    'total' => $total,
                ]);

                SaleItem::create([
                    'sale_id' => $sale->id,
                    'product_id' => $product->id,
                    'quantity' => $request->quantity,
                    'unit_price' => $product->base_price,
                    'subtotal' => $total,
                ]);

                // 4. Registrar auditoría en el Kardex
                InventoryMovement::create([
                    'branch_id' => $request->branch_id,
                    'product_id' => $product->id,
                    'user_id' => $user->id,
                    'type' => 'out',
                    'quantity' => $request->quantity,
                    'previous_stock' => $pivot->stock,
                    'new_stock' => $newStock,
                    'reference' => 'Venta #' . $sale->id,
                ]);
            });

            return back()->with('success', 'Venta registrada y stock actualizado.');
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function restock(Request $request)
    {
        $request->validate(['branch_id' => 'required', 'product_id' => 'required', 'quantity' => 'required|integer|min:1']);

        DB::transaction(function () use ($request) {
            $pivot = DB::table('branch_product')
                ->where('branch_id', $request->branch_id)
                ->where('product_id', $request->product_id)
                ->lockForUpdate()->first();

            $newStock = $pivot->stock + $request->quantity;

            DB::table('branch_product')->where('id', $pivot->id)->update(['stock' => $newStock, 'updated_at' => now()]);

            InventoryMovement::create([
                'branch_id' => $request->branch_id,
                'product_id' => $request->product_id,
                'user_id' => User::first()->id,
                'type' => 'in',
                'quantity' => $request->quantity,
                'previous_stock' => $pivot->stock,
                'new_stock' => $newStock,
                'reference' => 'Reabastecimiento manual',
            ]);
        });

        return back()->with('success', 'Stock reabastecido correctamente.');
    }

    public function transfer(Request $request)
    {
        $request->validate([
            'from_branch_id' => 'required|exists:branches,id',
            'to_branch_id' => 'required|exists:branches,id|different:from_branch_id',
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1',
        ]);

        try {
            DB::transaction(function () use ($request) {
                $origin = DB::table('branch_product')
                    ->where('branch_id', $request->from_branch_id)
                    ->where('product_id', $request->product_id)
                    ->lockForUpdate()->first();

                if (!$origin || $origin->stock < $request->quantity) {
                    throw new \Exception('Stock insuficiente para traspasar.');
                }

                $dest = DB::table('branch_product')
                    ->where('branch_id', $request->to_branch_id)
                    ->where('product_id', $request->product_id)
                    ->lockForUpdate()->first();

                $userId = User::first()->id;
                $newOriginStock = $origin->stock - $request->quantity;
                DB::table('branch_product')->where('id', $origin->id)->update(['stock' => $newOriginStock, 'updated_at' => now()]);

                $prevDestStock = $dest ? $dest->stock : 0;
                $newDestStock = $prevDestStock + $request->quantity;

                if ($dest) {
                    DB::table('branch_product')->where('id', $dest->id)->update(['stock' => $newDestStock, 'updated_at' => now()]);
                } else {
                    DB::table('branch_product')->insert([
                        'branch_id' => $request->to_branch_id, 'product_id' => $request->product_id,
                        'stock' => $newDestStock, 'min_stock' => 5, 'created_at' => now(), 'updated_at' => now()
                    ]);
                }

                InventoryMovement::create([
                    'branch_id' => $request->from_branch_id, 'product_id' => $request->product_id, 'user_id' => $userId,
                    'type' => 'out', 'quantity' => $request->quantity, 'previous_stock' => $origin->stock,
                    'new_stock' => $newOriginStock, 'reference' => 'Traspaso a Sucursal #' . $request->to_branch_id,
                ]);

                InventoryMovement::create([
                    'branch_id' => $request->to_branch_id, 'product_id' => $request->product_id, 'user_id' => $userId,
                    'type' => 'in', 'quantity' => $request->quantity, 'previous_stock' => $prevDestStock,
                    'new_stock' => $newDestStock, 'reference' => 'Traspaso desde Sucursal #' . $request->from_branch_id,
                ]);
            });
            return back()->with('success', 'Traspaso realizado con éxito.');
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}