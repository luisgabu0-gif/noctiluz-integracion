<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/inicio.php';
require dirname(__DIR__) . '/app/Panel.php';

Panel::iniciarSesion();
$u = Panel::exigirUsuario();
$actor = $u['nombre'] . ' <' . $u['email'] . '>';

// ── Acciones (POST + CSRF). Tras cada una se redirige para no repetirla al recargar. ──────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Panel::comprobarCsrf();
    $volver = $_POST['volver'] ?? 'panel.php';
    $volver = is_string($volver) && str_starts_with($volver, 'panel.php') ? $volver : 'panel.php';
    try {
        switch ($_POST['accion'] ?? '') {
            case 'cambiar_estado':
                $motivo = trim((string) ($_POST['motivo'] ?? '')) ?: null;
                $r = Sincronizacion::cambiarEstado((string) ($_POST['codigo'] ?? ''), (string) ($_POST['estado'] ?? ''), $motivo ? mb_substr($motivo, 0, 200) : null, 'backoffice', $actor);
                $r['sincronizado']
                    ? Panel::aviso("Estado cambiado y sincronizado con la tienda ({$r['codigo']}).")
                    : Panel::aviso("Estado cambiado aquí, pero la tienda aún no lo tiene: {$r['error']}", 'error');
                break;
            case 'reintentar':
                $r = Sincronizacion::enviar((int) ($_POST['id'] ?? 0), 'Reintento manual', $actor);
                $r['sincronizado'] ? Panel::aviso('Sincronización completada.') : Panel::aviso('Sigue fallando: ' . $r['error'], 'error');
                break;
            case 'decidir':
                $r = Agente::decidir((int) ($_POST['id'] ?? 0), (string) ($_POST['decision'] ?? ''), $actor);
                Panel::aviso($r['resultado'] === 'ya_decidida' ? 'Esa propuesta ya estaba decidida.' : 'Decisión guardada.'
                    . ($r['enviar_cliente'] ?? false ? ' (La respuesta al cliente por Telegram la envía n8n solo cuando se aprueba desde Telegram.)' : ''));
                break;
            case 'incidencia':
                $estado = (string) ($_POST['estado'] ?? '');
                if (!in_array($estado, ['abierta', 'en_curso', 'resuelta'], true)) {
                    throw new ErrorNegocio('Estado de incidencia no válido.');
                }
                Db::ejecutar('UPDATE incidencias SET estado = ?, resuelta_en = ? WHERE id = ?',
                    [$estado, $estado === 'resuelta' ? ahora() : null, (int) ($_POST['id'] ?? 0)]);
                Panel::aviso('Incidencia actualizada.');
                break;
            default:
                Panel::aviso('Acción desconocida.', 'error');
        }
    } catch (ErrorNegocio $e) {
        Panel::aviso($e->getMessage(), 'error');
    }
    header('Location: ' . $volver);
    exit;
}

$vista = $_GET['vista'] ?? 'resumen';

