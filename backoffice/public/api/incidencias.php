<?php
declare(strict_types=1);
require __DIR__ . '/_api.php';

// POST                         → escenario 3: n8n entrega un support.requested.
// POST ?accion=notificada      → n8n confirma que ya avisó por Telegram ({"codigo": "INC-..."}).
// GET  ?codigo=INC-...         → detalle para el agente.
// GET  ?email=...              → herramienta «consultar_historial_incidencias» del agente.
permitir('GET', 'POST');
exigirToken();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (($codigo = parametro('codigo')) !== null) {
        responder(['ok' => true] + Integracion::incidenciaParaN8n($codigo));
    }
    responder(['ok' => true] + Integracion::historialIncidencias(parametro('email') ?? ''));
}
if (parametro('accion') === 'notificada') {
    $codigo = cuerpoJson()['codigo'] ?? null;
    if (!is_string($codigo)) {
        throw new ErrorNegocio('Falta el código de la incidencia.', 400, 'validacion');
    }
    Integracion::marcarNotificada($codigo);
    responder(['ok' => true]);
}
procesarEvento('incidencia', cuerpoJson(), [Integracion::class, 'recibirIncidencia']);
