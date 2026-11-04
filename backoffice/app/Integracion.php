<?php
declare(strict_types=1);

/**
 * Entrada de eventos que llegan desde n8n. Cada evento viaja en el mismo sobre:
 *   { event_id, event, occurred_at, source, data }
 * Cada método valida el sobre, aplica idempotencia por event_id y guarda en una transacción.
 */
final class Integracion
{
    /** Valida el sobre común. Devuelve [event_id, occurred_at, data]. */
    public static function sobre(array $e, string $evento): array
    {
        $id = $e['event_id'] ?? null;
        if (!is_string($id) || !preg_match('/^[A-Za-z0-9-]{8,40}$/', $id)) {
            throw new ErrorNegocio('event_id ausente o con formato no válido.', 400, 'validacion');
        }
        if (($e['event'] ?? null) !== $evento) {
            throw new ErrorNegocio("Se esperaba el evento {$evento}.", 400, 'validacion');
        }
        if (!is_array($e['data'] ?? null)) {
            throw new ErrorNegocio('Falta el objeto data.', 400, 'validacion');
        }
        return [$id, fechaBd(is_string($e['occurred_at'] ?? null) ? $e['occurred_at'] : null), $e['data']];
    }

    /** true si el evento ya se había procesado (y lo marca como recibido si no). Va dentro de la transacción. */
    private static function yaProcesado(string $eventId, string $tipo): bool
    {
        if (Db::uno('SELECT event_id FROM eventos_recibidos WHERE event_id = ?', [$eventId]) !== null) {
            return true;
        }
        Db::ejecutar('INSERT INTO eventos_recibidos (event_id, tipo, recibido_en) VALUES (?, ?, ?)', [$eventId, $tipo, ahora()]);
        return false;
    }

    private static function texto($v, string $campo, int $max, bool $obligatorio = true): ?string
    {
        if (!is_string($v) || trim($v) === '') {
            if ($obligatorio) {
                throw new ErrorNegocio("Falta el campo {$campo}.", 400, 'validacion');
            }
            return null;
        }
        return mb_substr(trim($v), 0, $max);
    }