// ── Cuadro de mando ───────────────────────────────────────────────────────────────────────────
if ($vista === 'resumen') {
    $dias = (int) ($_GET['dias'] ?? 30);
    $dias = in_array($dias, [7, 30, 90], true) ? $dias : 30;
    $m = Metricas::calcular($dias);
    $tienda = Metricas::tienda();
    Panel::cabecera('Cuadro de mando', 'resumen');
    $pct = fn ($v) => $v === null ? '—' : number_format((float) $v, 1, ',', '.') . ' %';
    ?>
    <div class="titulo-fila">
      <h1>Cuadro de mando</h1>
      <nav class="filtro" aria-label="Periodo">
        <?php foreach ([7, 30, 90] as $d): ?>
          <a href="panel.php?vista=resumen&dias=<?= $d ?>"<?= $d === $dias ? ' aria-current="page"' : '' ?>><?= $d ?> días</a>
        <?php endforeach; ?>
      </nav>
    </div>
    <section class="kpis">
      <article class="kpi"><h2>Pedidos integrados</h2><p class="cifra"><?= $m['pedidos_integrados'] ?></p><p class="suave"><?= $m['pedidos_pagados'] ?> pagados · <?= eur($m['importe_integrado']) ?> · ticket medio <?= eur($m['ticket_medio']) ?></p></article>
      <article class="kpi"><h2>Éxito de la integración</h2><p class="cifra"><?= $pct($m['tasa_exito']) ?></p><p class="suave"><?= $m['ejecuciones_ok'] ?> ok · <?= $m['ejecuciones_error'] ?> con error · <?= $m['duplicados_evitados'] ?> duplicados evitados</p></article>
      <article class="kpi"><h2>Latencia tienda → back-office</h2><p class="cifra"><?= $m['latencia_mediana_s'] === null ? '—' : $m['latencia_mediana_s'] . ' s' ?></p><p class="suave">mediana, incluye reintentos</p></article>
      <article class="kpi"><h2>Incidencias abiertas</h2><p class="cifra"><?= $m['incidencias_abiertas'] ?></p><p class="suave"><?= $m['incidencias_por_100'] === null ? '—' : number_format($m['incidencias_por_100'], 1, ',', '.') ?> por cada 100 pedidos</p></article>
      <article class="kpi"><h2>Tiempo medio de resolución</h2><p class="cifra"><?= $m['resolucion_media_h'] === null ? '—' : number_format($m['resolucion_media_h'], 1, ',', '.') . ' h' ?></p><p class="suave">primera respuesta: <?= $m['primera_respuesta_h'] === null ? '—' : number_format($m['primera_respuesta_h'], 1, ',', '.') . ' h' ?> · en plazo de <?= Metricas::SLA_HORAS ?> h: <?= $pct($m['cumplimiento_sla']) ?></p></article>
      <article class="kpi"><h2>Propuestas del agente aprobadas</h2><p class="cifra"><?= $pct($m['aprobacion_ia']) ?></p><p class="suave"><?= $m['propuestas_ia'] ?> propuestas · <?= $m['propuestas_pendientes'] ?> pendientes · todas con revisión humana</p></article>
      <article class="kpi<?= $m['sincronizaciones_pend'] ? ' kpi-alerta' : '' ?>"><h2>Sincronizaciones pendientes</h2><p class="cifra"><?= $m['sincronizaciones_pend'] ?></p><p class="suave">cambios del back-office aún no confirmados por la tienda</p></article>
    </section>
    <section class="tarjeta">
      <h2>Pedidos integrados y errores por día (últimos 30 días)</h2>
      <div class="grafico"><canvas id="grafico-diario" aria-label="Gráfico de pedidos integrados y errores por día" role="img"></canvas></div>
    </section>
    <div class="dos-columnas">
      <section class="tarjeta"><h2>Pedidos por estado</h2>
        <p class="suave"><?= $m['pedidos_pendientes'] ?> pendientes (pagados o en preparación)</p>
        <table><thead><tr><th>Estado</th><th class="num">Pedidos</th></tr></thead><tbody>
        <?php foreach ($m['pedidos_por_estado'] as $f): ?><tr><td><?= etiquetaEstado($f['estado']) ?></td><td class="num"><?= (int) $f['n'] ?></td></tr><?php endforeach; ?>
        <?php if (!$m['pedidos_por_estado']): ?><tr><td colspan="2" class="suave">Aún no hay pedidos.</td></tr><?php endif; ?>
        </tbody></table>
      </section>
      <section class="tarjeta"><h2>Errores por tipo (<?= $dias ?> días)</h2>
        <table><thead><tr><th>Tipo</th><th class="num">Errores</th></tr></thead><tbody>
        <?php foreach ($m['errores_por_tipo'] as $f): ?><tr><td><?= e($f['tipo']) ?></td><td class="num"><?= (int) $f['n'] ?></td></tr><?php endforeach; ?>
        <?php if (!$m['errores_por_tipo']): ?><tr><td colspan="2" class="suave">Sin errores en el periodo.</td></tr><?php endif; ?>
        </tbody></table>
      </section>
    </div>
    <div class="dos-columnas">
      <section class="tarjeta"><h2>Productos más solicitados (<?= $dias ?> días)</h2>
        <table><thead><tr><th>Producto</th><th class="num">Unidades</th></tr></thead><tbody>
        <?php foreach ($m['productos_top'] as $f): ?><tr><td><?= e($f['descripcion']) ?><br><small class="suave"><?= e($f['sku']) ?></small></td><td class="num"><?= (int) $f['unidades'] ?></td></tr><?php endforeach; ?>
        <?php if (!$m['productos_top']): ?><tr><td colspan="2" class="suave">Aún no hay pedidos pagados.</td></tr><?php endif; ?>
        </tbody></table>
      </section>
      <section class="tarjeta"><h2>Embudo de conversión de la tienda</h2>
        <?php if ($tienda === null): ?><p class="suave">La tienda no ha respondido: revisa TIENDA_URL y TIENDA_API_TOKEN, o recarga en un momento.</p>
        <?php else: $base = max(1, (int) ($tienda['embudo'][0]['sesiones'] ?? 0)); ?>
          <table><thead><tr><th>Paso</th><th class="num">Sesiones</th><th class="num">%</th></tr></thead><tbody>
          <?php foreach ($tienda['embudo'] as $f): ?><tr><td><?= e($f['etiqueta']) ?></td><td class="num"><?= (int) $f['sesiones'] ?></td><td class="num"><?= $pct(100 * (int) $f['sesiones'] / $base) ?></td></tr><?php endforeach; ?>
          </tbody></table>
          <p class="suave">Cola de envíos a n8n: <?= (int) $tienda['cola']['enviado'] ?> enviados · <?= (int) $tienda['cola']['pendiente'] ?> pendientes · <?= (int) $tienda['cola']['fallido'] ?> fallidos</p>
        <?php endif; ?>
      </section>
    </div>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
    <script>
      const serie = <?= json_encode($m['serie_diaria']) ?>;
      new Chart(document.getElementById('grafico-diario'), {
        type: 'bar',
        data: {
          labels: serie.map(d => d.dia.slice(8, 10) + '/' + d.dia.slice(5, 7)),
          datasets: [
            { label: 'Pedidos integrados', data: serie.map(d => d.pedidos), backgroundColor: '#3fd0c9' },
            { label: 'Errores de integración', data: serie.map(d => d.errores), backgroundColor: '#ff7a6b' }
          ]
        },
        options: {
          maintainAspectRatio: false,
          scales: { y: { beginAtZero: true, ticks: { precision: 0, color: '#a9b4d0' }, grid: { color: '#26304d' } }, x: { ticks: { color: '#a9b4d0' }, grid: { display: false } } },
          plugins: { legend: { labels: { color: '#e6ebff' } } }
        }
      });
    </script>
    <?php
    Panel::pie();
    exit;
}

