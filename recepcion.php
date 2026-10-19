<?php
require_once __DIR__ . '/db_connection.php';
$usuario = requireAuth();
$pdo = getDB();

$fechaFiltro = $_GET['fecha'] ?? date('Y-m-d');
$docFiltro   = isset($_GET['nutriologo_id']) ? (int)$_GET['nutriologo_id'] : 0;
$areaFiltro  = $_GET['area'] ?? '';
$citaCobroId = isset($_GET['cobrar_cita_id']) ? (int)$_GET['cobrar_cita_id'] : 0;

// 1. Cambiar estado de cita en Mostrador / Sala de Espera
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cambiar_estado_cita']) && verify_csrf($_POST['csrf_token'] ?? '')) {
    $citaId = (int)$_POST['cita_id'];
    $nuevoEstado = $_POST['nuevo_estado'];
    $horaActual = date('H:i:s');

    if ($nuevoEstado === 'en_sala') {
        $pdo->prepare("UPDATE citas SET estado = ?, hora_llegada_sala = ? WHERE id = ?")->execute([$nuevoEstado, $horaActual, $citaId]);
    } elseif ($nuevoEstado === 'en_consulta') {
        $pdo->prepare("UPDATE citas SET estado = ?, hora_inicio_consulta = ? WHERE id = ?")->execute([$nuevoEstado, $horaActual, $citaId]);
    } elseif ($nuevoEstado === 'por_cobrar' || $nuevoEstado === 'finalizada') {
        $pdo->prepare("UPDATE citas SET estado = ?, hora_fin_consulta = ? WHERE id = ?")->execute([$nuevoEstado, $horaActual, $citaId]);
    } else {
        $pdo->prepare("UPDATE citas SET estado = ? WHERE id = ?")->execute([$nuevoEstado, $citaId]);
    }
    header("Location: recepcion.php?fecha=" . urlencode($fechaFiltro) . "&nutriologo_id={$docFiltro}&area=" . urlencode($areaFiltro));
    exit;
}

// 2. Agendar Cita Rápida / Walk-in desde Mostrador
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['nueva_cita_mostrador']) && verify_csrf($_POST['csrf_token'] ?? '')) {
    $pacienteId = !empty($_POST['paciente_id']) ? (int)$_POST['paciente_id'] : null;
    $especialistaId = (int)$_POST['nutriologo_id'];
    $servicioId = (int)$_POST['servicio_id'];
    $fechaCita = $_POST['fecha_cita'] ?: date('Y-m-d');
    $horaInicio = $_POST['hora_inicio'] . ':00';
    $estadoInicial = $_POST['estado_inicial'] ?? 'en_sala';
    $motivo = trim($_POST['motivo_notas'] ?? '');

    $stmtSrv = $pdo->prepare("SELECT * FROM catalogo_servicios WHERE id = ?");
    $stmtSrv->execute([$servicioId]);
    $srv = $stmtSrv->fetch();

    $stmtDoc = $pdo->prepare("SELECT * FROM usuarios WHERE id = ?");
    $stmtDoc->execute([$especialistaId]);
    $doc = $stmtDoc->fetch();

    $areaCita = $srv['area'] ?? 'nutricion';
    if ($areaCita === 'paquete') $areaCita = 'psiconutricion';
    $requiereAyuno = ($areaCita === 'checkup' || !empty($srv['incluye_checkup'])) ? 1 : 0;
    $prefijos = ['nutricion' => 'N-', 'psicologia' => 'P-', 'psiconutricion' => 'PN-', 'checkup' => 'CK-'];
    $turno = ($prefijos[$areaCita] ?? 'M-') . rand(10, 99);
    $duracion = (int)($srv['duracion_minutos'] ?? 50);
    $horaFin = date('H:i:s', strtotime($horaInicio . " + {$duracion} minutes"));
    $horaLlegada = ($estadoInicial === 'en_sala' || $estadoInicial === 'en_consulta') ? date('H:i:s') : null;
    $costo = (float)($srv['precio'] ?? 850);
    $consultorio = $doc['consultorio_asignado'] ?? 'Consultorio 1';
    $tipoCita = ($areaCita === 'checkup') ? 'checkup_integral' : (($areaCita === 'psicologia') ? 'psicoterapia' : 'seguimiento');
    $prospecto = !$pacienteId ? trim($_POST['nombre_prospecto'] ?? 'Paciente Mostrador') : null;
    $telProspecto = !$pacienteId ? trim($_POST['telefono_prospecto'] ?? '5500000000') : null;

    $stmtIns = $pdo->prepare("INSERT INTO citas (turno_sala, paciente_id, nutriologo_id, servicio_id, area_cita, consultorio, nombre_prospecto, telefono_prospecto, fecha_cita, hora_inicio, hora_fin, tipo_cita, origen, estado, requiere_ayuno, hora_llegada_sala, costo_estimado, motivo_notas) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'recepcion', ?, ?, ?, ?, ?)");
    $stmtIns->execute([$turno, $pacienteId, $especialistaId, $servicioId, $areaCita, $consultorio, $prospecto, $telProspecto, $fechaCita, $horaInicio, $horaFin, $tipoCita, $estadoInicial, $requiereAyuno, $horaLlegada, $costo, $motivo]);

    header("Location: recepcion.php?fecha=" . urlencode($fechaCita) . "&msg=cita_creada");
    exit;
}

