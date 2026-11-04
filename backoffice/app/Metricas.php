<?php
declare(strict_types=1);

/**
 * Cuadro de mando. Todas las cifras salen de las tablas reales del back-office (nada inventado):
 * si no ha pasado nada, la métrica sale a 0 o «—».
 */
final class Metricas
{
    public const SLA_HORAS = 48;

    public static function calcular(int $dias = 30): array
    {
        $desde = date('Y-m-d H:i:s', strtotime("-{$dias} days"));

        // 1. Pedidos integrados; el importe y el ticket medio cuentan solo lo pagado (un pedido creado aún no es una venta)
        $pedidos = (int) Db::valor('SELECT COUNT(*) FROM pedidos WHERE recibido_en >= ?', [$desde]);
        $ped = Db::uno(
            "SELECT COUNT(*) AS n, COALESCE(SUM(total), 0) AS importe FROM pedidos WHERE recibido_en >= ? AND estado NOT IN ('creado', 'cancelado')",
            [$desde]
        );
        $pagados = (int) $ped['n'];

        // 2. Tasa de éxito de la integración (ok / ok+error; los duplicados no cuentan)
        $ej = Db::uno(
            "SELECT SUM(CASE WHEN resultado = 'ok' THEN 1 ELSE 0 END) AS ok,
                    SUM(CASE WHEN resultado = 'error' THEN 1 ELSE 0 END) AS error,
                    SUM(CASE WHEN resultado = 'duplicado' THEN 1 ELSE 0 END) AS duplicado
               FROM ejecuciones WHERE creado_en >= ?",
            [$desde]
        );
        $ok = (int) $ej['ok'];
        $err = (int) $ej['error'];

        // 3. Latencia de integración: desde que ocurre en la tienda hasta que está en el back-office
        $lat = [];
        foreach (Db::todos('SELECT creado_tienda, recibido_en FROM pedidos WHERE recibido_en >= ?', [$desde]) as $f) {
            $lat[] = max(0, strtotime($f['recibido_en']) - strtotime($f['creado_tienda']));
        }
        sort($lat);

        // 4. Incidencias: abiertas, por cada 100 pedidos y tiempo medio de resolución
        $abiertas = (int) Db::valor("SELECT COUNT(*) FROM incidencias WHERE estado <> 'resuelta'");
        $incPeriodo = (int) Db::valor('SELECT COUNT(*) FROM incidencias WHERE creado_en >= ?', [$desde]);
        $res = [];
        foreach (Db::todos("SELECT creado_en, resuelta_en FROM incidencias WHERE resuelta_en IS NOT NULL AND creado_en >= ?", [$desde]) as $f) {
            $res[] = max(0, strtotime($f['resuelta_en']) - strtotime($f['creado_en']));
        }

        // Primera respuesta: la primera propuesta aprobada por una persona. SLA: resueltas en 48 h o menos.
        $primera = [];
        foreach (Db::todos(
            "SELECT i.creado_en, MIN(a.decidido_en) AS respondida FROM incidencias i JOIN propuestas_agente a ON a.incidencia_id = i.id
              WHERE a.estado = 'aprobada' AND i.creado_en >= ? GROUP BY i.id, i.creado_en",
            [$desde]
        ) as $f) {
            $primera[] = max(0, strtotime($f['respondida']) - strtotime($f['creado_en']));
        }
        $enPlazo = count(array_filter($res, fn ($s) => $s <= self::SLA_HORAS * 3600));

        // 5. Agente: propuestas y % aprobadas por una persona
        $ag = Db::uno(
            "SELECT COUNT(*) AS n,
                    SUM(CASE WHEN estado = 'aprobada' THEN 1 ELSE 0 END) AS aprobadas,
                    SUM(CASE WHEN estado = 'rechazada' THEN 1 ELSE 0 END) AS rechazadas
               FROM propuestas_agente WHERE creado_en >= ?",
            [$desde]
        );
        $decididas = (int) $ag['aprobadas'] + (int) $ag['rechazadas'];

        // 6. Sincronizaciones con la tienda sin confirmar
        $syncPend = (int) Db::valor("SELECT COUNT(*) FROM sincronizaciones WHERE resultado <> 'ok'");

        return [
            'dias'                    => $dias,
            'pedidos_integrados'      => $pedidos,
            'importe_integrado'       => round((float) $ped['importe'], 2),
            'pedidos_pagados'         => $pagados,
            'ticket_medio'            => $pagados ? round((float) $ped['importe'] / $pagados, 2) : null,
            'ejecuciones_ok'          => $ok,
            'ejecuciones_error'       => $err,
            'duplicados_evitados'     => (int) $ej['duplicado'],
            'tasa_exito'              => ($ok + $err) ? round(100 * $ok / ($ok + $err), 1) : null,
            'latencia_mediana_s'      => $lat ? $lat[intdiv(count($lat), 2)] : null,
            'incidencias_abiertas'    => $abiertas,
            'incidencias_por_100'     => $pedidos ? round(100 * $incPeriodo / $pedidos, 1) : null,
            'resolucion_media_h'      => $res ? round(array_sum($res) / count($res) / 3600, 1) : null,
            'primera_respuesta_h'     => $primera ? round(array_sum($primera) / count($primera) / 3600, 1) : null,
            'cumplimiento_sla'        => $res ? round(100 * $enPlazo / count($res), 1) : null,
            'propuestas_ia'           => (int) $ag['n'],
            'propuestas_pendientes'   => (int) $ag['n'] - $decididas,
            'aprobacion_ia'           => $decididas ? round(100 * (int) $ag['aprobadas'] / $decididas, 1) : null,
            'sincronizaciones_pend'   => $syncPend,
            'serie_diaria'            => self::serieDiaria(30),
            'pedidos_pendientes'      => (int) Db::valor("SELECT COUNT(*) FROM pedidos WHERE estado IN ('pagado', 'en_preparacion')"),
            'productos_top'           => Db::todos(
                "SELECT l.sku, MAX(l.descripcion) AS descripcion, SUM(l.cantidad) AS unidades
                   FROM pedido_lineas l JOIN pedidos p ON p.id = l.pedido_id
                  WHERE p.recibido_en >= ? AND p.estado NOT IN ('creado', 'cancelado')
                  GROUP BY l.sku ORDER BY unidades DESC LIMIT 5",
                [$desde]
            ),
            'errores_por_tipo'        => Db::todos(
                "SELECT COALESCE(tipo_error, 'desconocido') AS tipo, COUNT(*) AS n FROM ejecuciones
                  WHERE resultado = 'error' AND creado_en >= ? GROUP BY COALESCE(tipo_error, 'desconocido') ORDER BY n DESC",
                [$desde]
            ),
            'pedidos_por_estado'      => Db::todos('SELECT estado, COUNT(*) AS n FROM pedidos GROUP BY estado ORDER BY n DESC'),
        ];
    }

