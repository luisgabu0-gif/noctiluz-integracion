<?php
declare(strict_types=1);
require __DIR__ . '/_api.php';

// Comprobación de vida (Render y n8n). Sin token y sin datos: solo dice si la base de datos responde.
permitir('GET');
Db::valor('SELECT 1');
responder(['ok' => true, 'servicio' => 'noctiluz-backoffice', 'hora' => date('c')]);