// 3. Registrar Cobro en Punto de Venta (POS)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['registrar_cobro_pos']) && verify_csrf($_POST['csrf_token'] ?? '')) {
    $folio = 'REC-' . date('Ymd') . '-' . rand(100, 999);
    $citaId = !empty($_POST['cita_id']) ? (int)$_POST['cita_id'] : null;
    $pacienteId = !empty($_POST['paciente_id']) ? (int)$_POST['paciente_id'] : null;
    $especialistaId = (int)$_POST['nutriologo_id'];
    $servicioId = !empty($_POST['servicio_id']) ? (int)$_POST['servicio_id'] : null;
    $categoria = $_POST['categoria_ingreso'] ?? 'nutricion';
    $concepto = trim($_POST['concepto']);
    $monto = (float)$_POST['monto'];
    $descuento = (float)($_POST['descuento'] ?? 0);
    $neto = max(0, $monto - $descuento);
    $pagadoHoy = isset($_POST['total_pagado']) && $_POST['total_pagado'] !== '' ? (float)$_POST['total_pagado'] : $neto;
    $saldoGenerado = max(0, $neto - $pagadoHoy);
    $metodoPago = $_POST['metodo_pago'] ?? 'efectivo';
    $referencia = trim($_POST['referencia_pago'] ?? '');
    $requiereFactura = !empty($_POST['requiere_factura']) ? 1 : 0;
    $estadoFactura = $requiereFactura ? 'pendiente' : 'no_solicitada';

    $stmtDoc = $pdo->prepare("SELECT porcentaje_comision FROM usuarios WHERE id = ?");
    $stmtDoc->execute([$especialistaId]);
    $pctComision = (float)($stmtDoc->fetchColumn() ?: 70.0);
    $comisionMonto = round($pagadoHoy * ($pctComision / 100), 2);

    $stmtPago = $pdo->prepare("INSERT INTO pagos_caja (folio_recibo, cita_id, paciente_id, nutriologo_id, servicio_id, categoria_ingreso, concepto, monto, descuento, impuestos, total_pagado, saldo_pendiente_generado, metodo_pago, referencia_pago, comision_especialista, requiere_factura, estado_factura, registrado_por, fecha_pago) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0.00, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmtPago->execute([$folio, $citaId, $pacienteId, $especialistaId, $servicioId, $categoria, $concepto, $monto, $descuento, $pagadoHoy, $saldoGenerado, $metodoPago, $referencia, $comisionMonto, $requiereFactura, $estadoFactura, $usuario['id'], date('Y-m-d H:i:s')]);

    if ($citaId) {
        $pdo->prepare("UPDATE citas SET pagado = 1, estado = 'finalizada' WHERE id = ?")->execute([$citaId]);
    }

    if ($pacienteId) {
        $pdo->prepare("UPDATE pacientes SET total_historico_pagado = total_historico_pagado + ?, saldo_pendiente = saldo_pendiente + ?, ultima_visita = ? WHERE id = ?")
            ->execute([$pagadoHoy, $saldoGenerado, date('Y-m-d'), $pacienteId]);

        if ($categoria === 'abono_saldo' && $pagadoHoy > 0) {
            $pdo->prepare("UPDATE pacientes SET saldo_pendiente = MAX(0, saldo_pendiente - ?) WHERE id = ?")
                ->execute([$pagadoHoy + $saldoGenerado, $pacienteId]);
        }

        if ($servicioId) {
            $stmtS = $pdo->prepare("SELECT * FROM catalogo_servicios WHERE id = ?");
            $stmtS->execute([$servicioId]);
            if ($srvData = $stmtS->fetch()) {
                if ($srvData['area'] === 'paquete') {
                    $pdo->prepare("UPDATE pacientes SET nombre_paquete_activo = ?, sesiones_disponibles_nutri = sesiones_disponibles_nutri + ?, sesiones_disponibles_psico = sesiones_disponibles_psico + ?, checkups_disponibles = checkups_disponibles + ? WHERE id = ?")
                        ->execute([$srvData['nombre'], (int)$srvData['sesiones_nutri_incluidas'], (int)$srvData['sesiones_psico_incluidas'], (int)$srvData['incluye_checkup'], $pacienteId]);
                } elseif ($metodoPago === 'redencion_paquete') {
                    if ($srvData['area'] === 'nutricion') {
                        $pdo->prepare("UPDATE pacientes SET sesiones_disponibles_nutri = MAX(0, sesiones_disponibles_nutri - 1) WHERE id = ?")->execute([$pacienteId]);
                    } elseif ($srvData['area'] === 'psicologia' || $srvData['area'] === 'psiconutricion') {
                        $pdo->prepare("UPDATE pacientes SET sesiones_disponibles_psico = MAX(0, sesiones_disponibles_psico - 1) WHERE id = ?")->execute([$pacienteId]);
                    } elseif ($srvData['area'] === 'checkup') {
                        $pdo->prepare("UPDATE pacientes SET checkups_disponibles = MAX(0, checkups_disponibles - 1) WHERE id = ?")->execute([$pacienteId]);
                    }
                }
            }
        }
    }

    header("Location: recepcion.php?fecha=" . urlencode($fechaFiltro) . "&msg=cobro_ok&folio=" . urlencode($folio));
    exit;
}

// 4. Cerrar Corte de Caja Diario / Arqueo Ciego
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_corte_caja']) && verify_csrf($_POST['csrf_token'] ?? '')) {
    $fondoInicial = (float)$_POST['fondo_inicial_efectivo'];
    $ingEfectivo  = (float)$_POST['ingresos_efectivo'];
    $ingTarjeta   = (float)$_POST['ingresos_tarjeta'];
    $ingTransf    = (float)$_POST['ingresos_transferencia'];
    $gasEfectivo  = (float)$_POST['gastos_efectivo'];
    $esperado     = $fondoInicial + $ingEfectivo - $gasEfectivo;
    $contado      = (float)$_POST['efectivo_contado_fisico'];
    $diferencia   = round($contado - $esperado, 2);
    $totalNeto    = $ingEfectivo + $ingTarjeta + $ingTransf - $gasEfectivo;
    $obs          = trim($_POST['observaciones'] ?? '');

    $stmtCorteIns = $pdo->prepare("INSERT INTO cortes_caja (fecha_corte, turno, fondo_inicial_efectivo, ingresos_efectivo, ingresos_tarjeta, ingresos_transferencia, gastos_efectivo, efectivo_esperado, efectivo_contado_fisico, diferencia_arqueo, total_neto_dia, observaciones, cerrado_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmtCorteIns->execute([$fechaFiltro, $_POST['turno'] ?? 'Turno Completo', $fondoInicial, $ingEfectivo, $ingTarjeta, $ingTransf, $gasEfectivo, $esperado, $contado, $diferencia, $totalNeto, $obs, $usuario['id']]);

    header("Location: recepcion.php?fecha=" . urlencode($fechaFiltro) . "&msg=corte_ok");
    exit;
}