// ── Pedidos ───────────────────────────────────────────────────────────────────────────────────
if ($vista === 'pedidos') {
    $filas = Db::todos(
        'SELECT p.codigo, p.estado, p.total, p.unidades, p.recibido_en, c.nombre FROM pedidos p JOIN clientes c ON c.id = p.cliente_id
          ORDER BY p.recibido_en DESC, p.id DESC LIMIT 200'
    );
    $pendientes = Sincronizacion::pendientes();
    Panel::cabecera('Pedidos', 'pedidos');
    ?>
    <h1>Pedidos</h1>
    <p class="suave">Llegan solos desde la tienda a través de n8n (escenario 1). Pulsa un código para cambiar su estado (escenario 2).</p>
    <?php if ($pendientes): ?>
      <section class="tarjeta kpi-alerta"><h2>Sincronizaciones sin confirmar</h2>
        <table><thead><tr><th>Pedido</th><th>Estado</th><th>Intentos</th><th>Último error</th><th></th></tr></thead><tbody>
        <?php foreach ($pendientes as $s): ?>
          <tr><td><?= e($s['codigo']) ?></td><td><?= etiquetaEstado($s['estado']) ?></td><td class="num"><?= (int) $s['intentos'] ?></td><td><?= e($s['ultimo_error']) ?></td>
            <td><form method="post"><?= Panel::csrf() ?><input type="hidden" name="accion" value="reintentar"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>"><input type="hidden" name="volver" value="panel.php?vista=pedidos"><button>Reintentar</button></form></td></tr>
        <?php endforeach; ?>
        </tbody></table>
      </section>
    <?php endif; ?>
    <section class="tarjeta"><div class="tabla-scroll"><table>
      <thead><tr><th>Código</th><th>Cliente</th><th>Estado</th><th class="num">Uds.</th><th class="num">Total</th><th>Recibido</th></tr></thead><tbody>
      <?php foreach ($filas as $f): ?>
        <tr><td><a href="panel.php?vista=pedido&codigo=<?= urlencode($f['codigo']) ?>"><?= e($f['codigo']) ?></a></td><td><?= e($f['nombre']) ?></td>
          <td><?= etiquetaEstado($f['estado']) ?></td><td class="num"><?= (int) $f['unidades'] ?></td><td class="num"><?= eur($f['total']) ?></td><td><?= fecha($f['recibido_en']) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$filas): ?><tr><td colspan="6" class="suave">Aún no ha llegado ningún pedido. Haz uno en noctiluz.onl.</td></tr><?php endif; ?>
    </tbody></table></div></section>
    <?php
    Panel::pie();
    exit;
}

