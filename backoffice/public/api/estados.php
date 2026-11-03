<?php
declare(strict_types=1);
require __DIR__ . '/_api.php';

// POST → escenario 2 (tienda → back-office): n8n entrega un order.status_changed.
// El sentido contrario (back-office → tienda) lo inicia el panel: app/Sincronizacion.php.
permitir('POST');
exigirToken();
procesarEvento('estado', cuerpoJson(), [Integracion::class, 'recibirCambioEstado']);
