<?php
declare(strict_types=1);
require __DIR__ . '/_api.php';

// POST  → escenario 1: n8n entrega un order.created de la tienda.
// GET ?codigo=NCZ-...  → herramienta «consultar_pedido» del agente.
permitir('GET', 'POST');
exigirToken();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $codigo = parametro('codigo') ?? '';
    responder(['ok' => true] + Integracion::consultarPedido($codigo));
}
procesarEvento('pedido', cuerpoJson(), [Integracion::class, 'recibirPedido']);