    /**
     * El embudo y la cola de envíos viven en la tienda: se preguntan en cada visita, con datos reales.
     * Si la tienda no responde, el panel lo dice en lugar de inventar cifras.
     */
    public static function tienda(): ?array
    {
        $url = Config::get('TIENDA_URL');
        $token = Config::get('TIENDA_API_TOKEN');
        if ($url === '' || $token === '') {
            return null;
        }
        [$http, $datos] = Http::postJson(rtrim($url, '/') . '/api/integracion/metricas.php', [], ['Authorization: Bearer ' . $token], 5);
        return $http === 200 ? $datos : null;
    }

    /** Pedidos integrados y errores por día (agrupado en PHP para que valga igual en PostgreSQL y SQLite). */
    private static function serieDiaria(int $dias): array
    {
        $serie = [];
        for ($i = $dias - 1; $i >= 0; $i--) {
            $serie[date('Y-m-d', strtotime("-{$i} days"))] = ['pedidos' => 0, 'errores' => 0];
        }
        $desde = array_key_first($serie) . ' 00:00:00';
        foreach (Db::todos('SELECT recibido_en FROM pedidos WHERE recibido_en >= ?', [$desde]) as $f) {
            if (isset($serie[$d = substr($f['recibido_en'], 0, 10)])) {
                $serie[$d]['pedidos']++;
            }
        }
        foreach (Db::todos("SELECT creado_en FROM ejecuciones WHERE resultado = 'error' AND creado_en >= ?", [$desde]) as $f) {
            if (isset($serie[$d = substr($f['creado_en'], 0, 10)])) {
                $serie[$d]['errores']++;
            }
        }
        $salida = [];
        foreach ($serie as $dia => $v) {
            $salida[] = ['dia' => $dia] + $v;
        }
        return $salida;
    }
}
