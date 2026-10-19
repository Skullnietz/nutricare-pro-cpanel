<?php
require_once __DIR__ . '/db_connection.php';
$usuario = requireAuth();
$pdo = getDB();

$filtro = $_GET['filtro'] ?? 'todos';
$busqueda = trim($_GET['q'] ?? '');
$msgCartera = '';

// 1. Alta de Nuevo Paciente / Cliente en Cartera CRM + Datos Fiscales CFDI
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['nuevo_paciente_crm']) && verify_csrf($_POST['csrf_token'] ?? '')) {
    $folio = 'EXP-' . date('Y') . '-' . rand(100, 999);
    $nutriId = !empty($_POST['nutriologo_id']) ? (int)$_POST['nutriologo_id'] : null;
    $psicoId = !empty($_POST['psicologo_id']) ? (int)$_POST['psicologo_id'] : null;
    $modalidad = $_POST['modalidad_atencion'] ?? 'psiconutricion_integral';
    $nombre = trim($_POST['nombre_completo']);
    $fechaNac = $_POST['fecha_nacimiento'] ?: '1990-01-01';
    $sexo = $_POST['sexo'] ?? 'F';
    $telefono = trim($_POST['telefono_whatsapp']);
    $email = trim($_POST['email'] ?? '');
    $ocupacion = trim($_POST['ocupacion'] ?? '');
    $rfc = strtoupper(trim($_POST['rfc'] ?: 'XAXX010101000'));
    $razonSocial = strtoupper(trim($_POST['razon_social'] ?: $nombre));
    $regimen = $_POST['regimen_fiscal'] ?? '612';
    $cp = trim($_POST['codigo_postal_fiscal'] ?? '03100');
    $usoCfdi = $_POST['uso_cfdi'] ?? 'D01';
    $objetivo = trim($_POST['objetivo_principal'] ?: 'Evaluación nutricional, checkup y bienestar emocional');
    $motivoPsico = trim($_POST['motivo_psicologico'] ?? '');

    $stmt = $pdo->prepare("INSERT INTO pacientes (folio_expediente, nutriologo_id, psicologo_id, modalidad_atencion, nombre_completo, fecha_nacimiento, sexo, telefono_whatsapp, email, ocupacion, estatus_cartera, nombre_paquete_activo, ultima_visita, proxima_cita_sugerida, rfc, razon_social, regimen_fiscal, codigo_postal_fiscal, uso_cfdi, email_facturacion, objetivo_principal, motivo_psicologico) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'activo', 'Sin paquete (Pago por sesión)', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$folio, $nutriId, $psicoId, $modalidad, $nombre, $fechaNac, $sexo, $telefono, $email, $ocupacion, date('Y-m-d'), date('Y-m-d', strtotime('+7 days')), $rfc, $razonSocial, $regimen, $cp, $usoCfdi, $email, $objetivo, $motivoPsico]);

    header("Location: cartera.php?msg=alta_ok&folio=" . urlencode($folio));
    exit;
}

// 2. Registrar Abono / Liquidación rápida de Saldo Pendiente
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['liquidar_saldo_cartera']) && verify_csrf($_POST['csrf_token'] ?? '')) {
    $pacId = (int)$_POST['paciente_id'];
    $abono = (float)$_POST['monto_abono'];
    $metodo = $_POST['metodo_pago'] ?? 'transferencia';
    $folioRec = 'REC-ABO-' . rand(1000, 9999);

    if ($pacId > 0 && $abono > 0) {
        $pdo->prepare("UPDATE pacientes SET saldo_pendiente = MAX(0, saldo_pendiente - ?), total_historico_pagado = total_historico_pagado + ? WHERE id = ?")
            ->execute([$abono, $abono, $pacId]);

        $stmtInsPago = $pdo->prepare("INSERT INTO pagos_caja (folio_recibo, paciente_id, nutriologo_id, categoria_ingreso, concepto, monto, descuento, impuestos, total_pagado, saldo_pendiente_generado, metodo_pago, comision_especialista, registrado_por, fecha_pago) VALUES (?, ?, 1, 'abono_saldo', 'Abono / Liquidación de Saldo en Cartera CRM', ?, 0, 0, ?, 0, ?, ?, ?, ?)");
        $stmtInsPago->execute([$folioRec, $pacId, $abono, $abono, $metodo, round($abono * 0.70, 2), $usuario['id'], date('Y-m-d H:i:s')]);
    }
    header("Location: cartera.php?msg=abono_ok");
    exit;
}

// Consulta de Cartera de Clientes
$sql = "SELECT p.*, u1.nombre AS nutriologo_nombre, u2.nombre AS psicologo_nombre
        FROM pacientes p
        LEFT JOIN usuarios u1 ON p.nutriologo_id = u1.id
        LEFT JOIN usuarios u2 ON p.psicologo_id = u2.id
        WHERE 1=1";
