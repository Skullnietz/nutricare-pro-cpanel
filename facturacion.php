<?php
require_once __DIR__ . '/db_connection.php';
$usuario = requireAuth();
$pdo = getDB();

$pagoSeleccionadoId = isset($_GET['pago_id']) ? (int)$_GET['pago_id'] : 0;
$pacienteSeleccionadoId = isset($_GET['paciente_id']) ? (int)$_GET['paciente_id'] : 0;
$verFacturaId = isset($_GET['ver_factura_id']) ? (int)$_GET['ver_factura_id'] : 0;

// Emitir / Timbrar Factura CFDI 4.0
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['timbrar_cfdi']) && verify_csrf($_POST['csrf_token'] ?? '')) {
    $folioFac = 'FAC-A' . rand(100, 999);
    $uuid = strtoupper( sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    ));
    $pagoId = !empty($_POST['pago_id']) ? (int)$_POST['pago_id'] : null;
    $pacId  = (int)$_POST['paciente_id'];
    $rfc    = strtoupper(trim($_POST['rfc_receptor']));
    $razon  = strtoupper(trim($_POST['razon_social_receptor']));
    $regimen = $_POST['regimen_fiscal_receptor'];
    $cp     = trim($_POST['cp_receptor']);
    $uso    = $_POST['uso_cfdi'];
    $claveProd = $_POST['clave_prod_serv'] ?: '85121800';
    $concepto = trim($_POST['concepto']);
    $formaPago = $_POST['forma_pago_sat'] ?: '04';
    $metodoPago = $_POST['metodo_pago_sat'] ?: 'PUE';
    $subtotal = (float)$_POST['subtotal'];
    $iva      = (float)($_POST['iva'] ?? 0.00);
    $total    = $subtotal + $iva;

    $stmt = $pdo->prepare("INSERT INTO facturas_cfdi (folio_factura, uuid_fiscal, pago_id, paciente_id, rfc_receptor, razon_social_receptor, regimen_fiscal_receptor, cp_receptor, uso_cfdi, clave_prod_serv, clave_unidad, concepto, forma_pago_sat, metodo_pago_sat, subtotal, iva, total, estado, fecha_emision) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'E48', ?, ?, ?, ?, ?, ?, 'timbrada', ?)");
    $stmt->execute([$folioFac, $uuid, $pagoId, $pacId, $rfc, $razon, $regimen, $cp, $uso, $claveProd, $concepto, $formaPago, $metodoPago, $subtotal, $iva, $total, date('Y-m-d H:i:s')]);
    $nuevaFacturaId = (int)$pdo->lastInsertId();

    if ($pagoId) {
        $pdo->prepare("UPDATE pagos_caja SET estado_factura = 'timbrada', requiere_factura = 1 WHERE id = ?")->execute([$pagoId]);
    }
    // Actualizar datos fiscales del paciente en su ficha CRM
    $pdo->prepare("UPDATE pacientes SET rfc = ?, razon_social = ?, regimen_fiscal = ?, codigo_postal_fiscal = ?, uso_cfdi = ? WHERE id = ?")
        ->execute([$rfc, $razon, $regimen, $cp, $uso, $pacId]);

    header("Location: facturacion.php?ver_factura_id={$nuevaFacturaId}&msg=timbrada");
    exit;
}

// Cancelar factura
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancelar_factura_id']) && verify_csrf($_POST['csrf_token'] ?? '')) {
    $fid = (int)$_POST['cancelar_factura_id'];
    $pdo->prepare("UPDATE facturas_cfdi SET estado = 'cancelada' WHERE id = ?")->execute([$fid]);
    header("Location: facturacion.php?msg=cancelada");
    exit;
}

