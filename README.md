# 🏪 POS Multisucursal

Sistema de punto de venta multi-sucursal con Laravel 13: inventario por sucursal, kardex
(auditoría de movimientos), ventas multirrenglón, traspasos de mercancía y pagos con
tarjeta vía Stripe (Checkout + Webhook firmado).

## Stack

- PHP 8.3 · Laravel 13 · MySQL
- Laravel Breeze (Blade) para autenticación
- Stripe Checkout + Webhooks (modo test)
- Tailwind CSS
- PHPUnit

## Puesta en marcha

```bash
composer install
copy .env.example .env
php artisan key\:generate
php artisan migrate --seed
npm install
npm run build
php artisan serve
```

Usuarios de prueba (los crea el seeder):
   Usuario       | Correo           | Contraseña   |
 |---------------|------------------|--------------|
 | Admin General | admin@pos.com    | password123  |
 | Cajero Norte  | cajero1@pos.com  | password123  |

## Pagos con tarjeta (modo test de Stripe)

1. En `.env`: `STRIPE_SECRET=sk_test_...` y `STRIPE_WEBHOOK_SECRET=whsec_...`.
2. Levantar el puente local de webhooks:

```bash
stripe listen --events checkout.session.completed --forward-to localhost:8000/webhooks/stripe
```

3. Pagar con la tarjeta de prueba: `4242 4242 4242 4242`, vencimiento `12/34`, CVC `123`.

## Decisiones de arquitectura

- **Transacciones con bloqueo pesimista (`lockForUpdate`)**: dos cajeros simultáneos no
  pueden vender el mismo stock; las filas se bloquean en una sola consulta para minimizar
  interbloqueos (deadlocks).
- **Cabecera + detalle (`sales` / `sale_items`)**: `unit_price` se congela en cada renglón
  (snapshot), para que los reportes históricos no cambien si el precio cambia después.
- **La venta con tarjeta nace `pending` con stock reservado; solo el webhook la marca `paid`.**
  La redirección del navegador nunca es fuente de verdad.
- **Webhook firmado con `Stripe\Webhook::constructEvent`** sobre el payload crudo; firma
  inválida → HTTP 400 y no se toca nada.
- **Idempotencia**: Stripe puede reenviar el mismo evento; el webhook bloquea la fila y solo
  procesa ventas aún `pending`.

## Tests

```bash
php artisan test
```

Cobertura: venta en efectivo con actualización de stock y kardex, rollback completo ante
stock insuficiente, webhook con firma válida (marca `paid`) y firma falsificada (rechazado
con 400 y venta intacta).

## Roadmap

- Cronjob para expirar sesiones de pago abandonadas.
- Middleware de roles (admin vs cajero) por ruta.
- Reportes por sucursal y corte de caja.
- API REST para app móvil.