<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>POS Multi-sucursal</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 p-6">
    <div class="max-w-4xl mx-auto space-y-6">
        <!-- Selector de Sucursal y Corte de Caja -->
        <div class="flex justify-between items-center bg-white p-4 rounded shadow">
            <h1 class="text-xl font-bold">Tienda: {{ $currentBranch->name }}</h1>
            <div class="flex items-center gap-4">
                <span class="bg-green-100 text-green-800 px-3 py-1 rounded font-bold">Ventas Totales: ${{ number_format($totalSales ?? 0, 2) }}</span>
                <form method="GET" action="{{ route('pos.index') }}">
                    <select name="branch_id" onchange="this.form.submit()" class="border p-2 rounded">
                        @foreach($branches as $b)
                            <option value="{{ $b->id }}" {{ $b->id == $currentBranch->id ? 'selected' : '' }}>{{ $b->name }}</option>
                        @endforeach
                    </select>
                </form>
                <!-- CAMBIO (Paso 9): usuario con sesión activa + botón Salir -->
                <span class="text-sm">👤 {{ auth()->user()->name }} ({{ auth()->user()->role }})</span>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="bg-red-500 text-white px-3 py-1 rounded">Salir</button>
                </form>
            </div>
        </div>

        @if(session('success')) <div class="bg-green-100 text-green-800 p-3 rounded">{{ session('success') }}</div> @endif
        @if(session('error')) <div class="bg-red-100 text-red-800 p-3 rounded">{{ session('error') }}</div> @endif

        <!-- Inventario y Acciones (Cobrar / + Stock) -->
        <div class="bg-white p-4 rounded shadow">
            <h2 class="font-bold mb-3">Inventario por Sucursal</h2>
            <table class="w-full text-left border-collapse">
                <thead><tr class="border-b"><th class="p-2">Producto</th><th>Precio</th><th>Stock (Pivote)</th><th>Acción</th></tr></thead>
                <tbody>
                    @foreach($products as $p)
                    <tr class="border-b">
                        <td class="p-2">{{ $p->sku }} - {{ $p->name }}</td>
                        <td>${{ number_format($p->base_price, 2) }}</td>
                        <td class="font-bold {{ $p->pivot->stock <= $p->pivot->min_stock ? 'text-red-600' : 'text-green-700' }}">
                            {{ $p->pivot->stock }} (Mín: {{ $p->pivot->min_stock }})
                        </td>
                        <td class="p-2 flex gap-2">
                            <form method="POST" action="{{ route('pos.store') }}" class="flex gap-1">
                                @csrf
                                <input type="hidden" name="branch_id" value="{{ $currentBranch->id }}">
                                <input type="hidden" name="product_id" value="{{ $p->id }}">
                                <input type="number" name="quantity" value="1" min="1" class="border w-14 p-1 rounded">
                                <button class="bg-blue-600 text-white px-2 py-1 rounded">Cobrar</button>
                            </form>
                            <form method="POST" action="{{ route('pos.restock') }}" class="flex gap-1">
                                @csrf
                                <input type="hidden" name="branch_id" value="{{ $currentBranch->id }}">
                                <input type="hidden" name="product_id" value="{{ $p->id }}">
                                <input type="number" name="quantity" value="10" min="1" class="border w-14 p-1 rounded">
                                <button class="bg-emerald-600 text-white px-2 py-1 rounded">+ Stock</button>
                            </form>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <!-- Módulo de Traspasos entre Sucursales -->
        <div class="bg-white p-4 rounded shadow">
            <h2 class="font-bold mb-3">Traspaso de Mercancía</h2>
            <form method="POST" action="{{ route('pos.transfer') }}" class="flex gap-4 items-end">
                @csrf
                <input type="hidden" name="from_branch_id" value="{{ $currentBranch->id }}">
                <div>
                    <label class="block text-sm text-gray-600">Sucursal Destino:</label>
                    <select name="to_branch_id" class="border p-2 rounded">
                        @foreach($branches as $b)
                            @if($b->id != $currentBranch->id)
                                <option value="{{ $b->id }}">{{ $b->name }}</option>
                            @endif
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm text-gray-600">Producto:</label>
                    <select name="product_id" class="border p-2 rounded">
                        @foreach($products as $p)
                            <option value="{{ $p->id }}">{{ $p->name }} (Stock: {{ $p->pivot->stock }})</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm text-gray-600">Cantidad:</label>
                    <input type="number" name="quantity" value="1" min="1" class="border w-20 p-2 rounded">
                </div>
                <button class="bg-purple-600 text-white px-4 py-2 rounded h-10">Realizar Traspaso</button>
            </form>
        </div>

        <!-- Historial Kardex -->
        <div class="bg-white p-4 rounded shadow">
            <h2 class="font-bold mb-3">Historial Kardex (Últimos movimientos)</h2>
            <table class="w-full text-left border-collapse text-sm">
                <thead><tr class="border-b"><th class="p-2">Fecha</th><th>Producto</th><th>Tipo</th><th>Cant.</th><th>Stock Antes ➔ Después</th><th>Referencia</th></tr></thead>
                <tbody>
                    @foreach($movements as $m)
                    <tr class="border-b">
                        <td class="p-2">{{ $m->created_at->format('H:i:s') }}</td>
                        <td>{{ $m->product->name }}</td>
                        <td>
                            <span class="px-2 py-1 rounded {{ $m->type == 'in' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
                                {{ strtoupper($m->type) }}
                            </span>
                        </td>
                        <td>{{ $m->type == 'in' ? '+' : '-' }}{{ $m->quantity }}</td>
                        <td class="font-mono">{{ $m->previous_stock }} ➔ {{ $m->new_stock }}</td>
                        <td>{{ $m->reference }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>