<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_visitante_es_redirigido_al_pos(): void
    {
        $response = $this->get('/');

        // La raíz redirige al POS; sin sesión, el middleware auth lo manda al login
        $response->assertRedirect(route('pos.index'));
    }
}