<?php
declare(strict_types=1);

/**
 * Cambios de estado hechos en el back-office (por una persona o por una propuesta aprobada del agente).
 * Se guardan aquí primero y luego se llevan a la tienda a través de n8n. Si n8n o la tienda no
 * responden, la sincronización queda «pendiente»/«error» y se puede reintentar desde el panel.
 */
final class Sincronizacion
{
    public static function cambiarEstado(string $codigo, string $nuevo, ?string $motivo, string $origen, string $actor): array
    {
        if (in_array($nuevo, Reglas::SOLO_TIENDA, true)) {
            throw new ErrorNegocio('«Pagado» solo lo pone la pasarela de la tienda.');
        }
        $syncId = Db::transaccion(function () use ($codigo, $nuevo, $motivo, $origen, $actor) {
            $p = Db::uno('SELECT id, estado FROM pedidos WHERE codigo = ?', [$codigo]);
            if ($p === null) {
                throw new ErrorNegocio('Pedido no encontrado.', 404);
            }
            if (!Reglas::puede($p['estado'], $nuevo)) {
                $de = Reglas::ESTADOS[$p['estado']] ?? $p['estado'];
                $a = Reglas::ESTADOS[$nuevo] ?? $nuevo;
                throw new ErrorNegocio("No se puede pasar un pedido de «{$de}» a «{$a}».");
            }
            Db::ejecutar('UPDATE pedidos SET estado = ?, actualizado_en = ? WHERE id = ?', [$nuevo, ahora(), $p['id']]);
            Db::insertar(
                'INSERT INTO historial_estados (pedido_id, de, a, origen, actor, motivo, creado_en) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$p['id'], $p['estado'], $nuevo, $origen, $actor, $motivo, ahora()]
            );
            return Db::insertar(
                'INSERT INTO sincronizaciones (pedido_id, estado, resultado, intentos, creado_en, actualizado_en) VALUES (?, ?, ?, ?, ?, ?)',
                [$p['id'], $nuevo, 'pendiente', 0, ahora(), ahora()]
            );
        });
        return self::enviar($syncId, $motivo, $actor);
    }

    /** Llama al webhook de n8n que actualiza la tienda. Fuera de la transacción: la red puede tardar. */
    public static function enviar(int $syncId, ?string $motivo = null, string $actor = 'backoffice'): array
    {
        $s = Db::uno(
            'SELECT s.id, s.estado, s.intentos, p.codigo FROM sincronizaciones s JOIN pedidos p ON p.id = s.pedido_id WHERE s.id = ?',
            [$syncId]
        );
        if ($s === null) {
            throw new ErrorNegocio('Sincronización no encontrada.', 404);
        }
        $url = Config::get('N8N_WEBHOOK_ESTADO');
        $error = null;
        if ($url === '') {
            $error = 'Falta configurar N8N_WEBHOOK_ESTADO.';
        } else {
            $cuerpo = [
                'event_id'    => 'bo-sync-' . $s['id'] . '-' . ((int) $s['intentos'] + 1),
                'event'       => 'backoffice.status_change_requested',
                'occurred_at' => date('c'),
                'source'      => 'backoffice',
                'data'        => ['codigo' => $s['codigo'], 'estado' => $s['estado'], 'motivo' => $motivo, 'actor' => $actor],
            ];
            [$http, $respuesta, $errorRed] = Http::postJson($url, $cuerpo, ['X-Noctiluz-Token: ' . Config::get('N8N_WEBHOOK_TOKEN')], 25);
            if ($errorRed !== null) {
                $error = 'n8n no responde (' . $errorRed . '). Si estaba dormido, reintenta en un minuto.';
            } elseif ($http >= 300 || !($respuesta['ok'] ?? false)) {
                $error = mb_substr((string) ($respuesta['error'] ?? "HTTP {$http}"), 0, 280);
            }
        }
        Db::ejecutar(
            'UPDATE sincronizaciones SET resultado = ?, intentos = intentos + 1, ultimo_error = ?, actualizado_en = ? WHERE id = ?',
            [$error === null ? 'ok' : 'error', $error, ahora(), $s['id']]
        );
        return ['sincronizacion_id' => (int) $s['id'], 'codigo' => $s['codigo'], 'estado' => $s['estado'], 'sincronizado' => $error === null, 'error' => $error];
    }

    public static function pendientes(): array
    {
        return Db::todos(
            "SELECT s.id, s.estado, s.resultado, s.intentos, s.ultimo_error, s.actualizado_en, p.codigo
               FROM sincronizaciones s JOIN pedidos p ON p.id = s.pedido_id
              WHERE s.resultado <> 'ok' ORDER BY s.id DESC LIMIT 50"
        );
    }
}

/** Cliente HTTP mínimo con cURL. */
final class Http
{
    /** @return array{0:int,1:array,2:?string} código HTTP, JSON decodificado y error de red */
    public static function postJson(string $url, array $cuerpo, array $cabeceras = [], int $timeout = 15): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json'], $cabeceras),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => $timeout,
        ]);
        $crudo = curl_exec($ch);
        $error = $crudo === false ? curl_error($ch) : null;
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $json = is_string($crudo) ? json_decode($crudo, true) : null;
        return [$http, is_array($json) ? $json : [], $error];
    }
}