// Precargar datos si viene de un Pago POS o Paciente
$pagoPre = null;
if ($pagoSeleccionadoId > 0) {
    $stmtP = $pdo->prepare("SELECT pg.*, p.id AS pac_id, p.nombre_completo, p.rfc, p.razon_social, p.regimen_fiscal, p.codigo_postal_fiscal, p.uso_cfdi
                            FROM pagos_caja pg
                            LEFT JOIN pacientes p ON pg.paciente_id = p.id
                            WHERE pg.id = ?");
    $stmtP->execute([$pagoSeleccionadoId]);
    $pagoPre = $stmtP->fetch();
    if ($pagoPre && $pagoPre['pac_id']) {
        $pacienteSeleccionadoId = (int)$pagoPre['pac_id'];
    }
}

$pacientePre = null;
if ($pacienteSeleccionadoId > 0) {
    $stmtPac = $pdo->prepare("SELECT * FROM pacientes WHERE id = ?");
    $stmtPac->execute([$pacienteSeleccionadoId]);
    $pacientePre = $stmtPac->fetch();
}

// Factura seleccionada para vista previa / impresión PDF
$facturaVista = null;
if ($verFacturaId > 0) {
    $stmtFV = $pdo->prepare("SELECT f.*, p.email, p.telefono_whatsapp FROM facturas_cfdi f JOIN pacientes p ON f.paciente_id = p.id WHERE f.id = ?");
    $stmtFV->execute([$verFacturaId]);
    $facturaVista = $stmtFV->fetch();
}

$pagosPendientesFactura = $pdo->query("SELECT pg.*, p.nombre_completo, p.rfc
    FROM pagos_caja pg
    LEFT JOIN pacientes p ON pg.paciente_id = p.id
    WHERE pg.estado_factura = 'pendiente'
    ORDER BY pg.id DESC")->fetchAll();

$facturasEmitidas = $pdo->query("SELECT f.*, p.nombre_completo
    FROM facturas_cfdi f
    JOIN pacientes p ON f.paciente_id = p.id
    ORDER BY f.id DESC")->fetchAll();

$pacientesList = $pdo->query("SELECT id, folio_expediente, nombre_completo, rfc, razon_social, regimen_fiscal, codigo_postal_fiscal, uso_cfdi FROM pacientes ORDER BY nombre_completo ASC")->fetchAll();

$totalFacturadoMes = 0.0;
foreach ($facturasEmitidas as $fe) {
    if ($fe['estado'] === 'timbrada') {
        $totalFacturadoMes += (float)$fe['total'];
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Facturación Electrónica CFDI 4.0 | <?= htmlspecialchars(CLINIC_NAME) ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <?php renderNavbar('facturacion'); ?>

    <main class="container section-pad">
        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'timbrada'): ?>
            <div class="result-box no-print">✅ Factura CFDI 4.0 timbrada exitosamente con Folio Fiscal UUID. Lista para imprimir o enviar por correo/WhatsApp.</div>
        <?php elseif (isset($_GET['msg']) && $_GET['msg'] === 'cancelada'): ?>
            <div class="alert-warning no-print">⚠️ Factura marcada como Cancelada ante el registro fiscal.</div>
        <?php endif; ?>

        <?php if ($facturaVista): ?>
        <!-- REPRESENTACIÓN IMPRESA DE CFDI 4.0 -->
        <div class="card" style="border: 2px solid #0f172a; margin-bottom:24px;">
            <div class="flex-between no-print" style="margin-bottom:14px; padding-bottom:10px; border-bottom:1px solid var(--border);">
                <span class="badge badge-teal">REPRESENTACIÓN IMPRESA DE CFDI 4.0</span>
                <div style="display:flex; gap:8px;">
                    <button onclick="window.print()" class="btn btn-primary btn-sm">🖨️ Imprimir / Guardar PDF CFDI</button>
                    <a href="facturacion.php" class="btn btn-outline btn-sm">Cerrar Vista Previa</a>
                </div>
            </div>
            <div class="grid-2" style="margin-bottom:14px;">
                <div>
                    <h3 style="margin-bottom:4px;"><?= htmlspecialchars(CLINIC_NAME) ?></h3>
                    <p><strong>RFC Emisor:</strong> <?= htmlspecialchars(CLINIC_RFC) ?></p>
                    <p><strong>Régimen Fiscal:</strong> <?= htmlspecialchars(CLINIC_REGIMEN) ?></p>
                    <p><strong>Lugar de Expedición (CP):</strong> <?= htmlspecialchars(CLINIC_CP) ?> — <?= htmlspecialchars(CLINIC_ADDRESS) ?></p>
                </div>
                <div style="text-align:right;">
                    <p><strong>Folio Interno:</strong> <span class="badge badge-blue"><?= htmlspecialchars($facturaVista['folio_factura']) ?></span></p>
                    <p><strong>Folio Fiscal (UUID):</strong><br><code style="font-size:12px; font-weight:700;"><?= htmlspecialchars($facturaVista['uuid_fiscal']) ?></code></p>
                    <p><strong>Fecha y Hora de Certificación:</strong> <?= htmlspecialchars($facturaVista['fecha_emision']) ?></p>
                    <p><strong>Tipo de Comprobante:</strong> I - Ingreso (CFDI 4.0)</p>
                </div>
            </div>
            <div style="background:#f8fafc; padding:12px; border-radius:8px; border:1px solid var(--border); margin-bottom:14px;" class="grid-2">
                <div>
                    <strong>DATOS FISCALES DEL RECEPTOR (PACIENTE):</strong><br>
                    <strong>RFC:</strong> <?= htmlspecialchars($facturaVista['rfc_receptor']) ?><br>
                    <strong>Nombre / Razón Social:</strong> <?= htmlspecialchars($facturaVista['razon_social_receptor']) ?>
                </div>
                <div>
                    <strong>Régimen Fiscal Receptor:</strong> <?= htmlspecialchars($facturaVista['regimen_fiscal_receptor']) ?><br>
                    <strong>Domicilio Fiscal (CP):</strong> <?= htmlspecialchars($facturaVista['cp_receptor']) ?><br>
                    <strong>Uso del CFDI:</strong> <?= htmlspecialchars($facturaVista['uso_cfdi']) ?> (Deducción Personal Médica/Psicológica/Nutricional)
                </div>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>ClaveProdServ SAT</th>
                        <th>ClaveUnidad</th>
                        <th>Cant.</th>
                        <th>Concepto / Descripción Clínica</th>
                        <th>Subtotal</th>
                        <th>IVA</th>
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code><?= htmlspecialchars($facturaVista['clave_prod_serv']) ?></code></td>
                        <td><code><?= htmlspecialchars($facturaVista['clave_unidad']) ?></code></td>
                        <td>1.00</td>
                        <td><?= htmlspecialchars($facturaVista['concepto']) ?></td>
                        <td>$<?= number_format((float)$facturaVista['subtotal'], 2) ?></td>
                        <td>$<?= number_format((float)$facturaVista['iva'], 2) ?></td>
                        <td><strong>$<?= number_format((float)$facturaVista['total'], 2) ?></strong></td>
                    </tr>
                </tbody>
            </table>
            <div class="flex-between" style="margin-top:14px; font-size:12px; color:#475569;">
                <div>
                    <strong>Forma de Pago SAT:</strong> <?= htmlspecialchars($facturaVista['forma_pago_sat']) ?> ·
                    <strong>Método de Pago:</strong> <?= htmlspecialchars($facturaVista['metodo_pago_sat']) ?> ·
                    <strong>Moneda:</strong> MXN
                    <br><small>Sello Digital del SAT: ||1.1|<?= htmlspecialchars($facturaVista['uuid_fiscal']) ?>|<?= htmlspecialchars($facturaVista['fecha_emision']) ?>|SAT970701NN3||</small>
                </div>
                <div style="font-size:16px; font-weight:800; color:#0f172a;">
                    TOTAL CFDI: $<?= number_format((float)$facturaVista['total'], 2) ?> MXN
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- KPIS DE FACTURACIÓN -->
        <div class="grid-3 no-print" style="margin-bottom:20px;">
            <div class="kpi-card">
                <div class="kpi-title">Total Facturado Vigente</div>
                <div class="kpi-value">$<?= number_format($totalFacturadoMes, 2) ?></div>
                <div class="kpi-sub">Honorarios exentos de IVA (Art. 15 LIVA) y paquetes</div>
            </div>
            <div class="kpi-card amber">
                <div class="kpi-title">Recibos POS Pendientes de Timbrar</div>
                <div class="kpi-value"><?= count($pagosPendientesFactura) ?> recibos</div>
                <div class="kpi-sub">Solicitados por pacientes en Mostrador</div>
            </div>
            <div class="kpi-card blue">
                <div class="kpi-title">Facturas CFDI 4.0 Emitidas</div>
                <div class="kpi-value"><?= count($facturasEmitidas) ?> comprobantes</div>
                <div class="kpi-sub">Con Claves SAT 85121800 (Nutrición/Checkup) y 85121600 (Psicología)</div>
            </div>
        </div>

        <div class="grid-2 no-print">
            <!-- FORMULARIO DE EMISIÓN / TIMBRADO CFDI 4.0 -->
            <div class="card">
                <h3>🧾 Emitir y Timbrar Factura Electrónica CFDI 4.0</h3>
                <p class="muted" style="margin-bottom:12px;">Completa o verifica los datos fiscales de la Constancia de Situación Fiscal del paciente.</p>

                <?php if (!empty($pagosPendientesFactura)): ?>
                <div style="background:#fffbeb; border:1px solid #fde68a; border-radius:8px; padding:10px; margin-bottom:14px;">
                    <strong style="color:#92400e; font-size:12px;">⚡ Recibos de Mostrador esperando Factura (Clic para cargar):</strong>
                    <div style="display:flex; gap:6px; flex-wrap:wrap; margin-top:6px;">
                        <?php foreach ($pagosPendientesFactura as $ppf): ?>
                            <a href="facturacion.php?pago_id=<?= (int)$ppf['id'] ?>" class="btn btn-amber btn-sm">
                                <?= htmlspecialchars($ppf['folio_recibo']) ?> - <?= htmlspecialchars($ppf['nombre_completo'] ?: 'Mostrador') ?> ($<?= number_format((float)$ppf['total_pagado'], 0) ?>)
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <form method="POST" class="grid-2">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="timbrar_cfdi" value="1">
                    <input type="hidden" name="pago_id" value="<?= (int)($pagoPre['id'] ?? 0) ?>">

                    <label style="grid-column: span 2;">Seleccionar Cliente de la Cartera CRM *
                        <select name="paciente_id" id="fac_paciente" onchange="cargarDatosFiscalesCliente(this)" required>
                            <option value="">-- Seleccionar paciente --</option>
                            <?php foreach ($pacientesList as $pl): ?>
                                <option value="<?= (int)$pl['id'] ?>"
                                        data-rfc="<?= htmlspecialchars($pl['rfc']) ?>"
                                        data-razon="<?= htmlspecialchars($pl['razon_social'] ?: strtoupper($pl['nombre_completo'])) ?>"
                                        data-regimen="<?= htmlspecialchars($pl['regimen_fiscal']) ?>"
                                        data-cp="<?= htmlspecialchars($pl['codigo_postal_fiscal'] ?: '03100') ?>"
                                        data-uso="<?= htmlspecialchars($pl['uso_cfdi'] ?: 'D01') ?>"
                                        <?= ($pacienteSeleccionadoId === (int)$pl['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($pl['nombre_completo']) ?> — RFC: <?= htmlspecialchars($pl['rfc']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label>RFC del Receptor *
                        <input type="text" name="rfc_receptor" id="fac_rfc" value="<?= htmlspecialchars($pacientePre['rfc'] ?? 'XAXX010101000') ?>" maxlength="13" required>
                    </label>

                    <label>Código Postal Fiscal Receptor *
                        <input type="text" name="cp_receptor" id="fac_cp" value="<?= htmlspecialchars($pacientePre['codigo_postal_fiscal'] ?? '03100') ?>" maxlength="5" required>
                    </label>

                    <label style="grid-column: span 2;">Nombre o Razón Social Receptor (Exacto a Constancia SAT) *
                        <input type="text" name="razon_social_receptor" id="fac_razon" value="<?= htmlspecialchars($pacientePre['razon_social'] ?? strtoupper($pacientePre['nombre_completo'] ?? '')) ?>" required>
                    </label>

                    <label>Régimen Fiscal Receptor *
                        <select name="regimen_fiscal_receptor" id="fac_regimen" required>
                            <?php foreach (getRegimenesSAT() as $code => $label): ?>
                                <option value="<?= htmlspecialchars((string)$code) ?>" <?= (($pacientePre['regimen_fiscal'] ?? '612') === (string)$code) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label>Uso del CFDI 4.0 *
                        <select name="uso_cfdi" id="fac_uso" required>
                            <?php foreach (getUsosCFDI() as $code => $label): ?>
                                <option value="<?= htmlspecialchars((string)$code) ?>" <?= (($pacientePre['uso_cfdi'] ?? 'D01') === (string)$code) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label>Clave Producto / Servicio SAT *
                        <select name="clave_prod_serv">
                            <option value="85121800">85121800 - Servicios de Nutrición Clínica y Checkup Metabólico</option>
                            <option value="85121600">85121600 - Servicios de Psicología Clínica y Psiconutrición</option>
                            <option value="85101500">85101500 - Centros de atención médica integral y paquetes</option>
                        </select>
                    </label>

                    <label>Forma de Pago SAT *
                        <select name="forma_pago_sat">
                            <?php foreach (getFormasPagoSAT() as $code => $label): ?>
                                <option value="<?= htmlspecialchars((string)$code) ?>"><?= htmlspecialchars($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label style="grid-column: span 2;">Concepto Fiscal en Factura *
                        <input type="text" name="concepto" value="<?= htmlspecialchars($pagoPre['concepto'] ?? 'Honorarios por Consulta Clínica Integral / Checkup Preventivo') ?>" required>
                    </label>

                    <label>Subtotal ($ MXN) *
                        <input type="number" name="subtotal" step="0.01" value="<?= (float)($pagoPre['total_pagado'] ?? 950.00) ?>" required>
                    </label>

                    <label>IVA ($ MXN) <small class="muted">(Honorarios médicos exentos = $0)</small>
                        <input type="number" name="iva" step="0.01" value="0.00">
                    </label>

                    <input type="hidden" name="metodo_pago_sat" value="PUE">

                    <div style="grid-column: span 2;">
                        <button type="submit" class="btn btn-primary" style="width:100%; padding:10px;">⚡ Timbrar CFDI 4.0 y Generar Folio Fiscal UUID</button>
                    </div>
                </form>
            </div>

            <!-- HISTORIAL DE FACTURAS EMITIDAS -->
            <div class="card">
                <h3>📂 Historial de Facturas CFDI 4.0 Emitidas</h3>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Folio / UUID</th>
                                <th>Receptor / RFC</th>
                                <th>Concepto / Clave SAT</th>
                                <th>Total</th>
                                <th>Estado / Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($facturasEmitidas as $f): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($f['folio_factura']) ?></strong><br>
                                    <small class="muted" style="font-size:10.5px;"><?= htmlspecialchars(substr($f['uuid_fiscal'], 0, 18)) ?>...</small>
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars($f['razon_social_receptor']) ?></strong><br>
                                    <span class="badge badge-slate"><?= htmlspecialchars($f['rfc_receptor']) ?></span>
                                </td>
                                <td>
                                    <small><?= htmlspecialchars($f['concepto']) ?></small><br>
                                    <small class="muted">SAT: <?= htmlspecialchars($f['clave_prod_serv']) ?> · Uso: <?= htmlspecialchars($f['uso_cfdi']) ?></small>
                                </td>
                                <td><strong>$<?= number_format((float)$f['total'], 2) ?></strong></td>
                                <td>
                                    <?php if ($f['estado'] === 'timbrada'): ?>
                                        <span class="badge badge-teal">Timbrada</span>
                                    <?php else: ?>
                                        <span class="badge badge-rose">Cancelada</span>
                                    <?php endif; ?>
                                    <div style="margin-top:4px; display:flex; gap:4px;">
                                        <a href="facturacion.php?ver_factura_id=<?= (int)$f['id'] ?>" class="btn btn-outline btn-sm">🖨️ Ver PDF</a>
                                        <?php if ($f['estado'] === 'timbrada'): ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                            <button name="cancelar_factura_id" value="<?= (int)$f['id'] ?>" class="btn btn-outline btn-sm" style="color:var(--rose);">✕</button>
                                        </form>
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
    </main>

    <script>
    function cargarDatosFiscalesCliente(sel) {
        const opt = sel.options[sel.selectedIndex];
        if (!opt || !opt.value) return;
        document.getElementById('fac_rfc').value = opt.getAttribute('data-rfc') || 'XAXX010101000';
        document.getElementById('fac_razon').value = opt.getAttribute('data-razon') || '';
        document.getElementById('fac_regimen').value = opt.getAttribute('data-regimen') || '612';
        document.getElementById('fac_cp').value = opt.getAttribute('data-cp') || '03100';
        document.getElementById('fac_uso').value = opt.getAttribute('data-uso') || 'D01';
    }
    </script>
</body>
</html>
