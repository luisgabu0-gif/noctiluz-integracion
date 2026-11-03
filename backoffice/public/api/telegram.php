<?php
declare(strict_types=1);
require __DIR__ . '/_api.php';

// POST → el cliente pulsó el enlace del bot: {"carga": "NCZ-..._firma", "chat_id": "123"}
permitir('POST');
exigirToken();
$e = cuerpoJson();
responder(['ok' => true] + Integracion::vincularTelegram((string) ($e['carga'] ?? ''), (string) ($e['chat_id'] ?? '')));