$params = [];

if ($filtro === 'con_saldo') {
    $sql .= " AND p.saldo_pendiente > 0";
} elseif ($filtro === 'en_riesgo') {
    $sql .= " AND p.estatus_cartera = 'en_riesgo'";
} elseif ($filtro === 'con_paquete') {
    $sql .= " AND (p.sesiones_disponibles_nutri > 0 OR p.sesiones_disponibles_psico > 0 OR p.checkups_disponibles > 0)";
}

if ($busqueda !== '') {
    $sql .= " AND (p.nombre_completo LIKE :q OR p.folio_expediente LIKE :q OR p.rfc LIKE :q OR p.telefono_whatsapp LIKE :q)";
    $params['q'] = "%{$busqueda}%";
}

$sql .= " ORDER BY p.saldo_pendiente DESC, p.id ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$cartera = $stmt->fetchAll();

// KPIs globales de Cartera
$kpis = $pdo->query("SELECT
    COUNT(*) AS total_clientes,
    COALESCE(SUM(saldo_pendiente), 0) AS total_por_cobrar,
    COALESCE(SUM(total_historico_pagado), 0) AS ltv_total,
    COALESCE(SUM(sesiones_disponibles_nutri + sesiones_disponibles_psico + checkups_disponibles), 0) AS sesiones_prepagadas,
    SUM(CASE WHEN estatus_cartera = 'en_riesgo' THEN 1 ELSE 0 END) AS clientes_riesgo
    FROM pacientes")->fetch();

$nutriologos = $pdo->query("SELECT id, nombre FROM usuarios WHERE rol = 'nutriologo' AND activo = 1")->fetchAll();
$psicologos  = $pdo->query("SELECT id, nombre FROM usuarios WHERE rol = 'psicologo' AND activo = 1")->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cartera de Clientes CRM y Cuentas por Cobrar | <?= htmlspecialchars(CLINIC_NAME) ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <?php renderNavbar('cartera'); ?>

    <main class="container section-pad">
        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'alta_ok'): ?>
            <div class="result-box">✅ Nuevo expediente clínico y fiscal creado en Cartera: <strong><?= htmlspecialchars($_GET['folio'] ?? '') ?></strong>.</div>
        <?php elseif (isset($_GET['msg']) && $_GET['msg'] === 'abono_ok'): ?>
            <div class="result-box">✅ Abono registrado en Caja POS y descontado del saldo pendiente del cliente.</div>
        <?php endif; ?>

        <!-- KPIS DE CARTERA DE CLIENTES -->
        <div class="grid-4" style="margin-bottom:20px;">
            <div class="kpi-card">
                <div class="kpi-title">Pacientes Activos en Cartera</div>
                <div class="kpi-value"><?= (int)$kpis['total_clientes'] ?> expedientes</div>
                <div class="kpi-sub">Valor Histórico (LTV): $<?= number_format((float)$kpis['ltv_total'], 2) ?></div>
            </div>
            <div class="kpi-card rose">
                <div class="kpi-title">Cuentas por Cobrar (Saldos)</div>
                <div class="kpi-value">$<?= number_format((float)$kpis['total_por_cobrar'], 2) ?></div>
                <div class="kpi-sub">Cobranza automatizada por WhatsApp</div>
            </div>
            <div class="kpi-card blue">
                <div class="kpi-title">Sesiones y Checkups Prepagados</div>
                <div class="kpi-value"><?= (int)$kpis['sesiones_prepagadas'] ?> disponibles</div>
                <div class="kpi-sub">En paquetes activos de clientes</div>
            </div>
            <div class="kpi-card amber">
                <div class="kpi-title">Alerta de Retención / Riesgo</div>
                <div class="kpi-value"><?= (int)$kpis['clientes_riesgo'] ?> pacientes</div>
                <div class="kpi-sub">Requieren contacto de seguimiento</div>
            </div>
        </div>

        <div class="card">
            <div class="flex-between" style="margin-bottom:14px;">
                <div>
                    <h2>👥 Cartera de Clientes, Membresías, Checkups y Cuentas por Cobrar</h2>
                    <p class="muted">Seguimiento integral de pacientes de Nutrición, Psicología, Psiconutrición y Checkups con datos fiscales CFDI 4.0.</p>
                </div>
                <form method="GET" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                    <input type="text" name="q" value="<?= htmlspecialchars($busqueda) ?>" placeholder="Buscar nombre, expediente, RFC..." style="width:220px; margin:0;">
                    <select name="filtro" style="width:auto; margin:0;" onchange="this.form.submit()">
                        <option value="todos" <?= $filtro === 'todos' ? 'selected' : '' ?>>Todos los Clientes</option>
                        <option value="con_saldo" <?= $filtro === 'con_saldo' ? 'selected' : '' ?>>💳 Con Saldo Pendiente por Cobrar</option>
                        <option value="con_paquete" <?= $filtro === 'con_paquete' ? 'selected' : '' ?>>🎟️ Con Paquete / Checkup Disponible</option>
                        <option value="en_riesgo" <?= $filtro === 'en_riesgo' ? 'selected' : '' ?>>⚠️ En Riesgo de Abandono</option>
                    </select>
                    <button type="submit" class="btn btn-outline btn-sm">Buscar</button>
                </form>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Expediente / Cliente</th>
                            <th>Equipo Clínico Asignado</th>
                            <th>Paquete Activo / Sesiones Restantes</th>
                            <th>Datos Fiscales CFDI</th>
                            <th>Estado Financiero (LTV / Saldo)</th>
                            <th>Acciones CRM y Cobranza</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cartera as $p): ?>
                        <tr>
                            <td>
                                <span class="badge badge-slate"><?= htmlspecialchars($p['folio_expediente']) ?></span>
                                <?php if ($p['estatus_cartera'] === 'en_riesgo'): ?>
                                    <span class="badge badge-rose">⚠️ En Riesgo</span>
                                <?php else: ?>
                                    <span class="badge badge-teal">Activo</span>
                                <?php endif; ?>
                                <br>
                                <strong><?= htmlspecialchars($p['nombre_completo']) ?></strong> (<?= calcularEdad($p['fecha_nacimiento']) ?> años)<br>
                                <small class="muted">📞 <?= htmlspecialchars($p['telefono_whatsapp']) ?> · ✉️ <?= htmlspecialchars($p['email']) ?></small>
                            </td>
                            <td>
                                <small>
                                    🥗 <strong>Nutrición/Checkup:</strong> <?= htmlspecialchars($p['nutriologo_nombre'] ?: 'No asignado') ?><br>
                                    🧠 <strong>Psicología/TCA:</strong> <?= htmlspecialchars($p['psicologo_nombre'] ?: 'No asignado') ?><br>
                                    📌 <span class="muted">Última visita: <?= htmlspecialchars($p['ultima_visita'] ?: '—') ?></span>
                                </small>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars($p['nombre_paquete_activo'] ?: 'Pago por sesión') ?></strong><br>
                                <span class="badge badge-teal">🥗 Nut: <?= (int)$p['sesiones_disponibles_nutri'] ?></span>
                                <span class="badge badge-purple">🧠 Psi: <?= (int)$p['sesiones_disponibles_psico'] ?></span>
                                <span class="badge badge-blue">🔬 Checkup: <?= (int)$p['checkups_disponibles'] ?></span>
                            </td>
                            <td>
                                <strong>RFC: <?= htmlspecialchars($p['rfc']) ?></strong><br>
                                <small class="muted">Régimen: <?= htmlspecialchars($p['regimen_fiscal']) ?> · CP: <?= htmlspecialchars($p['codigo_postal_fiscal'] ?: '03100') ?> · Uso: <?= htmlspecialchars($p['uso_cfdi']) ?></small>
                            </td>
                            <td>
                                <small class="muted">Pagado Histórico: $<?= number_format((float)$p['total_historico_pagado'], 2) ?></small><br>
                                <?php if ($p['saldo_pendiente'] > 0): ?>
                                    <span class="badge badge-rose" style="font-size:12px;">Debe: $<?= number_format((float)$p['saldo_pendiente'], 2) ?></span>
                                <?php else: ?>
                                    <span class="badge badge-teal">Al corriente ($0.00)</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="display:flex; gap:5px; flex-wrap:wrap;">
                                    <a href="consulta.php?paciente_id=<?= (int)$p['id'] ?>" class="btn btn-primary btn-sm">🩺 Expediente y Checkup</a>
                                    <a href="facturacion.php?paciente_id=<?= (int)$p['id'] ?>" class="btn btn-outline btn-sm">🧾 Facturar CFDI</a>
                                    <a href="<?= generarLinkCobranzaWhatsApp((string)$p['telefono_whatsapp'], (string)$p['nombre_completo'], (float)$p['saldo_pendiente'], (string)$p['nombre_paquete_activo']) ?>" target="_blank" class="btn btn-whatsapp btn-sm">
                                        <?= $p['saldo_pendiente'] > 0 ? '💬 Cobrar WhatsApp' : '💬 Seguimiento' ?>
                                    </a>
                                </div>
                                <?php if ($p['saldo_pendiente'] > 0): ?>
                                <form method="POST" style="margin-top:6px; display:flex; gap:4px; align-items:center;">
                                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                    <input type="hidden" name="liquidar_saldo_cartera" value="1">
                                    <input type="hidden" name="paciente_id" value="<?= (int)$p['id'] ?>">
                                    <input type="number" name="monto_abono" step="0.01" value="<?= (float)$p['saldo_pendiente'] ?>" style="width:90px; margin:0; padding:4px 6px;">
                                    <select name="metodo_pago" style="width:auto; margin:0; padding:4px 6px;">
                                        <option value="transferencia">SPEI</option>
                                        <option value="efectivo">Efectivo</option>
                                        <option value="tarjeta_credito">Tarjeta</option>
                                    </select>
                                    <button type="submit" class="btn btn-amber btn-sm">Liquidar</button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ALTA DE NUEVO CLIENTE / PACIENTE EN CARTERA CON DATOS FISCALES -->
        <div class="card">
            <h3>➕ Dar de Alta Nuevo Paciente en Cartera CRM (Expediente + Datos Fiscales CFDI 4.0)</h3>
            <form method="POST" class="grid-3">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="nuevo_paciente_crm" value="1">

                <label>Nombre Completo del Paciente *
                    <input type="text" name="nombre_completo" placeholder="Ej. Laura Elena Morales" required>
                </label>
                <label>Fecha de Nacimiento *
                    <input type="date" name="fecha_nacimiento" value="1990-06-15" required>
                </label>
                <label>Sexo *
                    <select name="sexo">
                        <option value="F">Femenino</option>
                        <option value="M">Masculino</option>
                    </select>
                </label>
                <label>Teléfono WhatsApp *
                    <input type="tel" name="telefono_whatsapp" placeholder="5512345678" required>
                </label>
                <label>Correo Electrónico (Expediente y Facturas)
                    <input type="email" name="email" placeholder="cliente@correo.com">
                </label>
                <label>Modalidad de Atención *
                    <select name="modalidad_atencion">
                        <option value="psiconutricion_integral">🤝 Integral (Nutrición + Psicología + Checkup)</option>
                        <option value="nutricion">🥗 Nutrición Clínica / Deportiva</option>
                        <option value="psicologia">🧠 Psicología Clínica / TCC</option>
                        <option value="checkup_preventivo">🔬 Checkup Preventivo 360°</option>
                    </select>
                </label>
                <label>Nutriólogo Titular Asignado
                    <select name="nutriologo_id">
                        <option value="">-- Sin asignar --</option>
                        <?php foreach ($nutriologos as $n): ?>
                            <option value="<?= (int)$n['id'] ?>"><?= htmlspecialchars($n['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Psicólogo Titular Asignado
                    <select name="psicologo_id">
                        <option value="">-- Sin asignar --</option>
                        <?php foreach ($psicologos as $ps): ?>
                            <option value="<?= (int)$ps['id'] ?>"><?= htmlspecialchars($ps['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Ocupación
                    <input type="text" name="ocupacion" placeholder="Ej. Ejecutivo / Estudiante / Deportista">
                </label>

                <label>RFC Receptor (Para Facturación CFDI 4.0)
                    <input type="text" name="rfc" value="XAXX010101000" maxlength="13">
                </label>
                <label>Razón Social Fiscal (Sin régimen societario)
                    <input type="text" name="razon_social" placeholder="Nombre o Razón Social en Constancia SAT">
                </label>
                <label>Régimen Fiscal SAT
                    <select name="regimen_fiscal">
                        <?php foreach (getRegimenesSAT() as $code => $label): ?>
                            <option value="<?= htmlspecialchars((string)$code) ?>" <?= (string)$code === '612' ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Código Postal Fiscal (Constancia SAT)
                    <input type="text" name="codigo_postal_fiscal" value="03100" maxlength="5">
                </label>
                <label>Uso de CFDI Predeterminado
                    <select name="uso_cfdi">
                        <?php foreach (getUsosCFDI() as $code => $label): ?>
                            <option value="<?= htmlspecialchars((string)$code) ?>"><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Objetivo Nutricional / Checkup *
                    <input type="text" name="objetivo_principal" placeholder="Ej. Checkup metabólico y pérdida de grasa" required>
                </label>
                <label style="grid-column: span 3;">Motivo Psicológico / Emocional (Opcional)
                    <input type="text" name="motivo_psicologico" placeholder="Ej. Manejo de ansiedad, estrés laboral o relación con la comida">
                </label>
                <div style="grid-column: span 3;">
                    <button type="submit" class="btn btn-primary">💾 Guardar Expediente y Ficha Fiscal en Cartera</button>
                </div>
            </form>
        </div>
    </main>
</body>
</html>