// Consultas de Agenda y Sala de Espera
$sqlCitas = "SELECT c.*, COALESCE(p.nombre_completo, c.nombre_prospecto) AS nombre_mostrar,
             COALESCE(p.telefono_whatsapp, c.telefono_prospecto) AS telefono_mostrar,
             p.folio_expediente, p.nombre_paquete_activo, p.sesiones_disponibles_nutri, p.sesiones_disponibles_psico, p.checkups_disponibles, p.saldo_pendiente,
             u.nombre AS doctor_nombre, u.color_agenda
             FROM citas c
             LEFT JOIN pacientes p ON c.paciente_id = p.id
             JOIN usuarios u ON c.nutriologo_id = u.id
             WHERE c.fecha_cita = :fecha";
$params = ['fecha' => $fechaFiltro];
if ($docFiltro > 0) {
    $sqlCitas .= " AND c.nutriologo_id = :doc";
    $params['doc'] = $docFiltro;
}
if ($areaFiltro !== '') {
    $sqlCitas .= " AND c.area_cita = :area";
    $params['area'] = $areaFiltro;
}
$sqlCitas .= " ORDER BY c.hora_inicio ASC";
$stmtCitas = $pdo->prepare($sqlCitas);
$stmtCitas->execute($params);
$citasDia = $stmtCitas->fetchAll();

$citaSeleccionadaCobro = null;
if ($citaCobroId > 0) {
    foreach ($citasDia as $cd) {
        if ((int)$cd['id'] === $citaCobroId) {
            $citaSeleccionadaCobro = $cd;
            break;
        }
    }
}

$especialistas = $pdo->query("SELECT * FROM usuarios WHERE rol IN ('nutriologo', 'psicologo') AND activo = 1 ORDER BY id ASC")->fetchAll();
$pacientesList = $pdo->query("SELECT id, folio_expediente, nombre_completo, nombre_paquete_activo, sesiones_disponibles_nutri, sesiones_disponibles_psico, checkups_disponibles, saldo_pendiente FROM pacientes ORDER BY nombre_completo ASC")->fetchAll();
$serviciosList = $pdo->query("SELECT * FROM catalogo_servicios WHERE activo = 1 ORDER BY id ASC")->fetchAll();

