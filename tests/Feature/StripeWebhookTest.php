<?php

namespace Tests\Feature;

use App\Models\Sale;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StripeWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        config(['services.stripe.webhook' => 'whsec_test_de_prueba']);
    }

    private function ventaPendiente(): Sale
    {
        return Sale::create([
            'branch_id' => 1,
            'user_id' => 2,
            'total' => 49.00,
            'status' => 'pending',
            'payment_method' => 'card',
            'stripe_checkout_session_id' => 'cs_test_123',
        ]);
    }

    private function payloadValido(string $sessionId): string
    {
        return json_encode([
            'id' => 'evt_test_1',
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => ['object' => ['id' => $sessionId]],
        ]);
    }

    private function firmaValida(string $payload): string
    {
        $timestamp = time();
        $firma = hash_hmac('sha256', "{$timestamp}.{$payload}", 'whsec_test_de_prueba');
        return "t={$timestamp},v1={$firma}";
    }

    public function test_webhook_con_firma_valida_marca_la_venta_pagada(): void
    {
        $sale = $this->ventaPendiente();
        $payload = $this->payloadValido('cs_test_123');

        $response = $this->call('POST', '/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $this->firmaValida($payload),
        ], $payload);

        $response->assertOk();
        $this->assertDatabaseHas('sales', ['id' => $sale->id, 'status' => 'paid']);
    }

    public function test_webhook_con_firma_falsificada_se_rechaza(): void
    {
        $sale = $this->ventaPendiente();
        $payload = $this->payloadValido('cs_test_123');

        // Un "atacante" que calcula la firma con OTRO secreto
        $timestamp = time();
        $firmaFalsa = hash_hmac('sha256', "{$timestamp}.{$payload}", 'whsec_secreto_del_atacante');

        $response = $this->call('POST', '/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$firmaFalsa}",
        ], $payload);

        $response->assertStatus(400);
        $this->assertDatabaseHas('sales', ['id' => $sale->id, 'status' => 'pending']);
    }
}