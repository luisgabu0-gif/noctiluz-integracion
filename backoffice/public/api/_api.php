<?php
declare(strict_types=1);

// Arranque de la API que usa n8n: respuestas JSON, token obligatorio y registro de cada ejecución.

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

set_exception_handler(function (Throwable $e) {
    if ($e instanceof ErrorNegocio) {
        responder(['ok' => false, 'error' => $e->getMessage(), 'tipo_error' => $e->tipo], $e->http);
    }
    error_log('[BACKOFFICE] ' . $e);
    responder(['ok' => false, 'error' => Config::desarrollo() ? $e->getMessage() : 'Error interno del back-office.', 'tipo_error' => 'desconocido'], 500);
});

require dirname(__DIR__, 2) . '/app/inicio.php';

function responder(array $datos, int $codigo = 200): void
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function permitir(string ...$metodos): void
{
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', $metodos, true)) {
        header('Allow: ' . implode(', ', $metodos));
        responder(['ok' => false, 'error' => 'Método no permitido.', 'tipo_error' => 'validacion'], 405);
    }
}

/** Solo n8n conoce el token (variable BACKOFFICE_API_TOKEN en Render; credencial Header Auth en n8n). */
function exigirToken(): void
{
    $esperado = Config::get('BACKOFFICE_API_TOKEN');
    $recibido = $_SERVER['HTTP_X_API_TOKEN'] ?? '';
    if (strlen($esperado) < 20 || !is_string($recibido) || !hash_equals($esperado, $recibido)) {
        responder(['ok' => false, 'error' => 'Token no válido.', 'tipo_error' => 'autenticacion'], 401);
    }
}

function cuerpoJson(): array
{
    $crudo = file_get_contents('php://input', false, null, 0, 65536);
    $datos = json_decode($crudo ?: '', true);
    if (!is_array($datos)) {
        throw new ErrorNegocio('El cuerpo de la petición debe ser JSON.', 400, 'validacion');
    }
    return $datos;
}

function parametro(string $nombre): ?string
{
    $v = $_GET[$nombre] ?? null;
    return is_string($v) ? $v : null;
}

/**
 * Procesa un evento entrante y deja constancia en «ejecuciones» tanto si sale bien como si falla.
 * Así el cuadro de mando mide la integración con datos reales.
 */
function procesarEvento(string $escenario, array $sobre, callable $fn): void
{
    $inicio = microtime(true);
    $eventId = is_string($sobre['event_id'] ?? null) ? $sobre['event_id'] : null;
    try {
        $resultado = $fn($sobre);
    } catch (ErrorNegocio $e) {
        Ejecuciones::registrar('backoffice-api', $escenario, $eventId, 'error', 'Back-office: guardar', $e->tipo, $e->getMessage(), $sobre,
            (int) ((microtime(true) - $inicio) * 1000));
        throw $e;
    }
    Ejecuciones::registrar('backoffice-api', $escenario, $eventId, $resultado['resultado'] ?? 'ok', null, null, null, null,
        (int) ((microtime(true) - $inicio) * 1000));
    responder(['ok' => true] + $resultado, ($resultado['resultado'] ?? '') === 'ok' ? 201 : 200);
}
