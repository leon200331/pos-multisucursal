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

        return view('pos.index', compact('branches', 'currentBranch', 'products', 'sales', 'movements', 'totalSales'))
            ->with('stripeReturn', $request->get('stripe_return'));
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
                $user = auth()->user();

                $quantities = collect($request->items)
                    ->groupBy('product_id')
                    ->map(fn ($group) => $group->sum('quantity'));

                $sale = Sale::create([
                    'branch_id' => $request->branch_id,
                    'user_id' => $user->id,
                    'total' => 0,
                    'status' => 'paid',
                    'payment_method' => 'cash',
                    'paid_at' => now(),
                ]);

                $total = $this->processItems($sale, $quantities);
                $sale->update(['total' => $total]);
            });

            return back()->with('success', 'Venta en efectivo registrada.');
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function checkoutWithCard(Request $request)
    {
        $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
        ]);

        try {
            // 1. Crear la venta PENDIENTE y reservar el stock, en una transacción
            $sale = DB::transaction(function () use ($request) {
                $user = auth()->user();

                $quantities = collect($request->items)
                    ->groupBy('product_id')
                    ->map(fn ($group) => $group->sum('quantity'));

                $sale = Sale::create([
                    'branch_id' => $request->branch_id,
                    'user_id' => $user->id,
                    'total' => 0,
                    'status' => 'pending',
                    'payment_method' => 'card',
                ]);

                $total = $this->processItems($sale, $quantities);
                $sale->update(['total' => $total]);

                return $sale->fresh()->load('items.product');
            });

            // 2. Pedirle a Stripe una página de pago (FUERA de la transacción de BD)
            $stripe = new \Stripe\StripeClient(config('services.stripe.secret'));

            $lineItems = [];
            foreach ($sale->items as $item) {
                $lineItems[] = [
                    'price_data' => [
                        'currency' => 'mxn',
                        'unit_amount' => (int) round($item->unit_price * 100),
                        'product_data' => ['name' => $item->product->name],
                    ],
                    'quantity' => $item->quantity,
                ];
            }

            $session = $stripe->checkout->sessions->create([
                'mode' => 'payment',
                'line_items' => $lineItems,
                'success_url' => route('pos.index', ['branch_id' => $sale->branch_id, 'stripe_return' => 'success']),
                'cancel_url' => route('pos.index', ['branch_id' => $sale->branch_id, 'stripe_return' => 'cancel']),
                'metadata' => ['sale_id' => (string) $sale->id],
            ]);

            $sale->update(['stripe_checkout_session_id' => $session->id]);

            // 3. Mandar al cliente a la página de pago de Stripe
            return redirect()->away($session->url);
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    private function processItems(Sale $sale, \Illuminate\Support\Collection $quantities): float
    {
        $user = auth()->user();
        $branchId = $sale->branch_id;

        $pivots = DB::table('branch_product')
            ->where('branch_id', $branchId)
            ->whereIn('product_id', $quantities->keys())
            ->lockForUpdate()
            ->get()
            ->keyBy('product_id');

        foreach ($quantities as $productId => $qty) {
            $pivot = $pivots->get($productId);
            if (!$pivot || $pivot->stock < $qty) {
                $product = \App\Models\Product::find($productId);
                throw new \Exception("Stock insuficiente de '{$product->name}' (disponible: " . ($pivot->stock ?? 0) . ").");
            }
        }

        $products = \App\Models\Product::whereIn('id', $quantities->keys())->get()->keyBy('id');
        $total = 0;

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
                'reference' => 'Venta #' . $sale->id . ($sale->payment_method === 'card' ? ' (reserva tarjeta)' : ''),
            ]);

            $total += $product->base_price * $qty;
        }

        return $total;
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
                'user_id' => auth()->user()->id,
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

                $userId = auth()->user()->id;
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