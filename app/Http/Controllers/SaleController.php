<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\InventoryMovement;
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
        'items' => 'required|array|min:1',
        'items.*.product_id' => 'required|exists:products,id',
        'items.*.quantity' => 'required|integer|min:1',
    ]);

    try {
        DB::transaction(function () use ($request) {
            $branchId = $request->branch_id;
            $user = auth()->user();

            // 1. Juntar cantidades por producto (si el mismo vino repetido, se suma)
            $quantities = collect($request->items)
                ->groupBy('product_id')
                ->map(fn ($group) => $group->sum('quantity'));

            // 2. Bloquear TODAS las filas necesarias en UNA sola consulta
            $pivots = DB::table('branch_product')
                ->where('branch_id', $branchId)
                ->whereIn('product_id', $quantities->keys())
                ->lockForUpdate()
                ->get()
                ->keyBy('product_id');

            // 3. Verificar el stock de TODO antes de escribir algo
            foreach ($quantities as $productId => $qty) {
                $pivot = $pivots->get($productId);
                if (!$pivot || $pivot->stock < $qty) {
                    $product = \App\Models\Product::find($productId);
                    throw new \Exception("Stock insuficiente de '{$product->name}' (disponible: " . ($pivot->stock ?? 0) . ").");
                }
            }

            // 4. Calcular el total general y crear la cabecera
            $products = \App\Models\Product::whereIn('id', $quantities->keys())->get()->keyBy('id');

            $total = 0;
            foreach ($quantities as $productId => $qty) {
                $total += $products[$productId]->base_price * $qty;
            }

            $sale = Sale::create([
                'branch_id' => $branchId,
                'user_id' => $user->id,
                'total' => $total,
            ]);

            // 5. Un renglón por producto: SaleItem + descuento de stock + kardex
            foreach ($quantities as $productId => $qty) {
                $product = $products[$productId];
                $pivot = $pivots[$productId];
                $newStock = $pivot->stock - $qty;

                DB::table('branch_product')->where('id', $pivot->id)
                    ->update(['stock' => $newStock, 'updated_at' => now()]);

                SaleItem::create([
                    'sale_id' => $sale->id,
                    'product_id' => $product->id,
                    'quantity' => $qty,
                    'unit_price' => $product->base_price,
                    'subtotal' => $product->base_price * $qty,
                ]);

                InventoryMovement::create([
                    'branch_id' => $branchId,
                    'product_id' => $product->id,
                    'user_id' => $user->id,
                    'type' => 'out',
                    'quantity' => $qty,
                    'previous_stock' => $pivot->stock,
                    'new_stock' => $newStock,
                    'reference' => 'Venta #' . $sale->id,
                ]);
            }
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
                'user_id' => auth()->user()->id, // CAMBIO 2: antes era User::first()->id
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

                $userId = auth()->user()->id; // CAMBIO 3: antes era User::first()->id
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