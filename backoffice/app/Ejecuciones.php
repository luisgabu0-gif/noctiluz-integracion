<?php
declare(strict_types=1);

/** Registro de ejecuciones de la integración: qué pasó, en qué paso falló y por qué. */
final class Ejecuciones
{
    public const RESULTADOS = ['ok', 'error', 'duplicado', 'ignorado'];
    public const TIPOS_ERROR = ['validacion', 'autenticacion', 'negocio', 'transitorio', 'desconocido'];

    public static function registrar(string $flujo, string $escenario, ?string $eventId, string $resultado, ?string $paso = null,
                                     ?string $tipoError = null, ?string $causa = null, $entrada = null, ?int $duracionMs = null): void
    {
        if (!in_array($resultado, self::RESULTADOS, true)) {
            $resultado = 'error';
        }
        if ($tipoError !== null && !in_array($tipoError, self::TIPOS_ERROR, true)) {
            $tipoError = 'desconocido';
        }
        $json = $entrada === null ? null : mb_substr(is_string($entrada) ? $entrada : json_encode($entrada, JSON_UNESCAPED_UNICODE), 0, 4000);
        Db::insertar(
            'INSERT INTO ejecuciones (flujo, escenario, event_id, resultado, paso_fallido, tipo_error, causa, datos_entrada, duracion_ms, creado_en)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [mb_substr($flujo, 0, 60), mb_substr($escenario, 0, 30), $eventId ? mb_substr($eventId, 0, 40) : null, $resultado,
             $paso ? mb_substr($paso, 0, 80) : null, $tipoError, $causa ? mb_substr($causa, 0, 300) : null, $json, $duracionMs, ahora()]
        );
    }

    /** Lo que manda el Error Trigger de n8n (o cualquier flujo) por POST. */
    public static function desdeN8n(array $e): void
    {
        $flujo = is_string($e['flujo'] ?? null) ? $e['flujo'] : '';
        if ($flujo === '') {
            throw new ErrorNegocio('Falta el campo flujo.', 400, 'validacion');
        }
        self::registrar(
            $flujo,
            is_string($e['escenario'] ?? null) ? $e['escenario'] : 'otro',
            is_string($e['event_id'] ?? null) ? $e['event_id'] : null,
            is_string($e['resultado'] ?? null) ? $e['resultado'] : 'error',
            is_string($e['paso_fallido'] ?? null) ? $e['paso_fallido'] : null,
            is_string($e['tipo_error'] ?? null) ? $e['tipo_error'] : null,
            is_string($e['causa'] ?? null) ? $e['causa'] : null,
            $e['datos_entrada'] ?? null,
            is_numeric($e['duracion_ms'] ?? null) ? (int) $e['duracion_ms'] : null
        );
    }

    public static function listar(int $limite = 100): array
    {
        return Db::todos('SELECT * FROM ejecuciones ORDER BY id DESC LIMIT ' . max(1, min($limite, 500)));
    }
}
