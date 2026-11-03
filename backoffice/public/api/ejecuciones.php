<?php
declare(strict_types=1);
require __DIR__ . '/_api.php';

// POST → el flujo «Errores» de n8n deja constancia del paso que falló y por qué.
permitir('POST');
exigirToken();
Ejecuciones::desdeN8n(cuerpoJson());
responder(['ok' => true], 201);