if ($vista === 'pedido') {
    $codigo = (string) ($_GET['codigo'] ?? '');
    $info = Integracion::consultarPedido($codigo);
    Panel::cabecera('Pedido ' . $codigo, 'pedidos');
    if (!$info['encontrado']) {
        echo '<p class="aviso aviso-error">Pedido no encontrado.</p>';
        Panel::pie();
        exit;
    }
    $p = $info['pedido'];
    $opciones = Reglas::transicionesDesdePanel($p['estado']);
    ?>
    <p><a href="panel.php?vista=pedidos">← Pedidos</a></p>
    <h1><?= e($p['codigo']) ?> <?= etiquetaEstado($p['estado']) ?></h1>
    <div class="dos-columnas">
      <section class="tarjeta"><h2>Detalle</h2>
        <p><?= e($p['cliente_nombre']) ?> · <?= eur($p['total']) ?> · <?= (int) $p['unidades'] ?> uds. · envío <?= e($p['metodo_envio'] ?? '—') ?></p>
        <table><thead><tr><th>Producto</th><th class="num">Cant.</th><th class="num">Importe</th></tr></thead><tbody>
        <?php foreach ($p['lineas'] as $l): ?><tr><td><?= e($l['descripcion']) ?></td><td class="num"><?= (int) $l['cantidad'] ?></td><td class="num"><?= eur($l['importe']) ?></td></tr><?php endforeach; ?>
        </tbody></table>
      </section>
      <section class="tarjeta"><h2>Cambiar estado</h2>
        <?php if ($opciones): ?>
          <form method="post" class="formulario">
            <?= Panel::csrf() ?><input type="hidden" name="accion" value="cambiar_estado"><input type="hidden" name="codigo" value="<?= e($p['codigo']) ?>">
            <input type="hidden" name="volver" value="panel.php?vista=pedido&codigo=<?= e(urlencode($p['codigo'])) ?>">
            <label>Nuevo estado <select name="estado"><?php foreach ($opciones as $o): ?><option value="<?= e($o) ?>"><?= e(Reglas::ESTADOS[$o]) ?></option><?php endforeach; ?></select></label>
            <label>Motivo (opcional) <input name="motivo" maxlength="200"></label>
            <button type="submit">Guardar y sincronizar con la tienda</button>
          </form>
          <p class="suave">Se guarda aquí y n8n lo lleva a la tienda. Si n8n está dormido (Render gratis), queda pendiente y se puede reintentar.</p>
        <?php else: ?><p class="suave">Desde «<?= e($p['estado_nombre']) ?>» no hay más cambios posibles.</p><?php endif; ?>
      </section>
    </div>
    <section class="tarjeta"><h2>Historial</h2>
      <table><thead><tr><th>Fecha</th><th>De</th><th>A</th><th>Origen</th></tr></thead><tbody>
      <?php foreach ($p['historial'] as $h): ?><tr><td><?= fecha($h['creado_en']) ?></td><td><?= $h['de'] ? etiquetaEstado($h['de']) : '—' ?></td><td><?= etiquetaEstado($h['a']) ?></td><td><?= e($h['origen']) ?></td></tr><?php endforeach; ?>
      </tbody></table>
    </section>
    <?php
    Panel::pie();
    exit;
}

