<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StripeWebhookController extends Controller
{
    public function handle(Request $request)
    {
        // 1. Leer el payload CRUDO y la firma que manda Stripe
        $payload = $request->getContent();
        $signature = $request->header('Stripe-Signature');
        $secret = config('services.stripe.webhook');

        // 2. Verificar que la firma sea auténtica — LA puerta de seguridad
        try {
            $event = \Stripe\Webhook::constructEvent($payload, $signature, $secret);
        } catch (\UnexpectedValueException $e) {
            return response('Payload inválido', 400);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            return response('Firma inválida', 400);
        }

        // 3. Reaccionar solo al evento que nos importa
        if ($event->type === 'checkout.session.completed') {
            $session = $event->data->object;

            DB::transaction(function () use ($session) {
                $sale = Sale::where('stripe_checkout_session_id', $session->id)
                    ->lockForUpdate()
                    ->first();

                if (!$sale) {
                    Log::warning('Webhook: sesion de Stripe sin venta local', ['session' => $session->id]);
                    return;
                }

                if ($sale->status === 'paid') {
                    return; // IDEMPOTENCIA: ya fue procesada, no hacer nada
                }

                $sale->update([
                    'status' => 'paid',
                    'paid_at' => now(),
                ]);
            });
        }

        // 4. Siempre responder 200 rápido si todo salió bien
        return response()->json(['received' => true]);
    }
}