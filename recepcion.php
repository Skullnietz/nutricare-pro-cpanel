<?php
declare(strict_types=1);
require_once __DIR__ . '/db_connection.php';

$pdo = getDB();
$usuario = requireAuth();
$hoy = date('Y-m-d');
$fechaFiltro = $_GET['fecha'] ?? $hoy;
$vista = $_GET['vista'] ?? 'agenda';
if (isset($_GET['cobrar_cita_id']) || isset($_GET['recibo_id'])) {
    $vista = 'caja';
}

$mensajeExito = '';
$mensajeError = '';
$reciboRecienCreado = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $mensajeError = 'Token de seguridad inválido. Intenta nuevamente.';
    } else {
        $accion = $_POST['accion'] ?? '';

        if ($accion === 'cambiar_estado_cita') {
            $citaId = (int)($_POST['cita_id'] ?? 0);
            $nuevoEstado = $_POST['nuevo_estado'] ?? 'confirmada';
            $horaActual = date('H:i:s');

            if ($nuevoEstado === 'en_sala') {
                $stmt = $pdo->prepare("UPDATE citas SET estado = ?, hora_llegada_sala = ? WHERE id = ?");
                $stmt->execute([$nuevoEstado, $horaActual, $citaId]);
            } elseif ($nuevoEstado === 'en_consulta') {
                $stmt = $pdo->prepare("UPDATE citas SET estado = ?, hora_inicio_consulta = ? WHERE id = ?");
                $stmt->execute([$nuevoEstado, $horaActual, $citaId]);
            } else {
                $stmt = $pdo->prepare("UPDATE citas SET estado = ? WHERE id = ?");
                $stmt->execute([$nuevoEstado, $citaId]);
            }
            $mensajeExito = "Estado de la cita actualizado a: " . strtoupper(str_replace('_', ' ', $nuevoEstado));
        }

        elseif ($accion === 'agendar_mostrador') {
            $pacienteId = !empty($_POST['paciente_id']) ? (int)$_POST['paciente_id'] : null;
            $nombreProspecto = trim($_POST['nombre_prospecto'] ?? '');
            $telProspecto = trim($_POST['telefono_prospecto'] ?? '');
            $nutriologoId = (int)($_POST['nutriologo_id'] ?? 1);
            $servicioId = (int)($_POST['servicio_id'] ?? 1);
            $fechaCita = $_POST['fecha_cita'] ?? $hoy;
            $horaInicio = ($_POST['hora_inicio'] ?? '11:00') . ':00';
            $estadoInicial = $_POST['estado_inicial'] ?? 'en_sala';
            $motivo = trim($_POST['motivo_notas'] ?? '');

            $stmtS = $pdo->prepare("SELECT * FROM catalogo_servicios WHERE id = ?");
            $stmtS->execute([$servicioId]);
            $serv = $stmtS->fetch();

            $stmtD = $pdo->prepare("SELECT * FROM usuarios WHERE id = ?");
            $stmtD->execute([$nutriologoId]);
            $doc = $stmtD->fetch();

            $areaCita = $serv['area'] ?? 'nutricion';
            if ($areaCita === 'paquete') {
                $areaCita = ((int)($serv['incluye_checkup'] ?? 0) === 1) ? 'checkup' : 'psiconutricion';
            }
            $requiereAyuno = ($areaCita === 'checkup' || (int)($serv['incluye_checkup'] ?? 0) === 1) ? 1 : 0;
            $prefijo = match($areaCita) {
                'psicologia' => 'P-',
                'psiconutricion' => 'PN-',
                'checkup' => 'CK-',
                default => 'N-'
            };
            $turno = $prefijo . rand(10, 99);
            $horaFin = date('H:i:s', strtotime($horaInicio . ' +50 minutes'));
            $costo = (float)($serv['precio'] ?? 850.00);
            $consultorio = $doc['consultorio_asignado'] ?? 'Consultorio 1';
            $horaLlegada = ($estadoInicial === 'en_sala') ? date('H:i:s') : null;

            $stmtIns = $pdo->prepare("INSERT INTO citas (turno_sala, paciente_id, nutriologo_id, servicio_id, area_cita, consultorio, nombre_prospecto, telefono_prospecto, fecha_cita, hora_inicio, hora_fin, tipo_cita, origen, estado, requiere_ayuno, hora_llegada_sala, costo_estimado, pagado, motivo_notas) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'seguimiento', 'recepcion', ?, ?, ?, ?, 0, ?)");
            $stmtIns->execute([
                $turno,
                $pacienteId,
                $nutriologoId,
                $servicioId,
                $areaCita,
                $consultorio,
                $pacienteId ? null : $nombreProspecto,
                $pacienteId ? null : $telProspecto,
                $fechaCita,
                $horaInicio,
                $horaFin,
                $estadoInicial,
                $requiereAyuno,
                $horaLlegada,
                $costo,
                $motivo
            ]);
            $mensajeExito = "Cita registrada con turno {$turno}.";
            $vista = 'agenda';
        }

        elseif ($accion === 'cobrar_pos') {
            $citaId = !empty($_POST['cita_id']) ? (int)$_POST['cita_id'] : null;
            $pacienteId = (int)($_POST['paciente_id'] ?? 1);
            $nutriologoId = (int)($_POST['nutriologo_id'] ?? 1);
            $servicioId = (int)($_POST['servicio_id'] ?? 1);
            $concepto = trim($_POST['concepto'] ?? 'Consulta Clínica');
            $monto = (float)($_POST['monto'] ?? 0);
            $descuento = (float)($_POST['descuento'] ?? 0);
            $totalPagado = (float)($_POST['total_pagado'] ?? 0);
            $metodoPago = $_POST['metodo_pago'] ?? 'efectivo';
            $referencia = trim($_POST['referencia_pago'] ?? '');
            $requiereFactura = isset($_POST['requiere_factura']) ? 1 : 0;
            $usarSesionPaquete = isset($_POST['usar_sesion_paquete']) ? 1 : 0;

            $stmtS = $pdo->prepare("SELECT * FROM catalogo_servicios WHERE id = ?");
            $stmtS->execute([$servicioId]);
            $serv = $stmtS->fetch();
            $categoriaIngreso = $serv['area'] ?? 'nutricion';

            $stmtDoc = $pdo->prepare("SELECT porcentaje_comision FROM usuarios WHERE id = ?");
            $stmtDoc->execute([$nutriologoId]);
            $pctComision = (float)($stmtDoc->fetchColumn() ?: 70.0);

            if ($usarSesionPaquete === 1) {
                $descuento = $monto;
                $totalPagado = 0.00;
                $metodoPago = 'redencion_paquete';
                $concepto .= ' (Sesión de Paquete)';
                if ($categoriaIngreso === 'psicologia') {
                    $pdo->prepare("UPDATE pacientes SET sesiones_disponibles_psico = CASE WHEN sesiones_disponibles_psico > 0 THEN sesiones_disponibles_psico - 1 ELSE 0 END, ultima_visita = ? WHERE id = ?")->execute([$hoy, $pacienteId]);
                } elseif ($categoriaIngreso === 'checkup') {
                    $pdo->prepare("UPDATE pacientes SET checkups_disponibles = CASE WHEN checkups_disponibles > 0 THEN checkups_disponibles - 1 ELSE 0 END, ultima_visita = ? WHERE id = ?")->execute([$hoy, $pacienteId]);
                } else {
                    $pdo->prepare("UPDATE pacientes SET sesiones_disponibles_nutri = CASE WHEN sesiones_disponibles_nutri > 0 THEN sesiones_disponibles_nutri - 1 ELSE 0 END, ultima_visita = ? WHERE id = ?")->execute([$hoy, $pacienteId]);
                }
            }

            $netoServicio = max(0.0, $monto - $descuento);
            $saldoPendienteGenerado = max(0.0, $netoServicio - $totalPagado);
            $comisionEspecialista = round($totalPagado * ($pctComision / 100.0), 2);
            $folioRecibo = 'REC-' . date('Ymd') . '-' . rand(100, 999);
            $estadoFactura = $requiereFactura ? 'pendiente' : 'no_solicitada';

            $stmtPay = $pdo->prepare("INSERT INTO pagos_caja (folio_recibo, cita_id, paciente_id, nutriologo_id, servicio_id, categoria_ingreso, concepto, monto, descuento, impuestos, total_pagado, saldo_pendiente_generado, metodo_pago, referencia_pago, comision_especialista, requiere_factura, estado_factura, registrado_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0.00, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmtPay->execute([
                $folioRecibo,
                $citaId,
                $pacienteId,
                $nutriologoId,
                $servicioId,
                $categoriaIngreso,
                $concepto,
                $monto,
                $descuento,
                $totalPagado,
                $saldoPendienteGenerado,
                $metodoPago,
                $referencia ?: 'CAJA-POS',
                $comisionEspecialista,
                $requiereFactura,
                $estadoFactura,
                $usuario['id']
            ]);
            $reciboRecienCreado = (int)$pdo->lastInsertId();

            $sesionesNutriAdd = (int)($serv['sesiones_nutri_incluidas'] ?? 0);
            $sesionesPsicoAdd = (int)($serv['sesiones_psico_incluidas'] ?? 0);
            $checkupAdd = (int)($serv['incluye_checkup'] ?? 0);
            if ($categoriaIngreso === 'paquete') {
                $stmtUpPac = $pdo->prepare("UPDATE pacientes SET nombre_paquete_activo = ?, sesiones_disponibles_nutri = sesiones_disponibles_nutri + ?, sesiones_disponibles_psico = sesiones_disponibles_psico + ?, checkups_disponibles = checkups_disponibles + ?, saldo_pendiente = saldo_pendiente + ?, total_historico_pagado = total_historico_pagado + ?, ultima_visita = ?, estatus_cartera = 'activo' WHERE id = ?");
                $stmtUpPac->execute([$serv['nombre'], $sesionesNutriAdd, $sesionesPsicoAdd, $checkupAdd, $saldoPendienteGenerado, $totalPagado, $hoy, $pacienteId]);
            } else {
                $stmtUpPac = $pdo->prepare("UPDATE pacientes SET saldo_pendiente = saldo_pendiente + ?, total_historico_pagado = total_historico_pagado + ?, ultima_visita = ?, estatus_cartera = 'activo' WHERE id = ?");
                $stmtUpPac->execute([$saldoPendienteGenerado, $totalPagado, $hoy, $pacienteId]);
            }

            if ($citaId) {
                $pdo->prepare("UPDATE citas SET pagado = 1, estado = 'finalizada' WHERE id = ?")->execute([$citaId]);
            }

            $mensajeExito = "Cobro registrado con folio {$folioRecibo} por $" . number_format($totalPagado, 2) . " MXN.";
            $vista = 'caja';
        }

        elseif ($accion === 'cerrar_corte_caja') {
            $turno = $_POST['turno'] ?? 'Turno Completo';
            $fondoInicial = (float)($_POST['fondo_inicial_efectivo'] ?? 1000.00);
            $efectivoContado = (float)($_POST['efectivo_contado_fisico'] ?? 0.00);
            $observaciones = trim($_POST['observaciones'] ?? '');

            $stmtEf = $pdo->prepare("SELECT COALESCE(SUM(total_pagado),0) FROM pagos_caja WHERE substr(fecha_pago,1,10) = ? AND metodo_pago = 'efectivo'");
            $stmtEf->execute([$hoy]);
            $ingEfectivo = (float)$stmtEf->fetchColumn();

            $stmtTar = $pdo->prepare("SELECT COALESCE(SUM(total_pagado),0) FROM pagos_caja WHERE substr(fecha_pago,1,10) = ? AND metodo_pago IN ('tarjeta_credito','tarjeta_debito')");
            $stmtTar->execute([$hoy]);
            $ingTarjeta = (float)$stmtTar->fetchColumn();

            $stmtTr = $pdo->prepare("SELECT COALESCE(SUM(total_pagado),0) FROM pagos_caja WHERE substr(fecha_pago,1,10) = ? AND metodo_pago = 'transferencia'");
            $stmtTr->execute([$hoy]);
            $ingTransf = (float)$stmtTr->fetchColumn();

            $stmtGas = $pdo->prepare("SELECT COALESCE(SUM(monto),0) FROM gastos_operativos WHERE fecha_gasto = ? AND metodo_pago = 'efectivo_caja'");
            $stmtGas->execute([$hoy]);
            $gasEfectivo = (float)$stmtGas->fetchColumn();

            $efectivoEsperado = $fondoInicial + $ingEfectivo - $gasEfectivo;
            $diferencia = round($efectivoContado - $efectivoEsperado, 2);
            $totalNeto = ($ingEfectivo + $ingTarjeta + $ingTransf) - $gasEfectivo;

            $stmtCorte = $pdo->prepare("INSERT INTO cortes_caja (fecha_corte, turno, fondo_inicial_efectivo, ingresos_efectivo, ingresos_tarjeta, ingresos_transferencia, gastos_efectivo, efectivo_esperado, efectivo_contado_fisico, diferencia_arqueo, total_neto_dia, observaciones, cerrado_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmtCorte->execute([$hoy, $turno, $fondoInicial, $ingEfectivo, $ingTarjeta, $ingTransf, $gasEfectivo, $efectivoEsperado, $efectivoContado, $diferencia, $totalNeto, $observaciones, $usuario['id']]);

            $mensajeExito = "Corte de caja guardado correctamente.";
            $vista = 'corte';
        }
    }
}

