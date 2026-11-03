<?php
declare(strict_types=1);

// Arranque común (API y panel): carga clases, zona horaria y crea las tablas la primera vez.

foreach (['Config', 'Db', 'Reglas', 'Esquema', 'Integracion', 'Sincronizacion', 'Agente', 'Ejecuciones', 'Metricas'] as $clase) {
    require_once __DIR__ . "/$clase.php";
}

date_default_timezone_set('Europe/Madrid');
ini_set('serialize_precision', '-1');

Esquema::asegurar();
