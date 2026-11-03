<?php
declare(strict_types=1);

/**
 * Propuestas del agente de IA (n8n + Gemini). El agente SOLO propone: guarda aquí una salida
 * estructurada y una persona la aprueba o rechaza desde Telegram o desde el panel.
 * Nada se ejecuta sin esa aprobación.
 */
final class Agente
{
    public const CLASIFICACIONES = ['envio', 'producto', 'devolucion', 'pago', 'consulta', 'otro'];
    public const PRIORIDADES = ['baja', 'media', 'alta'];

    /** Valida la salida estructurada del modelo. Si no cumple el esquema, se rechaza (no se «arregla»). */
    public static function guardarPropuesta(array $e): array
    {
        $codigo = $e['incidencia'] ?? null;
        $i = is_string($codigo) ? Db::uno('SELECT id FROM incidencias WHERE codigo = ?', [$codigo]) : null;
        if ($i === null) {
            throw new ErrorNegocio('La propuesta no apunta a una incidencia existente.', 404, 'negocio');
        }
        $p = is_array($e['propuesta'] ?? null) ? $e['propuesta'] : [];
        $errores = [];
        $resumen = is_string($p['resumen'] ?? null) ? trim($p['resumen']) : '';
        if ($resumen === '' || mb_strlen($resumen) > 400) {
            $errores[] = 'resumen (texto de 1 a 400 caracteres)';
        }
        if (!in_array($p['clasificacion'] ?? null, self::CLASIFICACIONES, true)) {
            $errores[] = 'clasificacion (' . implode('|', self::CLASIFICACIONES) . ')';
        }
        if (!in_array($p['prioridad'] ?? null, self::PRIORIDADES, true)) {
            $errores[] = 'prioridad (baja|media|alta)';
        }
        if (!isset(Reglas::ACCIONES_AGENTE[$p['accion_propuesta'] ?? ''])) {
            $errores[] = 'accion_propuesta (' . implode('|', array_keys(Reglas::ACCIONES_AGENTE)) . ')';
        }
        $justificacion = is_string($p['justificacion'] ?? null) ? trim($p['justificacion']) : '';
        if ($justificacion === '' || mb_strlen($justificacion) > 600) {
            $errores[] = 'justificacion (texto de 1 a 600 caracteres)';
        }
        $confianza = $p['confianza'] ?? null;
        if (!is_numeric($confianza) || $confianza < 0 || $confianza > 1) {
            $errores[] = 'confianza (número entre 0 y 1)';
        }
        $respuesta = $p['respuesta_cliente'] ?? null;
        if ($respuesta !== null && (!is_string($respuesta) || mb_strlen($respuesta) > 1000)) {
            $errores[] = 'respuesta_cliente (texto de hasta 1000 caracteres o null)';
        }
        if ($errores) {
            throw new ErrorNegocio('Salida del agente no válida: ' . implode(', ', $errores) . '.', 422, 'validacion');
        }
        // Aunque el modelo diga lo contrario, aquí siempre se exige aprobación humana.
        $id = Db::insertar(
            'INSERT INTO propuestas_agente (incidencia_id, resumen, clasificacion, prioridad, accion_propuesta, respuesta_cliente,
                                            justificacion, confianza, requiere_aprobacion, estado, modelo, creado_en)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, TRUE, ?, ?, ?)',
            [$i['id'], $resumen, $p['clasificacion'], $p['prioridad'], $p['accion_propuesta'], $respuesta ? trim($respuesta) : null,
             $justificacion, round((float) $confianza, 2), 'pendiente', mb_substr((string) ($e['modelo'] ?? ''), 0, 60) ?: null, ahora()]
        );
        return ['resultado' => 'ok', 'propuesta' => self::ver($id)];
    }

    public static function ver(int $id): array
    {
        $p = Db::uno(
            'SELECT a.*, i.codigo AS incidencia_codigo, i.estado AS incidencia_estado, c.telegram_chat_id, p.codigo AS pedido_codigo
               FROM propuestas_agente a JOIN incidencias i ON i.id = a.incidencia_id
               LEFT JOIN clientes c ON c.id = i.cliente_id LEFT JOIN pedidos p ON p.id = i.pedido_id
              WHERE a.id = ?',
            [$id]
        );
        if ($p === null) {
            throw new ErrorNegocio('Propuesta no encontrada.', 404);
        }
        $p['id'] = (int) $p['id'];
        $p['confianza'] = (float) $p['confianza'];
        $p['accion_nombre'] = Reglas::ACCIONES_AGENTE[$p['accion_propuesta']] ?? $p['accion_propuesta'];
        return $p;
    }

    /** Aplica la decisión humana. Si ya estaba decidida no repite nada (idempotente). */
    public static function decidir(int $id, string $decision, string $por): array
    {
        if (!in_array($decision, ['aprobada', 'rechazada'], true)) {
            throw new ErrorNegocio('La decisión debe ser «aprobada» o «rechazada».', 400, 'validacion');
        }
        $p = self::ver($id);
        if ($p['estado'] !== 'pendiente') {
            return ['resultado' => 'ya_decidida', 'propuesta' => $p, 'enviar_cliente' => false];
        }
        Db::ejecutar(
            'UPDATE propuestas_agente SET estado = ?, decidido_por = ?, decidido_en = ? WHERE id = ? AND estado = ?',
            [$decision, mb_substr($por, 0, 120), ahora(), $id, 'pendiente']
        );
        if ($decision === 'rechazada') {
            return ['resultado' => 'ok', 'propuesta' => self::ver($id), 'enviar_cliente' => false];
        }

        $sync = null;
        $incId = (int) $p['incidencia_id'];
        switch ($p['accion_propuesta']) {
            case 'marcar_pedido_incidencia':
                if ($p['pedido_codigo'] !== null) {
                    $estado = Db::valor('SELECT estado FROM pedidos WHERE codigo = ?', [$p['pedido_codigo']]);
                    if (Reglas::puede((string) $estado, 'incidencia')) {
                        $sync = Sincronizacion::cambiarEstado($p['pedido_codigo'], 'incidencia', 'Propuesta IA #' . $id . ' aprobada', 'agente', $por);
                    }
                }
                Db::ejecutar("UPDATE incidencias SET estado = 'en_curso', prioridad = ? WHERE id = ?", [$p['prioridad'], $incId]);
                break;
            case 'escalar':
                Db::ejecutar("UPDATE incidencias SET estado = 'en_curso', prioridad = 'alta' WHERE id = ?", [$incId]);
                break;
            case 'cerrar_incidencia':
                Db::ejecutar("UPDATE incidencias SET estado = 'resuelta', resuelta_en = ? WHERE id = ?", [ahora(), $incId]);
                break;
            default: // responder_cliente
                Db::ejecutar("UPDATE incidencias SET estado = 'en_curso', prioridad = ? WHERE id = ?", [$p['prioridad'], $incId]);
        }
        $p = self::ver($id);
        return [
            'resultado'         => 'ok',
            'propuesta'         => $p,
            'sincronizacion'    => $sync,
            'enviar_cliente'    => $p['accion_propuesta'] !== 'escalar' && $p['respuesta_cliente'] !== null && $p['telegram_chat_id'] !== null,
            'telegram_chat_id'  => $p['telegram_chat_id'],
            'respuesta_cliente' => $p['respuesta_cliente'],
        ];
    }

    public static function listar(): array
    {
        return Db::todos(
            'SELECT a.id, a.resumen, a.clasificacion, a.prioridad, a.accion_propuesta, a.confianza, a.estado, a.decidido_por,
                    a.decidido_en, a.creado_en, i.codigo AS incidencia
               FROM propuestas_agente a JOIN incidencias i ON i.id = a.incidencia_id ORDER BY a.id DESC LIMIT 100'
        );
    }
}