// Consultas para las vistas
$stmtCitas = $pdo->prepare("
    SELECT c.*,
           p.nombre_completo AS paciente_nombre, p.telefono_whatsapp AS paciente_tel, p.folio_expediente,
           p.sesiones_disponibles_nutri, p.sesiones_disponibles_psico, p.checkups_disponibles, p.saldo_pendiente,
           u.nombre AS doctor_nombre,
           s.nombre AS servicio_nombre
    FROM citas c
    LEFT JOIN pacientes p ON c.paciente_id = p.id
    JOIN usuarios u ON c.nutriologo_id = u.id
    LEFT JOIN catalogo_servicios s ON c.servicio_id = s.id
    WHERE c.fecha_cita = ?
    ORDER BY c.hora_inicio ASC
");
$stmtCitas->execute([$fechaFiltro]);
$citasDia = $stmtCitas->fetchAll();

$pacientesLista = $pdo->query("SELECT id, folio_expediente, nombre_completo, sesiones_disponibles_nutri, sesiones_disponibles_psico, checkups_disponibles, saldo_pendiente FROM pacientes ORDER BY nombre_completo ASC")->fetchAll();
$especialistas = $pdo->query("SELECT id, nombre, area_clinica, consultorio_asignado FROM usuarios WHERE rol IN ('nutriologo','psicologo') AND activo = 1 ORDER BY id ASC")->fetchAll();
$servicios = $pdo->query("SELECT * FROM catalogo_servicios WHERE activo = 1 ORDER BY id ASC")->fetchAll();

$citaParaCobrar = null;
if (isset($_GET['cobrar_cita_id'])) {
    $cid = (int)$_GET['cobrar_cita_id'];
    foreach ($citasDia as $cd) {
        if ((int)$cd['id'] === $cid) {
            $citaParaCobrar = $cd;
            break;
        }
    }
}

$stmtPagosHoy = $pdo->prepare("
    SELECT pg.*, p.nombre_completo AS paciente_nombre, u.nombre AS doctor_nombre
    FROM pagos_caja pg
    JOIN pacientes p ON pg.paciente_id = p.id
    JOIN usuarios u ON pg.nutriologo_id = u.id
    WHERE substr(pg.fecha_pago, 1, 10) = ?
    ORDER BY pg.id DESC
");
$stmtPagosHoy->execute([$hoy]);
$pagosHoy = $stmtPagosHoy->fetchAll();

$totalCobradoHoy = 0.0;
$efectivoHoy = 0.0;
$tarjetaHoy = 0.0;
$transferenciaHoy = 0.0;
foreach ($pagosHoy as $ph) {
    $totalCobradoHoy += (float)$ph['total_pagado'];
    if ($ph['metodo_pago'] === 'efectivo') $efectivoHoy += (float)$ph['total_pagado'];
    elseif (in_array($ph['metodo_pago'], ['tarjeta_credito', 'tarjeta_debito'], true)) $tarjetaHoy += (float)$ph['total_pagado'];
    elseif ($ph['metodo_pago'] === 'transferencia') $transferenciaHoy += (float)$ph['total_pagado'];
}

$stmtGasEf = $pdo->prepare("SELECT COALESCE(SUM(monto),0) FROM gastos_operativos WHERE fecha_gasto = ? AND metodo_pago = 'efectivo_caja'");
$stmtGasEf->execute([$hoy]);
$gastosEfectivoHoy = (float)$stmtGasEf->fetchColumn();

$reciboDetalle = null;
$idReciboVer = isset($_GET['recibo_id']) ? (int)$_GET['recibo_id'] : $reciboRecienCreado;
if ($idReciboVer) {
    $stmtRec = $pdo->prepare("
        SELECT pg.*, p.nombre_completo, p.folio_expediente, p.rfc, u.nombre AS especialista_nombre, u.cedula_profesional
        FROM pagos_caja pg
        JOIN pacientes p ON pg.paciente_id = p.id
        JOIN usuarios u ON pg.nutriologo_id = u.id
        WHERE pg.id = ?
    ");
    $stmtRec->execute([$idReciboVer]);
    $reciboDetalle = $stmtRec->fetch();
}

$subPills = [
    ['label' => 'Sala de Espera y Agenda', 'href' => 'recepcion.php?vista=agenda', 'active' => ($vista === 'agenda'), 'count' => count($citasDia)],
    ['label' => 'Caja POS (Cobros)', 'href' => 'recepcion.php?vista=caja', 'active' => ($vista === 'caja'), 'count' => count($pagosHoy)],
    ['label' => 'Corte de Caja', 'href' => 'recepcion.php?vista=corte', 'active' => ($vista === 'corte'), 'count' => null],
];

$actionTop = ($vista === 'agenda')
    ? '<button type="button" onclick="openModal(\'modalNuevaCita\')" class="btn btn-primary btn-sm">+ Nueva Cita</button>'
    : '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mostrador y Caja | <?= htmlspecialchars(CLINIC_NAME) ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<?php renderAppStart('Mostrador y Caja', 'Control de sala de espera, flujo de turnos y cobro de consultas', 'recepcion', $subPills, $actionTop); ?>

<?php if ($mensajeExito): ?>
    <div class="alert alert-success">
        <span><?= htmlspecialchars($mensajeExito) ?></span>
        <?php if ($reciboRecienCreado): ?>
            <a href="recepcion.php?vista=caja&recibo_id=<?= $reciboRecienCreado ?>" class="btn btn-outline btn-xs">Ver recibo impreso</a>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($mensajeError): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($mensajeError) ?></div>
<?php endif; ?>

<?php if ($vista === 'agenda'): ?>
    <!-- =========================================================
         VISTA 1: SALA DE ESPERA Y AGENDA DEL DÍA (UN SOLO PROPÓSITO)
         ========================================================= -->
    <div class="kpi-row">
        <?php
        $enSala = array_filter($citasDia, fn($c) => $c['estado'] === 'en_sala');
        $enConsulta = array_filter($citasDia, fn($c) => $c['estado'] === 'en_consulta');
        $porCobrar = array_filter($citasDia, fn($c) => $c['estado'] === 'por_cobrar');
        ?>
        <div class="kpi-card">
            <span class="kpi-label">Citas Programadas Hoy</span>
            <div class="kpi-value"><?= count($citasDia) ?></div>
            <div class="kpi-sub">Fecha: <?= htmlspecialchars($fechaFiltro) ?></div>
        </div>
        <div class="kpi-card">
            <span class="kpi-label">En Sala de Espera</span>
            <div class="kpi-value" style="color:var(--warning);"><?= count($enSala) ?></div>
            <div class="kpi-sub">Listos para pasar a consultorio</div>
        </div>
        <div class="kpi-card">
            <span class="kpi-label">En Consultorio</span>
            <div class="kpi-value" style="color:var(--primary);"><?= count($enConsulta) ?></div>
            <div class="kpi-sub">Atención clínica activa</div>
        </div>
        <div class="kpi-card">
            <span class="kpi-label">Por Liquidar en Caja</span>
            <div class="kpi-value" style="color:var(--danger);"><?= count($porCobrar) ?></div>
            <div class="kpi-sub">Pendientes de cobro al salir</div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div>
                <h2>Agenda y Flujo de Pacientes</h2>
                <p>Cambia el estado del paciente conforme avanza desde la recepción hasta el consultorio y caja.</p>
            </div>
            <form method="GET" class="flex-gap">
                <input type="hidden" name="vista" value="agenda">
                <input type="date" name="fecha" value="<?= htmlspecialchars($fechaFiltro) ?>" class="form-control" style="width:165px;" onchange="this.form.submit()">
            </form>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Turno / Hora</th>
                        <th>Paciente</th>
                        <th>Servicio y Consultorio</th>
                        <th>Estado Actual</th>
                        <th class="text-right">Acción Siguiente</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($citasDia)): ?>
                        <tr>
                            <td colspan="5" class="text-center text-muted" style="padding:32px;">No hay citas programadas para esta fecha.</td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($citasDia as $c):
                        $nombrePac = $c['paciente_nombre'] ?: ($c['nombre_prospecto'] . ' (1ª Vez)');
                        $telPac = $c['paciente_tel'] ?: ($c['telefono_prospecto'] ?? '');
                        $badgeEstado = match($c['estado']) {
                            'en_sala' => 'badge-amber',
                            'en_consulta' => 'badge-blue',
                            'por_cobrar' => 'badge-rose',
                            'finalizada' => 'badge-teal',
                            default => 'badge-slate'
                        };
                        $waLink = $telPac ? generarLinkWhatsApp($telPac, $nombrePac, $c['fecha_cita'], substr($c['hora_inicio'], 0, 5), $c['doctor_nombre'], strtoupper($c['area_cita']), (bool)$c['requiere_ayuno']) : '';
                    ?>
                        <tr>
                            <td>
                                <span class="turno-pill"><?= htmlspecialchars($c['turno_sala'] ?? 'S/T') ?></span>
                                <div class="text-xs text-muted" style="margin-top:4px;"><?= substr($c['hora_inicio'], 0, 5) ?> hrs</div>
                            </td>
                            <td>
                                <div class="avatar-row">
                                    <?php if ($c['paciente_id']): ?>
                                        <img src="<?= htmlspecialchars(getFotoPaciente((int)$c['paciente_id'])) ?>" alt="" class="avatar-sm">
                                    <?php endif; ?>
                                    <div>
                                        <strong><?= htmlspecialchars($nombrePac) ?></strong>
                                        <div class="text-xs text-muted">
                                            <?= htmlspecialchars($c['motivo_notas'] ?? '') ?>
                                            <?php if ((int)$c['requiere_ayuno'] === 1): ?>
                                                · <span style="color:var(--warning);font-weight:600;">Ayuno 8-10h</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars($c['servicio_nombre'] ?? ucfirst($c['area_cita'])) ?></strong>
                                <div class="text-xs text-muted"><?= htmlspecialchars($c['doctor_nombre']) ?> · <?= htmlspecialchars($c['consultorio']) ?></div>
                            </td>
                            <td>
                                <span class="badge <?= $badgeEstado ?>"><?= strtoupper(str_replace('_', ' ', $c['estado'])) ?></span>
                                <?php if ((int)$c['pagado'] === 1): ?>
                                    <span class="badge badge-teal">PAGADO</span>
                                <?php else: ?>
                                    <div class="text-xs text-muted" style="margin-top:3px;">$<?= number_format((float)$c['costo_estimado'], 2) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-right">
                                <div class="flex-gap" style="justify-content:flex-end;">
                                    <!-- Flujo paso a paso claro -->
                                    <?php if (in_array($c['estado'], ['pendiente_confirmar', 'confirmada'], true)): ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                                            <input type="hidden" name="accion" value="cambiar_estado_cita">
                                            <input type="hidden" name="cita_id" value="<?= (int)$c['id'] ?>">
                                            <input type="hidden" name="nuevo_estado" value="en_sala">
                                            <button type="submit" class="btn btn-outline btn-xs">Marcar en Sala</button>
                                        </form>
                                    <?php elseif ($c['estado'] === 'en_sala'): ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                                            <input type="hidden" name="accion" value="cambiar_estado_cita">
                                            <input type="hidden" name="cita_id" value="<?= (int)$c['id'] ?>">
                                            <input type="hidden" name="nuevo_estado" value="en_consulta">
                                            <button type="submit" class="btn btn-primary btn-xs">Pasar a Consultorio</button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if ($c['paciente_id']): ?>
                                        <a href="consulta.php?paciente_id=<?= (int)$c['paciente_id'] ?>" class="btn btn-outline btn-xs">Expediente</a>
                                    <?php endif; ?>

                                    <?php if ((int)$c['pagado'] === 0): ?>
                                        <a href="recepcion.php?vista=caja&cobrar_cita_id=<?= (int)$c['id'] ?>" class="btn btn-accent btn-xs">Cobrar</a>
                                    <?php endif; ?>

                                    <?php if ($waLink): ?>
                                        <a href="<?= htmlspecialchars($waLink) ?>" target="_blank" class="btn btn-outline btn-xs" title="Recordatorio por WhatsApp">WhatsApp</a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- MODAL PARA NUEVA CITA DE MOSTRADOR -->
    <div id="modalNuevaCita" class="modal-backdrop">
        <div class="modal-dialog">
            <div class="modal-header">
                <h3>Registrar Cita en Mostrador</h3>
                <button type="button" class="modal-close" onclick="closeModal('modalNuevaCita')">&times;</button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                    <input type="hidden" name="accion" value="agendar_mostrador">

                    <div class="form-group">
                        <label>Paciente de Cartera (o dejar vacío si es 1ª vez)</label>
                        <select name="paciente_id" class="form-control">
                            <option value="">-- Paciente Nuevo (1ª Vez) --</option>
                            <?php foreach ($pacientesLista as $pl): ?>
                                <option value="<?= (int)$pl['id'] ?>">
                                    <?= htmlspecialchars($pl['folio_expediente'] . ' · ' . $pl['nombre_completo']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Nombre (si es 1ª vez)</label>
                            <input type="text" name="nombre_prospecto" class="form-control" placeholder="Nombre completo">
                        </div>
                        <div class="form-group">
                            <label>Teléfono WhatsApp</label>
                            <input type="text" name="telefono_prospecto" class="form-control" placeholder="10 dígitos">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Servicio o Checkup *</label>
                            <select name="servicio_id" class="form-control" required>
                                <?php foreach ($servicios as $s): ?>
                                    <option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['nombre']) ?> ($<?= number_format((float)$s['precio'], 0) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Especialista *</label>
                            <select name="nutriologo_id" class="form-control" required>
                                <?php foreach ($especialistas as $e): ?>
                                    <option value="<?= (int)$e['id'] ?>"><?= htmlspecialchars($e['nombre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-row-3">
                        <div class="form-group">
                            <label>Fecha</label>
                            <input type="date" name="fecha_cita" class="form-control" value="<?= htmlspecialchars($fechaFiltro) ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Hora</label>
                            <input type="time" name="hora_inicio" class="form-control" value="<?= date('H:00') ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Estado inicial</label>
                            <select name="estado_inicial" class="form-control">
                                <option value="en_sala">Ya en Sala de Espera</option>
                                <option value="confirmada">Confirmada</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Notas de recepción</label>
                        <input type="text" name="motivo_notas" class="form-control" placeholder="Ej. Viene en ayuno de 10h para Checkup">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('modalNuevaCita')">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Guardar Cita</button>
                </div>
            </form>
        </div>
    </div>

<?php elseif ($vista === 'caja'): ?>
    <!-- =========================================================
         VISTA 2: CAJA POS (ENFOCADA EXCLUSIVAMENTE EN COBROS)
         ========================================================= -->
    <?php if ($reciboDetalle): ?>
        <div class="card" style="margin-bottom:24px;border:2px solid var(--primary);">
            <div class="flex-between no-print" style="margin-bottom:16px;">
                <span class="badge badge-teal">Comprobante de Pago POS</span>
                <div class="flex-gap">
                    <button onclick="window.print()" class="btn btn-primary btn-sm">Imprimir Recibo</button>
                    <a href="facturacion.php?vista=timbrar&pago_id=<?= (int)$reciboDetalle['id'] ?>" class="btn btn-outline btn-sm">Timbrar CFDI 4.0</a>
                    <a href="recepcion.php?vista=caja" class="btn btn-outline btn-sm">Cerrar vista previa</a>
                </div>
            </div>
            <div class="grid-3" style="font-size:0.88rem;border-top:1px solid var(--border);padding-top:14px;">
                <div>
                    <div class="text-xs text-muted">Folio de Recibo</div>
                    <strong><?= htmlspecialchars($reciboDetalle['folio_recibo']) ?></strong>
                    <div class="text-xs text-muted"><?= htmlspecialchars($reciboDetalle['fecha_pago']) ?></div>
                </div>
                <div>
                    <div class="text-xs text-muted">Paciente</div>
                    <strong><?= htmlspecialchars($reciboDetalle['nombre_completo']) ?></strong>
                    <div class="text-xs text-muted">Atendió: <?= htmlspecialchars($reciboDetalle['especialista_nombre']) ?></div>
                </div>
                <div>
                    <div class="text-xs text-muted">Concepto e Importe Pagado</div>
                    <strong><?= htmlspecialchars($reciboDetalle['concepto']) ?></strong>
                    <div style="font-size:1.15rem;font-weight:700;color:var(--primary);">$<?= number_format((float)$reciboDetalle['total_pagado'], 2) ?> MXN (<?= strtoupper(htmlspecialchars($reciboDetalle['metodo_pago'])) ?>)</div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="grid-2">
        <!-- Formulario POS -->
        <div class="card">
            <div class="card-header">
                <div>
                    <h2>Registrar Cobro en Caja</h2>
                    <p>Selecciona el paciente y el servicio para emitir su recibo o descontar de su paquete.</p>
                </div>
            </div>

            <form method="POST" action="recepcion.php?vista=caja">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="accion" value="cobrar_pos">
                <?php if ($citaParaCobrar): ?>
                    <input type="hidden" name="cita_id" value="<?= (int)$citaParaCobrar['id'] ?>">
                    <div class="alert alert-info">
                        <span>Cobrando turno <strong><?= htmlspecialchars($citaParaCobrar['turno_sala']) ?></strong> de <?= htmlspecialchars($citaParaCobrar['paciente_nombre'] ?? $citaParaCobrar['nombre_prospecto']) ?></span>
                    </div>
                <?php endif; ?>

                <div class="form-group">
                    <label>Paciente *</label>
                    <select name="paciente_id" class="form-control" required>
                        <?php foreach ($pacientesLista as $pl): ?>
                            <option value="<?= (int)$pl['id'] ?>" <?= ($citaParaCobrar && (int)$citaParaCobrar['paciente_id'] === (int)$pl['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($pl['nombre_completo']) ?>
                                (Sesiones: <?= (int)$pl['sesiones_disponibles_nutri'] ?>N / <?= (int)$pl['sesiones_disponibles_psico'] ?>P / <?= (int)$pl['checkups_disponibles'] ?>CHK)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Servicio o Paquete *</label>
                        <select name="servicio_id" id="pos-servicio" class="form-control" onchange="actualizarPrecioPOS()" required>
                            <?php foreach ($servicios as $s): ?>
                                <option value="<?= (int)$s['id'] ?>"
                                        data-precio="<?= (float)$s['precio'] ?>"
                                        data-nombre="<?= htmlspecialchars($s['nombre']) ?>"
                                        <?= ($citaParaCobrar && (int)$citaParaCobrar['servicio_id'] === (int)$s['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($s['nombre']) ?> — $<?= number_format((float)$s['precio'], 2) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Especialista que atendió *</label>
                        <select name="nutriologo_id" class="form-control" required>
                            <?php foreach ($especialistas as $e): ?>
                                <option value="<?= (int)$e['id'] ?>" <?= ($citaParaCobrar && (int)$citaParaCobrar['nutriologo_id'] === (int)$e['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($e['nombre']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label>Concepto en Recibo *</label>
                    <input type="text" name="concepto" id="pos-concepto" class="form-control" value="<?= htmlspecialchars($citaParaCobrar['servicio_nombre'] ?? $servicios[0]['nombre']) ?>" required>
                </div>

                <div class="form-row-3">
                    <div class="form-group">
                        <label>Importe ($)</label>
                        <input type="number" step="0.01" name="monto" id="pos-monto" class="form-control" value="<?= (float)($citaParaCobrar['costo_estimado'] ?? $servicios[0]['precio']) ?>" oninput="recalcularNetoPOS()" required>
                    </div>
                    <div class="form-group">
                        <label>Descuento ($)</label>
                        <input type="number" step="0.01" name="descuento" id="pos-descuento" class="form-control" value="0.00" oninput="recalcularNetoPOS()">
                    </div>
                    <div class="form-group">
                        <label>Total que Paga ($)</label>
                        <input type="number" step="0.01" name="total_pagado" id="pos-pagado" class="form-control" value="<?= (float)($citaParaCobrar['costo_estimado'] ?? $servicios[0]['precio']) ?>" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Método de Pago *</label>
                        <select name="metodo_pago" class="form-control">
                            <option value="efectivo">Efectivo en Caja</option>
                            <option value="tarjeta_credito">Tarjeta de Crédito (Terminal)</option>
                            <option value="tarjeta_debito">Tarjeta de Débito (Terminal)</option>
                            <option value="transferencia">Transferencia SPEI</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Referencia / Voucher</label>
                        <input type="text" name="referencia_pago" class="form-control" placeholder="Ej. AUT-441029">
                    </div>
                </div>

                <div style="display:flex;flex-direction:column;gap:8px;margin:10px 0 18px;font-size:0.86rem;">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                        <input type="checkbox" name="usar_sesion_paquete" value="1">
                        <span>Descontar sesión del paquete prepagado del paciente ($0 a cobrar hoy)</span>
                    </label>
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                        <input type="checkbox" name="requiere_factura" value="1">
                        <span>El paciente solicita Factura Fiscal CFDI 4.0</span>
                    </label>
                </div>

                <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;padding:11px;">
                    Confirmar Cobro y Emitir Recibo
                </button>
            </form>
        </div>

        <!-- Movimientos cobrados hoy -->
        <div class="card">
            <div class="card-header">
                <div>
                    <h2>Recibos Emitidos Hoy</h2>
                    <p>Total cobrado en el día: <strong>$<?= number_format($totalCobradoHoy, 2) ?> MXN</strong></p>
                </div>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Folio</th>
                            <th>Paciente / Concepto</th>
                            <th>Método</th>
                            <th>Total</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pagosHoy as $pg): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($pg['folio_recibo']) ?></strong>
                                    <div class="text-xs text-muted"><?= substr($pg['fecha_pago'], 11, 5) ?> hrs</div>
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars($pg['paciente_nombre']) ?></strong>
                                    <div class="text-xs text-muted"><?= htmlspecialchars($pg['concepto']) ?></div>
                                </td>
                                <td><span class="badge badge-slate"><?= strtoupper(str_replace('_', ' ', $pg['metodo_pago'])) ?></span></td>
                                <td><strong style="color:var(--primary);">$<?= number_format((float)$pg['total_pagado'], 2) ?></strong></td>
                                <td class="text-right">
                                    <div class="flex-gap" style="justify-content:flex-end;">
                                        <a href="recepcion.php?vista=caja&recibo_id=<?= (int)$pg['id'] ?>" class="btn btn-outline btn-xs">Recibo</a>
                                        <?php if ($pg['estado_factura'] === 'pendiente'): ?>
                                            <a href="facturacion.php?vista=timbrar&pago_id=<?= (int)$pg['id'] ?>" class="btn btn-accent btn-xs">Facturar</a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

<?php elseif ($vista === 'corte'): ?>
    <!-- =========================================================
         VISTA 3: CORTE DE CAJA DIARIO (UN SOLO PROPÓSITO)
         ========================================================= -->
    <?php
    $efectivoEsperadoHoy = 1000.00 + $efectivoHoy - $gastosEfectivoHoy;
    $cortesPrevios = $pdo->query("SELECT c.*, u.nombre AS operador FROM cortes_caja c JOIN usuarios u ON c.cerrado_por = u.id ORDER BY c.id DESC LIMIT 8")->fetchAll();
    ?>
    <div class="kpi-row">
        <div class="kpi-card">
            <span class="kpi-label">Ingresos Efectivo Hoy</span>
            <div class="kpi-value">$<?= number_format($efectivoHoy, 2) ?></div>
            <div class="kpi-sub">+ $1,000.00 fondo fijo inicial</div>
        </div>
        <div class="kpi-card">
            <span class="kpi-label">Terminal Bancaria (TDC / TDD)</span>
            <div class="kpi-value">$<?= number_format($tarjetaHoy, 2) ?></div>
            <div class="kpi-sub">Vouchers conciliados</div>
        </div>
        <div class="kpi-card">
            <span class="kpi-label">Transferencias SPEI</span>
            <div class="kpi-value">$<?= number_format($transferenciaHoy, 2) ?></div>
            <div class="kpi-sub">Depósito directo en cuenta</div>
        </div>
        <div class="kpi-card">
            <span class="kpi-label">Efectivo Esperado en Cajón</span>
            <div class="kpi-value" style="color:var(--primary);">$<?= number_format($efectivoEsperadoHoy, 2) ?></div>
            <div class="kpi-sub">Descontando $<?= number_format($gastosEfectivoHoy, 2) ?> de gastos en efectivo</div>
        </div>
    </div>

    <div class="grid-2">
        <div class="card">
            <div class="card-header">
                <div>
                    <h2>Cerrar Arqueo de Caja</h2>
                    <p>Cuenta el efectivo físico en cajón para registrar el cierre del turno.</p>
                </div>
            </div>

            <form method="POST" action="recepcion.php?vista=corte">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="accion" value="cerrar_corte_caja">

                <div class="form-row">
                    <div class="form-group">
                        <label>Turno que cierra</label>
                        <select name="turno" class="form-control">
                            <option value="Turno Completo">Día Completo</option>
                            <option value="Turno Matutino">Turno Matutino</option>
                            <option value="Turno Vespertino">Turno Vespertino</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Fondo Inicial en Caja ($)</label>
                        <input type="number" step="0.01" name="fondo_inicial_efectivo" class="form-control" value="1000.00" required>
                    </div>
                </div>

                <div class="form-group">
                    <label>Efectivo Físico Contado en Cajón ($) *</label>
                    <input type="number" step="0.01" name="efectivo_contado_fisico" class="form-control" value="<?= number_format($efectivoEsperadoHoy, 2, '.', '') ?>" required>
                </div>

                <div class="form-group">
                    <label>Observaciones del cierre</label>
                    <textarea name="observaciones" class="form-control" rows="2" placeholder="Ej. Arqueo cuadrado sin diferencias, se deja fondo fijo en caja chica."></textarea>
                </div>

                <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;">
                    Guardar Corte de Caja
                </button>
            </form>
        </div>

        <div class="card">
            <div class="card-header">
                <div>
                    <h2>Historial de Cortes Cerrados</h2>
                    <p>Últimos cierres de turno registrados en el consultorio.</p>
                </div>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Fecha / Turno</th>
                            <th>Efectivo Contado</th>
                            <th>Diferencia</th>
                            <th>Total Neto</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cortesPrevios as $cp): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($cp['fecha_corte']) ?></strong>
                                    <div class="text-xs text-muted"><?= htmlspecialchars($cp['turno']) ?> · <?= htmlspecialchars($cp['operador']) ?></div>
                                </td>
                                <td>$<?= number_format((float)$cp['efectivo_contado_fisico'], 2) ?></td>
                                <td>
                                    <span class="badge <?= (float)$cp['diferencia_arqueo'] >= 0 ? 'badge-teal' : 'badge-rose' ?>">
                                        $<?= number_format((float)$cp['diferencia_arqueo'], 2) ?>
                                    </span>
                                </td>
                                <td><strong>$<?= number_format((float)$cp['total_neto_dia'], 2) ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<script>
function actualizarPrecioPOS() {
    var sel = document.getElementById('pos-servicio');
    if (!sel) return;
    var opt = sel.options[sel.selectedIndex];
    var precio = parseFloat(opt.getAttribute('data-precio') || '0');
    var nombre = opt.getAttribute('data-nombre') || '';
    document.getElementById('pos-monto').value = precio.toFixed(2);
    document.getElementById('pos-concepto').value = nombre;
    recalcularNetoPOS();
}
function recalcularNetoPOS() {
    var m = parseFloat(document.getElementById('pos-monto').value || '0');
    var d = parseFloat(document.getElementById('pos-descuento').value || '0');
    var neto = Math.max(0, m - d);
    document.getElementById('pos-pagado').value = neto.toFixed(2);
}
</script>

<?php renderAppEnd(); ?>
</body>
</html>