// ── Incidencias ───────────────────────────────────────────────────────────────────────────────
if ($vista === 'incidencias') {
    $filas = Db::todos(
        "SELECT i.id, i.codigo, i.motivo, i.mensaje, i.prioridad, i.estado, i.notificado_telegram, i.creado_en, c.nombre, p.codigo AS pedido
           FROM incidencias i LEFT JOIN clientes c ON c.id = i.cliente_id LEFT JOIN pedidos p ON p.id = i.pedido_id
          ORDER BY CASE i.estado WHEN 'abierta' THEN 0 WHEN 'en_curso' THEN 1 ELSE 2 END, i.creado_en DESC LIMIT 200"
    );
    Panel::cabecera('Incidencias', 'incidencias');
    ?>
    <h1>Incidencias</h1>
    <p class="suave">Llegan desde el formulario de soporte de la tienda (escenario 3). n8n avisa al equipo y al cliente por Telegram y el agente propone qué hacer.</p>
    <section class="tarjeta"><div class="tabla-scroll"><table>
      <thead><tr><th>Código</th><th>Cliente</th><th>Motivo</th><th>Prioridad</th><th>Pedido</th><th>Telegram</th><th>Estado</th></tr></thead><tbody>
      <?php foreach ($filas as $f): ?>
        <tr><td><?= e($f['codigo']) ?><br><small class="suave"><?= fecha($f['creado_en']) ?></small></td><td><?= e($f['nombre'] ?? '—') ?></td>
          <td title="<?= e($f['mensaje']) ?>"><?= e(Reglas::MOTIVOS[$f['motivo']] ?? $f['motivo']) ?></td>
          <td><span class="prioridad prioridad-<?= e($f['prioridad']) ?>"><?= e($f['prioridad']) ?></span></td>
          <td><?= $f['pedido'] ? '<a href="panel.php?vista=pedido&codigo=' . urlencode($f['pedido']) . '">' . e($f['pedido']) . '</a>' : '—' ?></td>
          <td><?= in_array($f['notificado_telegram'], [true, 1, '1', 't'], true) ? '✓ avisado' : '—' ?></td>
          <td><form method="post" class="en-linea"><?= Panel::csrf() ?><input type="hidden" name="accion" value="incidencia"><input type="hidden" name="id" value="<?= (int) $f['id'] ?>"><input type="hidden" name="volver" value="panel.php?vista=incidencias">
            <select name="estado" aria-label="Estado de <?= e($f['codigo']) ?>"><?php foreach (['abierta' => 'Abierta', 'en_curso' => 'En curso', 'resuelta' => 'Resuelta'] as $k => $t): ?><option value="<?= $k ?>"<?= $k === $f['estado'] ? ' selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select>
            <button>Guardar</button></form></td></tr>
      <?php endforeach; ?>
      <?php if (!$filas): ?><tr><td colspan="7" class="suave">Sin incidencias.</td></tr><?php endif; ?>
    </tbody></table></div></section>
    <?php
    Panel::pie();
    exit;
}