    private static function cliente(array $c): int
    {
        $email = mb_strtolower(self::texto($c['email'] ?? null, 'cliente.email', 120));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ErrorNegocio('cliente.email no es un email válido.', 400, 'validacion');
        }
        $nombre = self::texto($c['nombre'] ?? null, 'cliente.nombre', 100);
        $fila = Db::uno('SELECT id FROM clientes WHERE email = ?', [$email]);
        if ($fila !== null) {
            Db::ejecutar('UPDATE clientes SET nombre = ? WHERE id = ?', [$nombre, $fila['id']]);
            return (int) $fila['id'];
        }
        return Db::insertar('INSERT INTO clientes (email, nombre, creado_en) VALUES (?, ?, ?)', [$email, $nombre, ahora()]);
    }

    // ── Escenario 1: pedido integrado ────────────────────────────────────────────────────────────

    public static function recibirPedido(array $e): array
    {
        [$eventId, $ocurrido, $d] = self::sobre($e, 'order.created');
        $codigo = self::texto($d['codigo'] ?? null, 'codigo', 30);
        $estado = self::texto($d['estado'] ?? 'creado', 'estado', 20);
        if (!isset(Reglas::ESTADOS[$estado])) {
            throw new ErrorNegocio("Estado desconocido: {$estado}.", 400, 'validacion');
        }
        if (!is_numeric($d['total'] ?? null) || (float) $d['total'] < 0) {
            throw new ErrorNegocio('total debe ser un número positivo.', 400, 'validacion');
        }
        $lineas = is_array($d['lineas'] ?? null) ? $d['lineas'] : [];
        if (count($lineas) === 0) {
            throw new ErrorNegocio('El pedido no trae líneas.', 400, 'validacion');
        }

        return Db::transaccion(function () use ($eventId, $ocurrido, $d, $codigo, $estado, $lineas) {
            if (self::yaProcesado($eventId, 'order.created')) {
                return ['resultado' => 'duplicado', 'codigo' => $codigo];
            }
            if (Db::uno('SELECT id FROM pedidos WHERE codigo = ?', [$codigo]) !== null) {
                // Mismo pedido con otro event_id: no se duplica, se considera ya integrado.
                return ['resultado' => 'duplicado', 'codigo' => $codigo];
            }
            $clienteId = self::cliente(is_array($d['cliente'] ?? null) ? $d['cliente'] : []);
            $unidades = 0;
            foreach ($lineas as $l) {
                $unidades += max(0, (int) ($l['cantidad'] ?? 0));
            }
            $pedidoId = Db::insertar(
                'INSERT INTO pedidos (codigo, cliente_id, estado, total, unidades, metodo_envio, creado_tienda, recibido_en, actualizado_en)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$codigo, $clienteId, $estado, round((float) $d['total'], 2), $unidades,
                 self::texto($d['metodo_envio'] ?? null, 'metodo_envio', 30, false), $ocurrido, ahora(), ahora()]
            );
            foreach ($lineas as $l) {
                Db::insertar(
                    'INSERT INTO pedido_lineas (pedido_id, sku, descripcion, cantidad, importe) VALUES (?, ?, ?, ?, ?)',
                    [$pedidoId, self::texto($l['sku'] ?? null, 'sku', 40, false), self::texto($l['descripcion'] ?? null, 'lineas.descripcion', 160),
                     max(1, (int) ($l['cantidad'] ?? 1)), round((float) ($l['importe'] ?? 0), 2)]
                );
            }
            Db::insertar(
                'INSERT INTO historial_estados (pedido_id, de, a, origen, actor, motivo, creado_en) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$pedidoId, null, $estado, 'tienda', 'tienda', 'Pedido recibido desde la tienda', ahora()]
            );
            return ['resultado' => 'ok', 'codigo' => $codigo, 'pedido_id' => $pedidoId];
        });
    }

    // ── Escenario 2: cambio de estado que viene de la tienda ─────────────────────────────────────

    public static function recibirCambioEstado(array $e): array
    {
        [$eventId, , $d] = self::sobre($e, 'order.status_changed');
        $codigo = self::texto($d['codigo'] ?? null, 'codigo', 30);
        $nuevo = self::texto($d['a'] ?? null, 'a', 20);
        if (!isset(Reglas::ESTADOS[$nuevo])) {
            throw new ErrorNegocio("Estado desconocido: {$nuevo}.", 400, 'validacion');
        }
        return Db::transaccion(function () use ($eventId, $d, $codigo, $nuevo) {
            if (self::yaProcesado($eventId, 'order.status_changed')) {
                return ['resultado' => 'duplicado', 'codigo' => $codigo];
            }
            $p = Db::uno('SELECT id, estado FROM pedidos WHERE codigo = ?', [$codigo]);
            if ($p === null) {
                // Puede que el order.created aún no haya llegado: n8n lo reintentará.
                throw new ErrorNegocio("El pedido {$codigo} no existe en el back-office.", 409, 'transitorio');
            }
            if ($p['estado'] === $nuevo) {
                // Eco de un cambio hecho aquí mismo y llevado a la tienda: no se vuelve a aplicar (evita bucles).
                return ['resultado' => 'ignorado', 'codigo' => $codigo, 'motivo' => 'El pedido ya estaba en ese estado'];
            }
            Db::ejecutar('UPDATE pedidos SET estado = ?, actualizado_en = ? WHERE id = ?', [$nuevo, ahora(), $p['id']]);
            Db::insertar(
                'INSERT INTO historial_estados (pedido_id, de, a, origen, actor, motivo, creado_en) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$p['id'], $p['estado'], $nuevo, 'tienda', self::texto($d['actor'] ?? null, 'actor', 120, false) ?? 'tienda',
                 self::texto($d['motivo'] ?? null, 'motivo', 200, false), ahora()]
            );
            return ['resultado' => 'ok', 'codigo' => $codigo, 'de' => $p['estado'], 'a' => $nuevo];
        });
    }

    // ── Escenario 3: incidencia ──────────────────────────────────────────────────────────────────

    public static function recibirIncidencia(array $e): array
    {
        [$eventId, $ocurrido, $d] = self::sobre($e, 'support.requested');
        $codigo = self::texto($d['codigo'] ?? null, 'codigo', 30);
        $motivo = self::texto($d['motivo'] ?? null, 'motivo', 30);
        if (!isset(Reglas::MOTIVOS[$motivo])) {
            throw new ErrorNegocio("Motivo desconocido: {$motivo}.", 400, 'validacion');
        }
        return Db::transaccion(function () use ($eventId, $ocurrido, $d, $codigo, $motivo) {
            if (self::yaProcesado($eventId, 'support.requested')) {
                return ['resultado' => 'duplicado'] + self::incidenciaParaN8n($codigo);
            }
            $clienteId = self::cliente(is_array($d['cliente'] ?? null) ? $d['cliente'] : []);
            $pedidoId = null;
            $codPedido = self::texto($d['pedido'] ?? null, 'pedido', 30, false);
            if ($codPedido !== null) {
                $p = Db::uno('SELECT id FROM pedidos WHERE codigo = ?', [$codPedido]);
                $pedidoId = $p === null ? null : (int) $p['id'];
            }
            Db::insertar(
                'INSERT INTO incidencias (codigo, pedido_id, cliente_id, motivo, mensaje, prioridad, estado, creado_en)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$codigo, $pedidoId, $clienteId, $motivo, self::texto($d['mensaje'] ?? null, 'mensaje', 1000, false),
                 Reglas::PRIORIDAD_POR_MOTIVO[$motivo], 'abierta', $ocurrido]
            );
            return ['resultado' => 'ok'] + self::incidenciaParaN8n($codigo);
        });
    }

    /** Lo que n8n necesita para avisar por Telegram y pasarle contexto al agente. */
    public static function incidenciaParaN8n(string $codigo): array
    {
        $i = Db::uno(
            'SELECT i.id, i.codigo, i.motivo, i.mensaje, i.prioridad, i.estado, i.creado_en, c.nombre AS cliente_nombre,
                    c.email AS cliente_email, c.telegram_chat_id, p.codigo AS pedido_codigo, p.estado AS pedido_estado
               FROM incidencias i LEFT JOIN clientes c ON c.id = i.cliente_id LEFT JOIN pedidos p ON p.id = i.pedido_id
              WHERE i.codigo = ?',
            [$codigo]
        );
        if ($i === null) {
            throw new ErrorNegocio("Incidencia {$codigo} no encontrada.", 404, 'negocio');
        }
        $i['id'] = (int) $i['id'];
        $i['motivo_nombre'] = Reglas::MOTIVOS[$i['motivo']] ?? $i['motivo'];
        return ['incidencia' => $i];
    }

    public static function marcarNotificada(string $codigo): void
    {
        Db::ejecutar('UPDATE incidencias SET notificado_telegram = TRUE WHERE codigo = ?', [$codigo]);
    }

    // ── Herramientas de consulta del agente ─────────────────────────────────────────────────────

    /**
     * Borra un pedido solo del back-office; la tienda conserva el suyo.
     * Con incidencias no se borra, para no perder su relación con el pedido.
     */
    public static function eliminarPedido(string $codigo, string $actor): void
    {
        $p = Db::uno('SELECT id, estado, total FROM pedidos WHERE codigo = ?', [$codigo]);
        if ($p === null) {
            throw new ErrorNegocio('Ese pedido ya no existe.', 404);
        }
        $id = (int) $p['id'];
        if ((int) Db::valor('SELECT COUNT(*) FROM incidencias WHERE pedido_id = ?', [$id]) > 0) {
            throw new ErrorNegocio("El pedido {$codigo} tiene incidencias y no se puede eliminar.");
        }
        Db::transaccion(function () use ($id, $codigo, $p, $actor) {
            Db::ejecutar('DELETE FROM sincronizaciones WHERE pedido_id = ?', [$id]);
            Db::ejecutar('DELETE FROM historial_estados WHERE pedido_id = ?', [$id]);
            Db::ejecutar('DELETE FROM pedido_lineas WHERE pedido_id = ?', [$id]);
            Db::ejecutar('DELETE FROM pedidos WHERE id = ?', [$id]);
            // «ignorado» deja constancia del borrado sin alterar la tasa de éxito de la integración
            Ejecuciones::registrar('Back-office · Eliminar pedido', 'pedido', null, 'ignorado', null, null,
                "Pedido {$codigo} eliminado por {$actor}", ['codigo' => $codigo, 'estado' => $p['estado'], 'total' => $p['total']]);
        });
    }

    public static function consultarPedido(string $codigo): array
    {
        $p = Db::uno(
            'SELECT p.id, p.codigo, p.estado, p.total, p.unidades, p.metodo_envio, p.creado_tienda, c.nombre AS cliente_nombre
               FROM pedidos p JOIN clientes c ON c.id = p.cliente_id WHERE p.codigo = ?',
            [mb_strtoupper(trim($codigo))]
        );
        if ($p === null) {
            return ['encontrado' => false, 'codigo' => $codigo];
        }
        $id = (int) $p['id'];
        unset($p['id']);
        $p['total'] = (float) $p['total'];
        $p['estado_nombre'] = Reglas::ESTADOS[$p['estado']] ?? $p['estado'];
        $p['lineas'] = Db::todos('SELECT descripcion, cantidad, importe FROM pedido_lineas WHERE pedido_id = ? ORDER BY id', [$id]);
        $p['historial'] = Db::todos('SELECT de, a, origen, creado_en FROM historial_estados WHERE pedido_id = ? ORDER BY id', [$id]);
        return ['encontrado' => true, 'pedido' => $p];
    }

    public static function historialIncidencias(string $email): array
    {
        $filas = Db::todos(
            'SELECT i.codigo, i.motivo, i.prioridad, i.estado, i.creado_en, p.codigo AS pedido
               FROM incidencias i JOIN clientes c ON c.id = i.cliente_id LEFT JOIN pedidos p ON p.id = i.pedido_id
              WHERE c.email = ? ORDER BY i.creado_en DESC LIMIT 20',
            [mb_strtolower(trim($email))]
        );
        return ['email' => $email, 'total' => count($filas), 'incidencias' => $filas];
    }

    // ── Vinculación de Telegram ─────────────────────────────────────────────────────────────────

    /**
     * El cliente pulsa t.me/<bot>?start=<codigo>_<firma> en la confirmación del pedido.
     * La firma (HMAC con un secreto que comparten tienda y back-office) evita que alguien vincule un pedido ajeno.
     */
    public static function vincularTelegram(string $carga, string $chatId): array
    {
        if (!preg_match('/^(NCZ-\d{6}-[A-Z0-9]{6})_([a-f0-9]{10})$/', $carga, $m) || !preg_match('/^-?\d{1,20}$/', $chatId)) {
            throw new ErrorNegocio('Enlace de Telegram no válido.', 400, 'validacion');
        }
        $secreto = Config::get('TELEGRAM_VINCULO_SECRETO');
        if ($secreto === '' || !hash_equals(substr(hash_hmac('sha256', $m[1], $secreto), 0, 10), $m[2])) {
            throw new ErrorNegocio('El enlace de Telegram no es válido o ha caducado.', 403, 'autenticacion');
        }
        $p = Db::uno('SELECT p.codigo, c.id AS cliente_id, c.nombre FROM pedidos p JOIN clientes c ON c.id = p.cliente_id WHERE p.codigo = ?', [$m[1]]);
        if ($p === null) {
            throw new ErrorNegocio('Ese pedido todavía no ha llegado al back-office; inténtalo en un minuto.', 409, 'transitorio');
        }
        Db::ejecutar('UPDATE clientes SET telegram_chat_id = ? WHERE id = ?', [$chatId, $p['cliente_id']]);
        return ['resultado' => 'ok', 'codigo' => $p['codigo'], 'nombre' => $p['nombre']];
    }
}