$stmtPagosHoy = $pdo->prepare("SELECT pg.*, u.nombre AS especialista_nombre, p.nombre_completo AS paciente_nombre
                               FROM pagos_caja pg
                               JOIN usuarios u ON pg.nutriologo_id = u.id
                               LEFT JOIN pacientes p ON pg.paciente_id = p.id
                               WHERE SUBSTR(pg.fecha_pago, 1, 10) = ?
                               ORDER BY pg.id DESC");
$stmtPagosHoy->execute([$fechaFiltro]);
$pagosHoy = $stmtPagosHoy->fetchAll();

$ingEfectivoHoy = 0.0;
$ingTarjetaHoy = 0.0;
$ingTransfHoy = 0.0;
$totalComisionesHoy = 0.0;
foreach ($pagosHoy as $ph) {
    if ($ph['metodo_pago'] === 'efectivo') $ingEfectivoHoy += (float)$ph['total_pagado'];
    elseif ($ph['metodo_pago'] === 'tarjeta_credito' || $ph['metodo_pago'] === 'tarjeta_debito') $ingTarjetaHoy += (float)$ph['total_pagado'];
    elseif ($ph['metodo_pago'] === 'transferencia') $ingTransfHoy += (float)$ph['total_pagado'];
    $totalComisionesHoy += (float)$ph['comision_especialista'];
}
$totalIngresosHoy = $ingEfectivoHoy + $ingTarjetaHoy + $ingTransfHoy;

$stmtGastosEfectivo = $pdo->prepare("SELECT COALESCE(SUM(monto), 0) FROM gastos_operativos WHERE fecha_gasto = ? AND metodo_pago = 'efectivo_caja'");
$stmtGastosEfectivo->execute([$fechaFiltro]);
$gastosEfectivoHoy = (float)$stmtGastosEfectivo->fetchColumn();

$enSalaCount = 0;
$enConsultaCount = 0;
$porCobrarCount = 0;
foreach ($citasDia as $c) {
    if ($c['estado'] === 'en_sala') $enSalaCount++;
    if ($c['estado'] === 'en_consulta') $enConsultaCount++;
    if ($c['estado'] === 'por_cobrar' || ($c['estado'] !== 'cancelada' && !$c['pagado'])) $porCobrarCount++;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mostrador, Sala de Espera y Caja POS | <?= htmlspecialchars(CLINIC_NAME) ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <?php renderNavbar('recepcion'); ?>

    <main class="container section-pad">
        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'cobro_ok'): ?>
            <div class="result-box">
                ✅ Cobro procesado con éxito — Folio <strong><?= htmlspecialchars($_GET['folio'] ?? '') ?></strong>. El corte de caja, las comisiones del especialista y la cartera del paciente han sido actualizados.
                <a href="facturacion.php" class="btn btn-sm btn-primary" style="margin-left:10px;">🧾 Ir a Facturación CFDI 4.0</a>
            </div>
        <?php elseif (isset($_GET['msg']) && $_GET['msg'] === 'corte_ok'): ?>
            <div class="result-box">✅ Arqueo y Corte de Caja diario guardado correctamente en el historial auditable.</div>
        <?php elseif (isset($_GET['msg']) && $_GET['msg'] === 'cita_creada'): ?>
            <div class="result-box">✅ Paciente registrado e ingresado al flujo de Sala de Espera en vivo.</div>
        <?php endif; ?>

        <!-- PANTALLA DE SALA DE ESPERA Y TURNOS EN VIVO (MODO MONITOR TV + TIMBRE SONORO) -->
        <section class="waiting-board">
            <div class="flex-between" style="margin-bottom:18px; border-bottom:1px solid rgba(255,255,255,0.1); padding-bottom:14px;">
                <div>
                    <div style="display:inline-flex; align-items:center; gap:6px; font-size:11.5px; font-weight:700; color:#5eead4; text-transform:uppercase; letter-spacing:0.6px;">
                        <span class="live-dot"></span> MONITOR DE SALA DE ESPERA Y LLAMADO A CONSULTORIOS EN VIVO
                    </div>
                    <h2 style="color:#fff; margin-top:4px; font-size:22px;">Flujo de Pacientes en Sala, Checkups y Consultorios</h2>
                </div>
                <div style="display:flex; align-items:center; gap:12px;">
                    <div style="text-align:right; background:rgba(255,255,255,0.06); padding:6px 14px; border-radius:10px; border:1px solid rgba(255,255,255,0.12);">
                        <small style="display:block; font-size:10px; color:#94a3b8;">RELOJ DE SALA</small>
                        <strong id="reloj_sala" class="mono" style="font-size:16px; color:#5eead4;"><?= date('H:i:s') ?></strong>
                    </div>
                    <div>
                        <span class="badge badge-amber">🛋️ En Sala: <?= $enSalaCount ?></span>
                        <span class="badge badge-purple" style="margin-left:6px;">🩺 En Consulta: <?= $enConsultaCount ?></span>
                    </div>
                </div>
            </div>

            <div class="grid-3">
                <?php
                $activosSala = array_filter($citasDia, fn($x) => in_array($x['estado'], ['en_sala', 'en_consulta', 'por_cobrar'], true));
                if (empty($activosSala)):
                ?>
                    <div class="turn-pill" style="grid-column: 1 / -1;">
                        <span>Sin pacientes esperando en sala en este instante. Haz clic en <strong>"🛋️ En Sala"</strong> en la tabla inferior cuando llegue un paciente al mostrador.</span>
                    </div>
                <?php else: foreach ($activosSala as $as):
                    $turnClass = $as['area_cita'] === 'psicologia' ? 'psico' : ($as['area_cita'] === 'checkup' ? 'checkup' : ($as['area_cita'] === 'psiconutricion' ? 'psiconut' : ''));
                ?>
                    <div class="turn-pill" id="turn_card_<?= (int)$as['id'] ?>">
                        <div style="text-align:center;">
                            <span class="turn-code <?= $turnClass ?>"><?= htmlspecialchars($as['turno_sala']) ?></span>
                            <?php if ($as['requiere_ayuno']): ?>
                                <span class="badge badge-amber" style="display:block; margin-top:6px;">🔬 Ayuno 8h</span>
                            <?php endif; ?>
                        </div>
                        <div style="flex:1;">
                            <strong style="font-size:14.5px; color:#fff;"><?= htmlspecialchars($as['nombre_mostrar']) ?></strong><br>
                            <small style="color:#5eead4; font-weight:600;">📍 <?= htmlspecialchars($as['consultorio']) ?></small><br>
                            <small style="color:#cbd5e1;">👩‍⚕️ <?= htmlspecialchars($as['doctor_nombre']) ?> · Llegada: <?= htmlspecialchars($as['hora_llegada_sala'] ?: substr($as['hora_inicio'], 0, 5)) ?></small>
                        </div>
                        <div style="text-align:right; display:flex; flex-direction:column; gap:6px; align-items:flex-end;">
                            <?php if ($as['estado'] === 'en_sala'): ?>
                                <span class="badge badge-amber">⏳ EN SALA DE ESPERA</span>
                            <?php elseif ($as['estado'] === 'en_consulta'): ?>
                                <span class="badge badge-teal">🩺 EN CONSULTORIO</span>
                            <?php else: ?>
                                <span class="badge badge-rose">💳 PASAR A CAJA POS</span>
                            <?php endif; ?>
                            <button type="button" onclick="llamarTurnoSonoro(<?= (int)$as['id'] ?>, '<?= htmlspecialchars($as['turno_sala']) ?>', '<?= htmlspecialchars(addslashes($as['nombre_mostrar'])) ?>', '<?= htmlspecialchars(addslashes($as['consultorio'])) ?>')" class="btn btn-outline btn-sm" style="background:rgba(255,255,255,0.1); color:#fff; border-color:rgba(255,255,255,0.2); padding:4px 9px;">
                                🔔 Llamar Turno
                            </button>
                        </div>
                    </div>
                <?php endforeach; endif; ?>
            </div>
            <div id="aviso_llamado" style="display:none; margin-top:14px; background:rgba(20,184,166,0.2); border:1px solid #5eead4; padding:10px 16px; border-radius:10px; color:#fff; font-weight:700; text-align:center;"></div>
        </section>

        <!-- KPIS OPERATIVOS DEL DÍA CON BARRAS DE PROGRESO -->
        <div class="grid-4" style="margin-bottom:24px;">
            <div class="kpi-card">
                <div class="kpi-title">Agenda Operativa Hoy</div>
                <div class="kpi-value"><?= count($citasDia) ?> citas</div>
                <div class="kpi-sub">Nutrición, Psicología y Checkups</div>
                <div class="progress-track"><div class="progress-fill" style="width: 82%;"></div></div>
            </div>
            <div class="kpi-card purple">
                <div class="kpi-title">En Sala / En Consulta</div>
                <div class="kpi-value"><?= $enSalaCount ?> / <?= $enConsultaCount ?></div>
                <div class="kpi-sub">Triage y atención en vivo</div>
                <div class="progress-track"><div class="progress-fill purple" style="width: 65%;"></div></div>
            </div>
            <div class="kpi-card blue">
                <div class="kpi-title">Ingresos Cobrados Hoy (POS)</div>
                <div class="kpi-value">$<?= number_format($totalIngresosHoy, 2) ?></div>
                <div class="kpi-sub">Efec: $<?= number_format($ingEfectivoHoy, 0) ?> · Tarj/SPEI: $<?= number_format($ingTarjetaHoy + $ingTransfHoy, 0) ?></div>
                <div class="progress-track"><div class="progress-fill blue" style="width: 88%;"></div></div>
            </div>
            <div class="kpi-card amber">
                <div class="kpi-title">Comisiones Generadas Hoy</div>
                <div class="kpi-value">$<?= number_format($totalComisionesHoy, 2) ?></div>
                <div class="kpi-sub">Retención Clínica: $<?= number_format($totalIngresosHoy - $totalComisionesHoy, 2) ?></div>
                <div class="progress-track"><div class="progress-fill amber" style="width: 70%;"></div></div>
            </div>
        </div>

        <!-- BARRA DE FILTROS Y TABLA DE CONTROL DE MOSTRADOR -->
        <div class="card">
            <div class="flex-between" style="margin-bottom:14px;">
                <div>
                    <h2>📋 Control de Mostrador, Recepción de Pacientes y Triage de Checkups</h2>
                    <p class="muted">Administra el estatus de cada paciente desde su llegada hasta el cobro en Punto de Venta.</p>
                </div>
                <form method="GET" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                    <input type="date" name="fecha" value="<?= htmlspecialchars($fechaFiltro) ?>" style="width:auto; margin:0;">
                    <select name="area" style="width:auto; margin:0;">
                        <option value="">Todas las Especialidades</option>
                        <option value="nutricion" <?= $areaFiltro === 'nutricion' ? 'selected' : '' ?>>🥗 Nutrición</option>
                        <option value="psicologia" <?= $areaFiltro === 'psicologia' ? 'selected' : '' ?>>🧠 Psicología</option>
                        <option value="psiconutricion" <?= $areaFiltro === 'psiconutricion' ? 'selected' : '' ?>>🤝 Psiconutrición</option>
                        <option value="checkup" <?= $areaFiltro === 'checkup' ? 'selected' : '' ?>>🔬 Checkups 360°</option>
                    </select>
                    <select name="nutriologo_id" style="width:auto; margin:0;">
                        <option value="0">Todos los Especialistas</option>
                        <?php foreach ($especialistas as $doc): ?>
                            <option value="<?= (int)$doc['id'] ?>" <?= $docFiltro === (int)$doc['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($doc['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-primary btn-sm">Filtrar Agenda</button>
                </form>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Turno / Hora</th>
                            <th>Paciente / Expediente</th>
                            <th>Área y Servicio</th>
                            <th>Especialista / Consultorio</th>
                            <th>Estado en Flujo</th>
                            <th>Cobro</th>
                            <th>Acciones de Mostrador</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($citasDia as $c):
                            $areaBadges = [
                                'nutricion' => 'badge-teal',
                                'psicologia' => 'badge-purple',
                                'psiconutricion' => 'badge-rose',
                                'checkup' => 'badge-blue'
                            ];
                            $estadoBadges = [
                                'pendiente_confirmar' => 'badge-amber',
                                'confirmada' => 'badge-blue',
                                'en_sala' => 'badge-amber',
                                'en_consulta' => 'badge-purple',
                                'por_cobrar' => 'badge-rose',
                                'finalizada' => 'badge-teal',
                                'cancelada' => 'badge-slate'
                            ];
                        ?>
                        <tr>
                            <td>
                                <span class="badge badge-slate mono"><?= htmlspecialchars($c['turno_sala']) ?></span>
                                <strong><?= substr($c['hora_inicio'], 0, 5) ?></strong><br>
                                <small class="muted">Check-in: <?= htmlspecialchars($c['hora_llegada_sala'] ?: '—') ?></small>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars($c['nombre_mostrar']) ?></strong>
                                <?php if (!empty($c['folio_expediente'])): ?>
                                    <span class="badge badge-slate"><?= htmlspecialchars($c['folio_expediente']) ?></span>
                                <?php else: ?>
                                    <span class="badge badge-amber">Prospecto Web</span>
                                <?php endif; ?>
                                <br>
                                <small class="muted">📞 <?= htmlspecialchars($c['telefono_mostrar']) ?></small>
                                <?php if (!empty($c['saldo_pendiente']) && $c['saldo_pendiente'] > 0): ?>
                                    <br><span class="badge badge-rose">Saldo Pendiente: $<?= number_format((float)$c['saldo_pendiente'], 2) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?= $areaBadges[$c['area_cita']] ?? 'badge-teal' ?>">
                                    <?= strtoupper(htmlspecialchars($c['area_cita'])) ?>
                                </span>
                                <?php if ($c['requiere_ayuno']): ?>
                                    <span class="badge badge-amber">🔬 Ayuno</span>
                                <?php endif; ?>
                                <br>
                                <small class="muted"><?= htmlspecialchars($c['motivo_notas'] ?: $c['tipo_cita']) ?></small>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars($c['doctor_nombre']) ?></strong><br>
                                <small class="muted">📍 <?= htmlspecialchars($c['consultorio']) ?></small>
                            </td>
                            <td>
                                <span class="badge <?= $estadoBadges[$c['estado']] ?? 'badge-slate' ?>">
                                    <?= strtoupper(str_replace('_', ' ', htmlspecialchars($c['estado']))) ?>
                                </span>
                            </td>
                            <td>
                                <strong>$<?= number_format((float)$c['costo_estimado'], 2) ?></strong><br>
                                <?php if ($c['pagado']): ?>
                                    <span class="badge badge-teal">✓ PAGADO</span>
                                <?php else: ?>
                                    <span class="badge badge-amber">PENDIENTE</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <form method="POST" style="display:inline-flex; gap:4px; flex-wrap:wrap;">
                                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                    <input type="hidden" name="cambiar_estado_cita" value="1">
                                    <input type="hidden" name="cita_id" value="<?= (int)$c['id'] ?>">
                                    <?php if ($c['estado'] === 'pendiente_confirmar'): ?>
                                        <button name="nuevo_estado" value="confirmada" class="btn btn-outline btn-sm">✓ Confirmar</button>
                                    <?php endif; ?>
                                    <button name="nuevo_estado" value="en_sala" class="btn btn-amber btn-sm" title="Registrar llegada a Sala de Espera">🛋️ En Sala</button>
                                    <button name="nuevo_estado" value="en_consulta" class="btn btn-purple btn-sm" title="Pasar a Consultorio">🩺 En Consulta</button>
                                    <button name="nuevo_estado" value="por_cobrar" class="btn btn-outline btn-sm" title="Terminó consulta, pasar a caja">💳 A Caja</button>
                                </form>
                                <div style="margin-top:6px; display:flex; gap:5px; flex-wrap:wrap;">
                                    <?php if (!$c['pagado']): ?>
                                        <a href="recepcion.php?fecha=<?= urlencode($fechaFiltro) ?>&cobrar_cita_id=<?= (int)$c['id'] ?>#pos-caja" class="btn btn-primary btn-sm">💵 Cobrar POS</a>
                                    <?php endif; ?>
                                    <?php if ($c['paciente_id']): ?>
                                        <a href="consulta.php?paciente_id=<?= (int)$c['paciente_id'] ?>" class="btn btn-outline btn-sm">📂 Expediente</a>
                                    <?php endif; ?>
                                    <a href="<?= generarLinkWhatsApp((string)$c['telefono_mostrar'], (string)$c['nombre_mostrar'], (string)$c['fecha_cita'], substr((string)$c['hora_inicio'], 0, 5), (string)$c['doctor_nombre'], strtoupper((string)$c['area_cita']), (bool)$c['requiere_ayuno']) ?>" target="_blank" class="btn btn-whatsapp btn-sm">WhatsApp</a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <details style="margin-top:18px; border-top:1px solid var(--border); padding-top:14px;">
                <summary style="cursor:pointer; font-weight:700; color:var(--primary); font-size:13.5px;">➕ Registrar Llegada sin Cita (Walk-In) o Nueva Cita desde Mostrador</summary>
                <form method="POST" class="grid-4" style="margin-top:14px;">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="nueva_cita_mostrador" value="1">
                    <label>Paciente en Cartera (Opcional)
                        <select name="paciente_id">
                            <option value="">-- Nuevo Prospecto / Walk-in --</option>
                            <?php foreach ($pacientesList as $pl): ?>
                                <option value="<?= (int)$pl['id'] ?>"><?= htmlspecialchars($pl['folio_expediente'] . ' - ' . $pl['nombre_completo']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Si es Nuevo: Nombre
                        <input type="text" name="nombre_prospecto" placeholder="Nombre del visitante">
                    </label>
                    <label>Si es Nuevo: WhatsApp
                        <input type="text" name="telefono_prospecto" placeholder="5512345678">
                    </label>
                    <label>Servicio / Checkup *
                        <select name="servicio_id" required>
                            <?php foreach ($serviciosList as $sl): ?>
                                <option value="<?= (int)$sl['id'] ?>">[<?= strtoupper($sl['area']) ?>] <?= htmlspecialchars($sl['nombre']) ?> ($<?= number_format((float)$sl['precio'], 0) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Especialista *
                        <select name="nutriologo_id" required>
                            <?php foreach ($especialistas as $doc): ?>
                                <option value="<?= (int)$doc['id'] ?>"><?= htmlspecialchars($doc['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Fecha *
                        <input type="date" name="fecha_cita" value="<?= htmlspecialchars($fechaFiltro) ?>" required>
                    </label>
                    <label>Hora *
                        <input type="time" name="hora_inicio" value="<?= date('H:i') ?>" required>
                    </label>
                    <label>Estado Inicial *
                        <select name="estado_inicial">
                            <option value="en_sala">🛋️ Ya en Sala de Espera (Check-in inmediato)</option>
                            <option value="confirmada">📅 Programada / Confirmada</option>
                            <option value="en_consulta">🩺 Pasar directo a Consultorio</option>
                        </select>
                    </label>
                    <label style="grid-column: span 3;">Notas / Triage (Ej. Ayuno verificado 10h, motivo de consulta)
                        <input type="text" name="motivo_notas" placeholder="Observaciones de recepción">
                    </label>
                    <div style="display:flex; align-items:flex-end; margin-bottom:12px;">
                        <button type="submit" class="btn btn-primary" style="width:100%;">Registrar en Mostrador</button>
                    </div>
                </form>
            </details>
        </div>

        <!-- PUNTO DE VENTA (POS) Y CORTE DE CAJA -->
        <div class="grid-2" id="pos-caja">
            <div class="card" style="border-top: 4px solid var(--primary);">
                <h3>💳 Terminal Punto de Venta (POS) — Cobro, Checkups, Paquetes y Abonos</h3>
                <p class="muted" style="margin-bottom:14px;">Calcula comisiones automáticamente, acredita o redime sesiones de paquetes y envía a timbrado CFDI 4.0.</p>
                <form method="POST" class="grid-2">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="registrar_cobro_pos" value="1">
                    <input type="hidden" name="cita_id" value="<?= (int)($citaSeleccionadaCobro['id'] ?? 0) ?>">

                    <label>Paciente / Cliente (Cartera CRM)
                        <select name="paciente_id" id="pos_paciente">
                            <option value="">-- Público en General / Mostrador --</option>
                            <?php foreach ($pacientesList as $pl): ?>
                                <option value="<?= (int)$pl['id'] ?>" <?= ($citaSeleccionadaCobro && (int)$citaSeleccionadaCobro['paciente_id'] === (int)$pl['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($pl['nombre_completo']) ?> (Nut:<?= (int)$pl['sesiones_disponibles_nutri'] ?> / Psi:<?= (int)$pl['sesiones_disponibles_psico'] ?> / Chk:<?= (int)$pl['checkups_disponibles'] ?> | Saldo:$<?= number_format((float)$pl['saldo_pendiente'], 0) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label>Catálogo de Servicios / Checkups / Paquetes
                        <select name="servicio_id" id="pos_servicio" onchange="seleccionarServicioPOS(this)">
                            <option value="">-- Seleccionar del catálogo --</option>
                            <?php foreach ($serviciosList as $sl): ?>
                                <option value="<?= (int)$sl['id'] ?>"
                                        data-nombre="<?= htmlspecialchars($sl['nombre']) ?>"
                                        data-precio="<?= (float)$sl['precio'] ?>"
                                        data-area="<?= htmlspecialchars($sl['area']) ?>"
                                        <?= ($citaSeleccionadaCobro && (int)$citaSeleccionadaCobro['servicio_id'] === (int)$sl['id']) ? 'selected' : '' ?>>
                                    [<?= strtoupper($sl['area']) ?>] <?= htmlspecialchars($sl['nombre']) ?> — $<?= number_format((float)$sl['precio'], 2) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label>Especialista que Atendió *
                        <select name="nutriologo_id" id="pos_doctor" onchange="recalcularPOS()" required>
                            <?php foreach ($especialistas as $doc): ?>
                                <option value="<?= (int)$doc['id'] ?>" data-comision="<?= (float)$doc['porcentaje_comision'] ?>" <?= ($citaSeleccionadaCobro && (int)$citaSeleccionadaCobro['nutriologo_id'] === (int)$doc['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($doc['nombre']) ?> (Comisión <?= (float)$doc['porcentaje_comision'] ?>%)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label>Categoría Contable *
                        <select name="categoria_ingreso" id="pos_categoria" required>
                            <option value="nutricion" <?= ($citaSeleccionadaCobro && $citaSeleccionadaCobro['area_cita'] === 'nutricion') ? 'selected' : '' ?>>🥗 Consulta Nutricional</option>
                            <option value="psicologia" <?= ($citaSeleccionadaCobro && $citaSeleccionadaCobro['area_cita'] === 'psicologia') ? 'selected' : '' ?>>🧠 Psicoterapia Individual</option>
                            <option value="psiconutricion" <?= ($citaSeleccionadaCobro && $citaSeleccionadaCobro['area_cita'] === 'psiconutricion') ? 'selected' : '' ?>>🤝 Psiconutrición Interdisciplinaria</option>
                            <option value="checkup" <?= ($citaSeleccionadaCobro && $citaSeleccionadaCobro['area_cita'] === 'checkup') ? 'selected' : '' ?>>🔬 Checkup Médico Integral 360°</option>
                            <option value="paquete">📦 Venta de Paquete / Membresía</option>
                            <option value="abono_saldo">💵 Abono a Cuenta por Cobrar (Saldo Pendiente)</option>
                        </select>
                    </label>

                    <label style="grid-column: span 2;">Concepto del Recibo / Factura *
                        <input type="text" name="concepto" id="pos_concepto" value="<?= htmlspecialchars($citaSeleccionadaCobro['motivo_notas'] ?? 'Consulta Especializada / Checkup Integral') ?>" required>
                    </label>

                    <label>Importe del Servicio ($ MXN) *
                        <input type="number" name="monto" id="pos_monto" step="0.01" value="<?= (float)($citaSeleccionadaCobro['costo_estimado'] ?? 950) ?>" oninput="recalcularPOS()" required>
                    </label>

                    <label>Descuento Aplicado ($ MXN)
                        <input type="number" name="descuento" id="pos_descuento" step="0.01" value="0" oninput="recalcularPOS()">
                    </label>

                    <label>Monto Pagado Hoy ($ MXN) *
                        <input type="number" name="total_pagado" id="pos_pagado" step="0.01" value="<?= (float)($citaSeleccionadaCobro['costo_estimado'] ?? 950) ?>" oninput="actualizarDesglosePOS()" required>
                    </label>

                    <label>Método de Pago *
                        <select name="metodo_pago" required>
                            <option value="efectivo">💵 Efectivo en Caja</option>
                            <option value="tarjeta_credito">💳 Terminal Tarjeta de Crédito</option>
                            <option value="tarjeta_debito">💳 Terminal Tarjeta de Débito</option>
                            <option value="transferencia">🏦 Transferencia SPEI</option>
                            <option value="redencion_paquete">🎟️ Redimir Sesión de Paquete Prepagado ($0)</option>
                        </select>
                    </label>

                    <div style="grid-column: span 2; background:#f0fdfa; border:1px solid #99f6e4; border-radius:10px; padding:10px 14px; font-size:12.5px; color:#0f766e;" id="pos_desglose_live">
                        💡 <strong>Desglose Autónomo:</strong> Comisión Especialista: <strong>$665.00</strong> · Retención Clínica: <strong>$285.00</strong> · Saldo Restante a Cartera: <strong>$0.00</strong>
                    </div>

                    <label>Referencia Terminal / Clave Rastreo SPEI
                        <input type="text" name="referencia_pago" placeholder="Ej. AUT-92810 o SPEI-1029">
                    </label>

                    <label style="display:flex; align-items:center; gap:8px; padding-top:22px;">
                        <input type="checkbox" name="requiere_factura" value="1" style="width:18px; height:18px; margin:0;">
                        <span>🧾 Solicita Factura CFDI 4.0 (Enviar a bandeja fiscal)</span>
                    </label>

                    <div style="grid-column: span 2;">
                        <button type="submit" class="btn btn-primary btn-lg" style="width:100%;">✅ Emitir Recibo de Caja, Registrar Pago y Actualizar Cartera</button>
                    </div>
                </form>
            </div>

            <!-- CORTE DE CAJA Y ARQUEO CIEGO -->
            <div class="card" style="border-top: 4px solid var(--blue);">
                <h3>📊 Movimientos de Caja Hoy y Cierre de Turno (Arqueo Ciego)</h3>
                <div class="table-wrap" style="max-height:250px; overflow-y:auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Folio</th>
                                <th>Paciente / Concepto</th>
                                <th>Método</th>
                                <th>Pagado</th>
                                <th>CFDI</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pagosHoy as $p): ?>
                            <tr>
                                <td><code style="font-weight:700;"><?= htmlspecialchars($p['folio_recibo']) ?></code></td>
                                <td>
                                    <strong><?= htmlspecialchars($p['paciente_nombre'] ?: 'Mostrador') ?></strong><br>
                                    <small class="muted"><?= htmlspecialchars($p['concepto']) ?></small>
                                </td>
                                <td><span class="badge badge-slate"><?= strtoupper(htmlspecialchars($p['metodo_pago'])) ?></span></td>
                                <td>
                                    <strong>$<?= number_format((float)$p['total_pagado'], 2) ?></strong>
                                    <?php if ($p['saldo_pendiente_generado'] > 0): ?>
                                        <br><small style="color:var(--rose);">Resta: $<?= number_format((float)$p['saldo_pendiente_generado'], 0) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($p['estado_factura'] === 'timbrada'): ?>
                                        <span class="badge badge-teal">Timbrada</span>
                                    <?php elseif ($p['estado_factura'] === 'pendiente'): ?>
                                        <a href="facturacion.php?pago_id=<?= (int)$p['id'] ?>" class="badge badge-amber">Timbrar →</a>
                                    <?php else: ?>
                                        <small class="muted">Recibo</small>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php $efectivoEsperadoCaja = 1000.00 + $ingEfectivoHoy - $gastosEfectivoHoy; ?>
                <div style="background:#f8fafc; border:1px solid var(--border); border-radius:12px; padding:16px; margin-top:16px;">
                    <h4 style="margin-bottom:8px;">🔒 Cerrar Corte de Caja Diario / Arqueo de Efectivo</h4>
                    <p class="muted" style="margin-bottom:12px;">
                        Fondo Fijo: <strong>$1,000.00</strong> + Cobros Efectivo: <strong>$<?= number_format($ingEfectivoHoy, 2) ?></strong>
                        - Gastos Caja Chica: <strong>$<?= number_format($gastosEfectivoHoy, 2) ?></strong>
                        = Efectivo Esperado en Gaveta: <strong>$<?= number_format($efectivoEsperadoCaja, 2) ?></strong>
                    </p>
                    <form method="POST" class="grid-2">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        <input type="hidden" name="guardar_corte_caja" value="1">
                        <input type="hidden" name="fondo_inicial_efectivo" value="1000.00">
                        <input type="hidden" name="ingresos_efectivo" value="<?= $ingEfectivoHoy ?>">
                        <input type="hidden" name="ingresos_tarjeta" value="<?= $ingTarjetaHoy ?>">
                        <input type="hidden" name="ingresos_transferencia" value="<?= $ingTransfHoy ?>">
                        <input type="hidden" name="gastos_efectivo" value="<?= $gastosEfectivoHoy ?>">

                        <label>Turno que Cierra
                            <select name="turno">
                                <option value="Turno Completo">Turno Completo (08:00 - 20:00)</option>
                                <option value="Turno Matutino">Turno Matutino</option>
                                <option value="Turno Vespertino">Turno Vespertino</option>
                            </select>
                        </label>
                        <label>Efectivo Físico Contado en Caja ($ MXN) *
                            <input type="number" name="efectivo_contado_fisico" step="0.01" value="<?= $efectivoEsperadoCaja ?>" required>
                        </label>
                        <label style="grid-column: span 2;">Observaciones de Entrega / Arqueo
                            <input type="text" name="observaciones" placeholder="Ej. Se entrega corte cuadrado y comprobantes de terminal bancaria">
                        </label>
                        <div style="grid-column: span 2;">
                            <button type="submit" class="btn btn-outline" style="width:100%;">📥 Guardar Arqueo y Emitir Corte de Caja del Día</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </main>

    <script>
    // Reloj en tiempo real para Sala de Espera
    setInterval(() => {
        const el = document.getElementById('reloj_sala');
        if (el) el.textContent = new Date().toLocaleTimeString('es-MX', { hour12: false });
    }, 1000);

    // Timbre sonoro Web Audio API y resaltado visual de llamado a consultorio
    function llamarTurnoSonoro(id, turno, paciente, consultorio) {
        try {
            const ctx = new (window.AudioContext || window.webkitAudioContext)();
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.type = 'sine';
            osc.frequency.setValueAtTime(587.33, ctx.currentTime); // D5
            osc.frequency.setValueAtTime(880.00, ctx.currentTime + 0.18); // A5
            gain.gain.setValueAtTime(0.18, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.65);
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.start();
            osc.stop(ctx.currentTime + 0.65);
        } catch (e) {}

        document.querySelectorAll('.turn-pill').forEach(c => c.classList.remove('called-highlight'));
        const card = document.getElementById('turn_card_' + id);
        if (card) card.classList.add('called-highlight');

        const aviso = document.getElementById('aviso_llamado');
        if (aviso) {
            aviso.style.display = 'block';
            aviso.innerHTML = `🔔 <strong>LLAMADO EN PANTALLA:</strong> Turno <code>${turno}</code> — <strong>${paciente}</strong>, favor de pasar a <strong>${consultorio}</strong>.`;
        }
    }

    function seleccionarServicioPOS(sel) {
        const opt = sel.options[sel.selectedIndex];
        if (!opt || !opt.value) return;
        const nombre = opt.getAttribute('data-nombre');
        const precio = parseFloat(opt.getAttribute('data-precio')) || 0;
        const area = opt.getAttribute('data-area') || 'nutricion';
        document.getElementById('pos_concepto').value = nombre;
        document.getElementById('pos_monto').value = precio.toFixed(2);
        document.getElementById('pos_categoria').value = area;
        recalcularPOS();
    }

    function recalcularPOS() {
        const monto = parseFloat(document.getElementById('pos_monto').value) || 0;
        const desc = parseFloat(document.getElementById('pos_descuento').value) || 0;
        const neto = Math.max(0, monto - desc);
        document.getElementById('pos_pagado').value = neto.toFixed(2);
        actualizarDesglosePOS();
    }

    function actualizarDesglosePOS() {
        const monto = parseFloat(document.getElementById('pos_monto').value) || 0;
        const desc = parseFloat(document.getElementById('pos_descuento').value) || 0;
        const neto = Math.max(0, monto - desc);
        const pagado = parseFloat(document.getElementById('pos_pagado').value) || 0;
        const saldo = Math.max(0, neto - pagado);

        const docSel = document.getElementById('pos_doctor');
        const pctCom = parseFloat(docSel?.options[docSel.selectedIndex]?.getAttribute('data-comision')) || 70;
        const comision = (pagado * (pctCom / 100)).toFixed(2);
        const retencion = (pagado - parseFloat(comision)).toFixed(2);

        const box = document.getElementById('pos_desglose_live');
        if (box) {
            box.innerHTML = `💡 <strong>Desglose Autónomo (${pctCom}% com.):</strong> Comisión Especialista: <strong>$${comision}</strong> · Retención Clínica: <strong>$${retencion}</strong> · Saldo Restante a Cartera: <strong>$${saldo.toFixed(2)}</strong>`;
        }
    }
    </script>
</body>
</html>