// ── Agente IA ─────────────────────────────────────────────────────────────────────────────────
if ($vista === 'agente') {
    $filas = Agente::listar();
    Panel::cabecera('Agente IA', 'agente');
    ?>
    <h1>Propuestas del agente</h1>
    <p class="suave">El agente consulta el pedido y el historial del cliente, y devuelve una propuesta estructurada. <strong>Nunca actúa solo</strong>: una persona la aprueba o rechaza (en Telegram o aquí).</p>
    <section class="tarjeta"><div class="tabla-scroll"><table>
      <thead><tr><th>#</th><th>Incidencia</th><th>Resumen</th><th>Clasif.</th><th>Prioridad</th><th>Acción propuesta</th><th class="num">Confianza</th><th>Estado</th></tr></thead><tbody>
      <?php foreach ($filas as $f): ?>
        <tr><td><?= (int) $f['id'] ?></td><td><?= e($f['incidencia']) ?></td>
          <td><?= e($f['resumen']) ?><details><summary class="suave">Detalle</summary>
            <p><strong>Justificación:</strong> <?= e($f['justificacion']) ?></p>
            <p><strong>Riesgos:</strong> <?= e($f['riesgos'] ?? '—') ?></p>
            <p><strong>Respuesta propuesta:</strong> <?= e($f['respuesta_cliente'] ?? '—') ?></p>
            <p><strong>Datos consultados:</strong> <code><?= e($f['datos_consultados'] ?? '—') ?></code></p>
          </details></td><td><?= e($f['clasificacion']) ?></td>
          <td><span class="prioridad prioridad-<?= e($f['prioridad']) ?>"><?= e($f['prioridad']) ?></span></td>
          <td><?= e(Reglas::ACCIONES_AGENTE[$f['accion_propuesta']] ?? $f['accion_propuesta']) ?></td><td class="num"><?= number_format((float) $f['confianza'] * 100, 0) ?> %</td>
          <td><?php if ($f['estado'] === 'pendiente'): ?>
            <form method="post" class="en-linea"><?= Panel::csrf() ?><input type="hidden" name="accion" value="decidir"><input type="hidden" name="id" value="<?= (int) $f['id'] ?>"><input type="hidden" name="volver" value="panel.php?vista=agente">
              <button name="decision" value="aprobada">Aprobar</button> <button name="decision" value="rechazada" class="secundario">Rechazar</button></form>
          <?php else: ?><?= e($f['estado']) ?><br><small class="suave"><?= e($f['decidido_por']) ?> · <?= fecha($f['decidido_en']) ?></small><?php endif; ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$filas): ?><tr><td colspan="8" class="suave">El agente aún no ha hecho ninguna propuesta.</td></tr><?php endif; ?>
    </tbody></table></div></section>
    <?php
    Panel::pie();
    exit;
}

// ── Registro de ejecuciones ───────────────────────────────────────────────────────────────────
$filas = Ejecuciones::listar(150);
Panel::cabecera('Ejecuciones', 'ejecuciones');
?>
<h1>Registro de ejecuciones</h1>
<p class="suave">Cada evento procesado deja una fila: resultado, paso que falló, tipo de error y causa. Los errores de los flujos de n8n llegan por el flujo «NOCTILUZ · Errores».</p>
<section class="tarjeta"><div class="tabla-scroll"><table>
  <thead><tr><th>Fecha</th><th>Flujo</th><th>Escenario</th><th>Resultado</th><th>Paso fallido</th><th>Tipo</th><th>Causa</th><th class="num">ms</th></tr></thead><tbody>
  <?php foreach ($filas as $f): ?>
    <tr><td><?= fecha($f['creado_en']) ?></td><td><?= e($f['flujo']) ?></td><td><?= e($f['escenario']) ?></td>
      <td><span class="resultado resultado-<?= e($f['resultado']) ?>"><?= e($f['resultado']) ?></span></td><td><?= e($f['paso_fallido'] ?? '') ?></td>
      <td><?= e($f['tipo_error'] ?? '') ?></td><td><?= e($f['causa'] ?? '') ?></td><td class="num"><?= e($f['duracion_ms'] ?? '') ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$filas): ?><tr><td colspan="8" class="suave">Aún no hay ejecuciones.</td></tr><?php endif; ?>
</tbody></table></div></section>
<?php Panel::pie();
