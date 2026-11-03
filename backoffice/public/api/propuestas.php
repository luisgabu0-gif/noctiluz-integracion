<?php
declare(strict_types=1);
require __DIR__ . '/_api.php';

// POST                 → el agente guarda su propuesta estructurada (queda «pendiente»).
// POST ?accion=decidir → la persona aprueba o rechaza desde Telegram: {"id": 1, "decision": "aprobada", "por": "Luis"}
permitir('POST');
exigirToken();

$e = cuerpoJson();
if (parametro('accion') === 'decidir') {
    $id = filter_var($e['id'] ?? null, FILTER_VALIDATE_INT);
    if ($id === false) {
        throw new ErrorNegocio('Falta el id de la propuesta.', 400, 'validacion');
    }
    responder(['ok' => true] + Agente::decidir($id, (string) ($e['decision'] ?? ''), mb_substr((string) ($e['por'] ?? 'Telegram'), 0, 120)));
}
procesarEvento('agente', $e + ['event_id' => null], fn ($e) => Agente::guardarPropuesta($e));
