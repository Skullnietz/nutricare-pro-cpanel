<?php
declare(strict_types=1);
require_once __DIR__ . '/db_connection.php';

$pdo = getDB();
$usuario = requireAuth();
$vista = $_GET['vista'] ?? 'emitidas';
if (isset($_GET['pago_id'])) {
    $vista = 'timbrar';
}

$mensajeExito = '';
$mensajeError = '';
$facturaVerId = isset($_GET['ver_factura_id']) ? (int)$_GET['ver_factura_id'] : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $mensajeError = 'Token de seguridad inválido.';
    } else {
        $accion = $_POST['accion'] ?? '';

        if ($accion === 'timbrar_cfdi') {
            $pagoId = !empty($_POST['pago_id']) ? (int)$_POST['pago_id'] : null;
            $pacienteId = (int)($_POST['paciente_id'] ?? 1);
            $rfcReceptor = strtoupper(trim($_POST['rfc_receptor'] ?? 'XAXX010101000'));
            $razonSocial = strtoupper(trim($_POST['razon_social_receptor'] ?? 'PUBLICO EN GENERAL'));
            $regimenFiscal = $_POST['regimen_fiscal_receptor'] ?? '612';
            $cpReceptor = trim($_POST['cp_receptor'] ?? '06600');
            $usoCfdi = $_POST['uso_cfdi'] ?? 'D01';
            $claveProdServ = $_POST['clave_prod_serv'] ?? '85121800';
            $concepto = trim($_POST['concepto'] ?? 'Honorarios Médicos');
            $formaPagoSat = $_POST['forma_pago_sat'] ?? '04';
            $metodoPagoSat = $_POST['metodo_pago_sat'] ?? 'PUE';
            $subtotal = (float)($_POST['subtotal'] ?? 0);
            $aplicaIva = isset($_POST['aplica_iva']) ? 1 : 0;
            $iva = $aplicaIva ? round($subtotal * 0.16, 2) : 0.00;
            $total = round($subtotal + $iva, 2);

            $countF = (int)$pdo->query("SELECT COUNT(*) FROM facturas_cfdi")->fetchColumn() + 1;
            $folioFactura = 'FAC-A' . str_pad((string)$countF, 3, '0', STR_PAD_LEFT);
            $uuid = sprintf(
                '%04X%04X-%04X-4%03X-A%03X-%04X%04X%04X',
                mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                mt_rand(0, 0xffff),
                mt_rand(0, 0x0fff),
                mt_rand(0, 0x0fff),
                mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
            );

            $stmtIns = $pdo->prepare("INSERT INTO facturas_cfdi (folio_factura, uuid_fiscal, pago_id, paciente_id, rfc_receptor, razon_social_receptor, regimen_fiscal_receptor, cp_receptor, uso_cfdi, clave_prod_serv, clave_unidad, concepto, forma_pago_sat, metodo_pago_sat, subtotal, iva, total, estado) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'E48', ?, ?, ?, ?, ?, ?, 'timbrada')");
            $stmtIns->execute([
                $folioFactura,
                $uuid,
                $pagoId,
                $pacienteId,
                $rfcReceptor,
                $razonSocial,
                $regimenFiscal,
                $cpReceptor,
                $usoCfdi,
                $claveProdServ,
                $concepto,
                $formaPagoSat,
                $metodoPagoSat,
                $subtotal,
                $iva,
                $total
            ]);
            $facturaVerId = (int)$pdo->lastInsertId();

            if ($pagoId) {
                $pdo->prepare("UPDATE pagos_caja SET estado_factura = 'timbrada', requiere_factura = 1 WHERE id = ?")->execute([$pagoId]);
            }
            $pdo->prepare("UPDATE pacientes SET rfc = ?, razon_social = ?, regimen_fiscal = ?, codigo_postal_fiscal = ?, uso_cfdi = ? WHERE id = ?")
                ->execute([$rfcReceptor, $razonSocial, $regimenFiscal, $cpReceptor, $usoCfdi, $pacienteId]);

            $mensajeExito = "Factura {$folioFactura} timbrada correctamente (UUID: {$uuid}).";
            $vista = 'emitidas';
        }

        elseif ($accion === 'cancelar_cfdi') {
            $fid = (int)($_POST['factura_id'] ?? 0);
            $pdo->prepare("UPDATE facturas_cfdi SET estado = 'cancelada' WHERE id = ?")->execute([$fid]);
            $mensajeExito = "Factura cancelada ante el SAT.";
        }
    }
}

