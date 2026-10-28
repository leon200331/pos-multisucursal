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
                <span class="text-sm">👤 {{ auth()->user()->name }} ({{ auth()->user()->role }})</span>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="bg-red-500 text-white px-3 py-1 rounded">Salir</button>
                </form>
            </div>
        </div>

        @if(session('success')) <div class="bg-green-100 text-green-800 p-3 rounded">{{ session('success') }}</div> @endif
        @if(session('error')) <div class="bg-red-100 text-red-800 p-3 rounded">{{ session('error') }}</div> @endif

        <!-- CAMBIO 1: Panel del carrito de la venta actual -->
        <div class="bg-white p-4 rounded shadow">
            <h2 class="font-bold mb-3">🛒 Carrito de la venta actual</h2>
            <table class="w-full text-left border-collapse" id="cart-table">
                <thead>
                    <tr class="border-b">
                        <th class="p-2">Producto</th><th>Precio</th><th>Cantidad</th><th>Subtotal</th><th></th>
                    </tr>
                </thead>
                <tbody id="cart-body"></tbody>
            </table>
            <p id="cart-empty" class="text-gray-500 text-sm">Todavía no hay productos. Usa el botón "+ Carrito".</p>
            <div class="flex justify-between items-center mt-4">
                <span class="text-lg font-bold">Total: $<span id="cart-total">0.00</span></span>
                <form id="checkout-form" method="POST" action="{{ route('pos.store') }}">
                    @csrf
                    <input type="hidden" name="branch_id" value="{{ $currentBranch->id }}">
                    <button type="button" id="checkout-btn" class="bg-blue-600 text-white px-4 py-2 rounded font-bold">Cobrar venta</button>
                </form>
            </div>
        </div>

        <!-- Inventario y Acciones -->
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
                        <!-- CAMBIO 2: botón "+ Carrito" en lugar del formulario de Cobrar por producto -->
                        <td class="p-2 flex gap-2">
                            <button class="add-to-cart bg-blue-600 text-white px-3 py-1 rounded {{ $p->pivot->stock < 1 ? 'opacity-40 pointer-events-none' : '' }}"
                                    data-id="{{ $p->id }}"
                                    data-name="{{ $p->name }}"
                                    data-price="{{ $p->base_price }}"
                                    data-stock="{{ $p->pivot->stock }}">
                                + Carrito
                            </button>
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

    <!-- CAMBIO 3: JavaScript del carrito -->
    <script>
        // El carrito vive en la memoria del navegador: [{ id, name, price, stock, quantity }]
        let cart = [];

        document.querySelectorAll('.add-to-cart').forEach(btn => {
            btn.addEventListener('click', () => {
                const p = btn.dataset;
                addToCart(parseInt(p.id), p.name, parseFloat(p.price), parseInt(p.stock));
            });
        });

        function addToCart(id, name, price, stock) {
            const found = cart.find(i => i.id === id);
            if (found) {
                if (found.quantity + 1 > stock) { alert('No hay más stock de ' + name); return; }
                found.quantity++;
            } else {
                if (stock < 1) return;
                cart.push({ id, name, price, stock, quantity: 1 });
            }
            renderCart();
        }

        function changeQty(id, delta) {
            const item = cart.find(i => i.id === id);
            const newQty = item.quantity + delta;
            if (newQty < 1) return;
            if (newQty > item.stock) { alert('No hay más stock de ' + item.name); return; }
            item.quantity = newQty;
            renderCart();
        }

        function removeItem(id) {
            cart = cart.filter(i => i.id !== id);
            renderCart();
        }

        function renderCart() {
            const body = document.getElementById('cart-body');
            body.innerHTML = '';
            document.getElementById('cart-empty').style.display = cart.length ? 'none' : 'block';

            cart.forEach(item => {
                const row = document.createElement('tr');
                row.className = 'border-b';
                row.innerHTML = `
                    <td class="p-2">${item.name}</td>
                    <td>$${item.price.toFixed(2)}</td>
                    <td class="flex gap-1 items-center">
                        <button onclick="changeQty(${item.id}, -1)" class="border px-2 rounded">−</button>
                        <span class="font-bold w-8 text-center">${item.quantity}</span>
                        <button onclick="changeQty(${item.id}, 1)" class="border px-2 rounded">+</button>
                    </td>
                    <td>$${(item.price * item.quantity).toFixed(2)}</td>
                    <td><button onclick="removeItem(${item.id})" class="text-red-600">✕</button></td>`;
                body.appendChild(row);
            });

            const total = cart.reduce((sum, i) => sum + i.price * i.quantity, 0);
            document.getElementById('cart-total').textContent = total.toFixed(2);
        }

        document.getElementById('checkout-btn').addEventListener('click', () => {
            if (cart.length === 0) { alert('El carrito está vacío'); return; }

            const form = document.getElementById('checkout-form');
            form.querySelectorAll('input[name^="items"]').forEach(i => i.remove());

            // Convertir el carrito a inputs ocultos con la notación items[indice][campo]
            cart.forEach((item, index) => {
                addHidden(form, `items[${index}][product_id]`, item.id);
                addHidden(form, `items[${index}][quantity]`, item.quantity);
            });

            form.submit();
        });

        function addHidden(form, name, value) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = value;
            form.appendChild(input);
        }
    </script>
</body>
</html>