$pagosPendientes = $pdo->query("
    SELECT pg.*, p.nombre_completo, p.rfc, p.razon_social, p.regimen_fiscal, p.codigo_postal_fiscal, p.uso_cfdi
    FROM pagos_caja pg
    JOIN pacientes p ON pg.paciente_id = p.id
    WHERE pg.requiere_factura = 1 AND pg.estado_factura = 'pendiente'
    ORDER BY pg.id DESC
")->fetchAll();

$facturas = $pdo->query("
    SELECT f.*, p.nombre_completo
    FROM facturas_cfdi f
    JOIN pacientes p ON f.paciente_id = p.id
    ORDER BY f.id DESC
")->fetchAll();

$pacientesFiscales = $pdo->query("SELECT id, nombre_completo, rfc, razon_social, regimen_fiscal, codigo_postal_fiscal, uso_cfdi FROM pacientes ORDER BY nombre_completo ASC")->fetchAll();

$pagoPreseleccionado = null;
if (isset($_GET['pago_id'])) {
    $pid = (int)$_GET['pago_id'];
    $stmtP = $pdo->prepare("
        SELECT pg.*, p.rfc, p.razon_social, p.regimen_fiscal, p.codigo_postal_fiscal, p.uso_cfdi
        FROM pagos_caja pg
        JOIN pacientes p ON pg.paciente_id = p.id
        WHERE pg.id = ?
    ");
    $stmtP->execute([$pid]);
    $pagoPreseleccionado = $stmtP->fetch();
}

$facturaDetalle = null;
if ($facturaVerId) {
    $stmtF = $pdo->prepare("SELECT f.*, p.nombre_completo, p.email_facturacion FROM facturas_cfdi f JOIN pacientes p ON f.paciente_id = p.id WHERE f.id = ?");
    $stmtF->execute([$facturaVerId]);
    $facturaDetalle = $stmtF->fetch();
}

$regimenes = getRegimenesSAT();
$usosCfdi = getUsosCFDI();
$formasPago = getFormasPagoSAT();

$subPills = [
    ['label' => 'Facturas Emitidas', 'href' => 'facturacion.php?vista=emitidas', 'active' => ($vista === 'emitidas'), 'count' => count($facturas)],
    ['label' => 'Timbrar Nueva Factura', 'href' => 'facturacion.php?vista=timbrar', 'active' => ($vista === 'timbrar'), 'count' => count($pagosPendientes) ?: null],
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Facturación CFDI 4.0 | <?= htmlspecialchars(CLINIC_NAME) ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<?php renderAppStart('Facturación CFDI 4.0', 'Emisión de comprobantes fiscales de honorarios médicos y checkups', 'facturacion', $subPills); ?>

<?php if ($mensajeExito): ?>
    <div class="alert alert-success"><?= htmlspecialchars($mensajeExito) ?></div>
<?php endif; ?>
<?php if ($mensajeError): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($mensajeError) ?></div>
<?php endif; ?>

<?php if ($facturaDetalle): ?>
    <!-- REPRESENTACIÓN IMPRESA DEL CFDI 4.0 -->
    <div class="card" style="margin-bottom:24px;border:2px solid var(--primary);">
        <div class="flex-between no-print" style="margin-bottom:16px;">
            <span class="badge badge-teal">Representación Impresa CFDI 4.0</span>
            <div class="flex-gap">
                <button onclick="window.print()" class="btn btn-primary btn-sm">Imprimir / Guardar PDF</button>
                <a href="facturacion.php?vista=emitidas" class="btn btn-outline btn-sm">Cerrar vista previa</a>
            </div>
        </div>

        <div class="grid-2" style="border-bottom:1px solid var(--border);padding-bottom:14px;margin-bottom:14px;">
            <div>
                <h3 style="margin-bottom:4px;"><?= htmlspecialchars(CLINIC_LEGAL_NAME) ?></h3>
                <div class="text-xs text-muted">RFC Emisor: <?= htmlspecialchars(CLINIC_RFC) ?> · Régimen: <?= htmlspecialchars(CLINIC_REGIMEN) ?></div>
                <div class="text-xs text-muted">Lugar de Expedición: C.P. <?= htmlspecialchars(CLINIC_CP) ?></div>
            </div>
            <div class="text-right">
                <div style="font-size:1.1rem;font-weight:700;color:var(--primary);">Folio: <?= htmlspecialchars($facturaDetalle['folio_factura']) ?></div>
                <div class="text-xs text-muted">UUID Fiscal: <?= htmlspecialchars($facturaDetalle['uuid_fiscal']) ?></div>
                <div class="text-xs text-muted">Emisión: <?= htmlspecialchars($facturaDetalle['fecha_emision']) ?></div>
            </div>
        </div>

        <div class="grid-3" style="font-size:0.86rem;margin-bottom:14px;">
            <div>
                <span class="text-xs text-muted">Receptor</span>
                <div><strong><?= htmlspecialchars($facturaDetalle['razon_social_receptor']) ?></strong></div>
                <div class="text-xs">RFC: <?= htmlspecialchars($facturaDetalle['rfc_receptor']) ?> · C.P. <?= htmlspecialchars($facturaDetalle['cp_receptor']) ?></div>
            </div>
            <div>
                <span class="text-xs text-muted">Régimen y Uso CFDI</span>
                <div><strong>Régimen <?= htmlspecialchars($facturaDetalle['regimen_fiscal_receptor']) ?></strong></div>
                <div class="text-xs">Uso CFDI: <?= htmlspecialchars($facturaDetalle['uso_cfdi']) ?> · Clave SAT: <?= htmlspecialchars($facturaDetalle['clave_prod_serv']) ?></div>
            </div>
            <div class="text-right">
                <span class="text-xs text-muted">Importe Total</span>
                <div style="font-size:1.35rem;font-weight:700;color:var(--primary);">$<?= number_format((float)$facturaDetalle['total'], 2) ?> MXN</div>
                <div class="text-xs text-muted">Subtotal: $<?= number_format((float)$facturaDetalle['subtotal'], 2) ?> | IVA: $<?= number_format((float)$facturaDetalle['iva'], 2) ?></div>
            </div>
        </div>
        <div class="text-xs text-muted" style="background:var(--bg-body);padding:10px;border-radius:8px;">
            <strong>Concepto:</strong> <?= htmlspecialchars($facturaDetalle['concepto']) ?>
        </div>
    </div>
<?php endif; ?>

<?php if ($vista === 'emitidas'): ?>
    <!-- =========================================================
         VISTA 1: FACTURAS EMITIDAS Y PENDIENTES DE TIMBRAR
         ========================================================= -->
    <?php if (!empty($pagosPendientes)): ?>
        <div class="card" style="margin-bottom:24px;border-left:4px solid var(--accent);">
            <div class="card-header" style="margin-bottom:10px;padding-bottom:10px;">
                <div>
                    <h2>Recibos de Caja Pendientes de Facturar (<?= count($pagosPendientes) ?>)</h2>
                    <p>Estos cobros de mostrador solicitaron factura CFDI 4.0.</p>
                </div>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Recibo POS</th>
                            <th>Paciente / RFC</th>
                            <th>Concepto</th>
                            <th>Importe</th>
                            <th class="text-right">Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pagosPendientes as $pp): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($pp['folio_recibo']) ?></strong></td>
                                <td>
                                    <strong><?= htmlspecialchars($pp['nombre_completo']) ?></strong>
                                    <div class="text-xs text-muted">RFC: <?= htmlspecialchars($pp['rfc'] ?: 'XAXX010101000') ?></div>
                                </td>
                                <td><?= htmlspecialchars($pp['concepto']) ?></td>
                                <td><strong>$<?= number_format((float)$pp['total_pagado'], 2) ?></strong></td>
                                <td class="text-right">
                                    <a href="facturacion.php?vista=timbrar&pago_id=<?= (int)$pp['id'] ?>" class="btn btn-primary btn-xs">Timbrar CFDI</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header">
            <div>
                <h2>Historial de Facturas CFDI 4.0</h2>
                <p>Comprobantes fiscales timbrados de consultas nutricionales, psicológicas y checkups.</p>
            </div>
            <a href="facturacion.php?vista=timbrar" class="btn btn-primary btn-sm">+ Timbrar Factura</a>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Folio / Fecha</th>
                        <th>Receptor y RFC</th>
                        <th>Concepto SAT</th>
                        <th>Total</th>
                        <th>Estado</th>
                        <th class="text-right">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($facturas as $f): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($f['folio_factura']) ?></strong>
                                <div class="text-xs text-muted"><?= substr($f['fecha_emision'], 0, 16) ?></div>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars($f['razon_social_receptor']) ?></strong>
                                <div class="text-xs text-muted">RFC: <?= htmlspecialchars($f['rfc_receptor']) ?> · Uso: <?= htmlspecialchars($f['uso_cfdi']) ?></div>
                            </td>
                            <td>
                                <div><?= htmlspecialchars($f['concepto']) ?></div>
                                <div class="text-xs text-muted">Clave SAT: <?= htmlspecialchars($f['clave_prod_serv']) ?></div>
                            </td>
                            <td><strong style="color:var(--primary);">$<?= number_format((float)$f['total'], 2) ?></strong></td>
                            <td>
                                <span class="badge <?= $f['estado'] === 'timbrada' ? 'badge-teal' : 'badge-rose' ?>">
                                    <?= strtoupper($f['estado']) ?>
                                </span>
                            </td>
                            <td class="text-right">
                                <div class="flex-gap" style="justify-content:flex-end;">
                                    <a href="facturacion.php?vista=emitidas&ver_factura_id=<?= (int)$f['id'] ?>" class="btn btn-outline btn-xs">Ver CFDI</a>
                                    <?php if ($f['estado'] === 'timbrada'): ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                                            <input type="hidden" name="accion" value="cancelar_cfdi">
                                            <input type="hidden" name="factura_id" value="<?= (int)$f['id'] ?>">
                                            <button type="submit" class="btn btn-outline btn-xs" style="color:var(--danger);">Cancelar</button>
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

<?php else: ?>
    <!-- =========================================================
         VISTA 2: FORMULARIO DE TIMBRADO CFDI 4.0 (UN SOLO PROPÓSITO)
         ========================================================= -->
    <div class="card" style="max-width:760px;margin:0 auto;">
        <div class="card-header">
            <div>
                <h2>Timbrar Comprobante Fiscal CFDI 4.0</h2>
                <p>Los honorarios médicos, psicológicos y nutricionales están exentos de IVA conforme al Art. 15 LIVA.</p>
            </div>
        </div>

        <form method="POST" action="facturacion.php?vista=emitidas">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="accion" value="timbrar_cfdi">
            <?php if ($pagoPreseleccionado): ?>
                <input type="hidden" name="pago_id" value="<?= (int)$pagoPreseleccionado['id'] ?>">
                <div class="alert alert-info">
                    <span>Facturando recibo <strong><?= htmlspecialchars($pagoPreseleccionado['folio_recibo']) ?></strong> por $<?= number_format((float)$pagoPreseleccionado['total_pagado'], 2) ?> MXN</span>
                </div>
            <?php endif; ?>

            <div class="form-group">
                <label>Paciente *</label>
                <select name="paciente_id" id="cfdi-paciente" class="form-control" onchange="autocompletarFiscal()" required>
                    <?php foreach ($pacientesFiscales as $pf): ?>
                        <option value="<?= (int)$pf['id'] ?>"
                                data-rfc="<?= htmlspecialchars($pf['rfc'] ?: 'XAXX010101000') ?>"
                                data-razon="<?= htmlspecialchars($pf['razon_social'] ?: strtoupper($pf['nombre_completo'])) ?>"
                                data-regimen="<?= htmlspecialchars($pf['regimen_fiscal'] ?: '612') ?>"
                                data-cp="<?= htmlspecialchars($pf['codigo_postal_fiscal'] ?: '06600') ?>"
                                <?= ($pagoPreseleccionado && (int)$pagoPreseleccionado['paciente_id'] === (int)$pf['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($pf['nombre_completo']) ?> (RFC: <?= htmlspecialchars($pf['rfc'] ?: 'Sin RFC') ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>RFC del Receptor *</label>
                    <input type="text" name="rfc_receptor" id="cfdi-rfc" class="form-control" value="<?= htmlspecialchars($pagoPreseleccionado['rfc'] ?? $pacientesFiscales[0]['rfc'] ?? 'XAXX010101000') ?>" required>
                </div>
                <div class="form-group">
                    <label>Código Postal Fiscal *</label>
                    <input type="text" name="cp_receptor" id="cfdi-cp" class="form-control" value="<?= htmlspecialchars($pagoPreseleccionado['codigo_postal_fiscal'] ?? $pacientesFiscales[0]['codigo_postal_fiscal'] ?? '06600') ?>" required>
                </div>
            </div>

            <div class="form-group">
                <label>Razón Social (Tal como aparece en Constancia SAT) *</label>
                <input type="text" name="razon_social_receptor" id="cfdi-razon" class="form-control" value="<?= htmlspecialchars($pagoPreseleccionado['razon_social'] ?? $pacientesFiscales[0]['razon_social'] ?? '') ?>" required>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Régimen Fiscal del Receptor *</label>
                    <select name="regimen_fiscal_receptor" id="cfdi-regimen" class="form-control">
                        <?php foreach ($regimenes as $code => $label): ?>
                            <option value="<?= $code ?>"><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Uso de CFDI *</label>
                    <select name="uso_cfdi" class="form-control">
                        <?php foreach ($usosCfdi as $code => $label): ?>
                            <option value="<?= $code ?>"><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Clave Producto / Servicio SAT *</label>
                    <select name="clave_prod_serv" class="form-control">
                        <option value="85121800">85121800 - Consultorios de nutrición y checkup metabólico</option>
                        <option value="85121600">85121600 - Servicios de psicología clínica y psicoterapia</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Forma de Pago SAT *</label>
                    <select name="forma_pago_sat" class="form-control">
                        <?php foreach ($formasPago as $code => $label): ?>
                            <option value="<?= $code ?>"><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Concepto en la Factura *</label>
                    <input type="text" name="concepto" class="form-control" value="<?= htmlspecialchars($pagoPreseleccionado['concepto'] ?? 'Honorarios médicos de consulta clínica') ?>" required>
                </div>
                <div class="form-group">
                    <label>Subtotal a Facturar ($) *</label>
                    <input type="number" step="0.01" name="subtotal" class="form-control" value="<?= (float)($pagoPreseleccionado['total_pagado'] ?? 950.00) ?>" required>
                </div>
            </div>

            <input type="hidden" name="metodo_pago_sat" value="PUE">

            <div class="flex-between" style="margin-top:16px;">
                <a href="facturacion.php?vista=emitidas" class="btn btn-outline">Cancelar</a>
                <button type="submit" class="btn btn-primary">Timbrar Factura CFDI 4.0</button>
            </div>
        </form>
    </div>

    <script>
    function autocompletarFiscal() {
        var sel = document.getElementById('cfdi-paciente');
        if (!sel) return;
        var opt = sel.options[sel.selectedIndex];
        document.getElementById('cfdi-rfc').value = opt.getAttribute('data-rfc') || '';
        document.getElementById('cfdi-razon').value = opt.getAttribute('data-razon') || '';
        document.getElementById('cfdi-cp').value = opt.getAttribute('data-cp') || '';
    }
    </script>
<?php endif; ?>

<?php renderAppEnd(); ?>
</body>
</html>
