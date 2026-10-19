<?php
declare(strict_types=1);
require_once __DIR__ . '/db_connection.php';

$pdo = getDB();
$usuario = requireAuth();
$hoy = date('Y-m-d');
$vista = $_GET['vista'] ?? 'resumen';
$mensajeExito = '';
$mensajeError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $mensajeError = 'Token de seguridad inválido.';
    } else {
        $accion = $_POST['accion'] ?? '';

        if ($accion === 'registrar_gasto') {
            $categoria = $_POST['categoria'] ?? 'insumos_clinicos';
            $concepto = trim($_POST['concepto'] ?? '');
            $proveedor = trim($_POST['proveedor'] ?? '');
            $monto = (float)($_POST['monto'] ?? 0);
            $metodo = $_POST['metodo_pago'] ?? 'transferencia';
            $fechaGasto = $_POST['fecha_gasto'] ?? $hoy;
            $deducible = isset($_POST['deducible_impuestos']) ? 1 : 0;

            if ($concepto !== '' && $monto > 0) {
                $countG = (int)$pdo->query("SELECT COUNT(*) FROM gastos_operativos")->fetchColumn() + 1;
                $folioG = 'GAS-' . date('Y') . '-' . str_pad((string)$countG, 3, '0', STR_PAD_LEFT);
                $stmtG = $pdo->prepare("INSERT INTO gastos_operativos (folio_gasto, categoria, concepto, proveedor, monto, metodo_pago, deducible_impuestos, fecha_gasto, registrado_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmtG->execute([$folioG, $categoria, $concepto, $proveedor, $monto, $metodo, $deducible, $fechaGasto, $usuario['id']]);
                $mensajeExito = "Gasto {$folioG} por $" . number_format($monto, 2) . " registrado.";
                $vista = 'gastos';
            }
        }

        elseif ($accion === 'guardar_servicio') {
            $codigo = strtoupper(trim($_POST['codigo'] ?? 'SRV-' . rand(10, 99)));
            $nombre = trim($_POST['nombre'] ?? '');
            $area = $_POST['area'] ?? 'nutricion';
            $descripcion = trim($_POST['descripcion'] ?? '');
            $precio = (float)($_POST['precio'] ?? 900);
            $sesNutri = (int)($_POST['sesiones_nutri_incluidas'] ?? 1);
            $sesPsico = (int)($_POST['sesiones_psico_incluidas'] ?? 0);
            $incCheckup = isset($_POST['incluye_checkup']) ? 1 : 0;
            $duracion = (int)($_POST['duracion_minutos'] ?? 50);
            $claveSat = $_POST['clave_prod_serv_sat'] ?? '85121800';

            if ($nombre !== '' && $precio > 0) {
                $stmtS = $pdo->prepare("INSERT INTO catalogo_servicios (codigo, nombre, area, descripcion, precio, sesiones_nutri_incluidas, sesiones_psico_incluidas, incluye_checkup, duracion_minutos, clave_prod_serv_sat, clave_unidad_sat, exento_iva, activo) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'E48', 1, 1)");
                $stmtS->execute([$codigo, $nombre, $area, $descripcion, $precio, $sesNutri, $sesPsico, $incCheckup, $duracion, $claveSat]);
                $mensajeExito = "Servicio '{$nombre}' agregado al catálogo.";
                $vista = 'catalogo';
            }
        }
    }
}

$ingresosBrutos = (float)$pdo->query("SELECT COALESCE(SUM(total_pagado),0) FROM pagos_caja")->fetchColumn();
$comisionesTotales = (float)$pdo->query("SELECT COALESCE(SUM(comision_especialista),0) FROM pagos_caja")->fetchColumn();
$gastosTotales = (float)$pdo->query("SELECT COALESCE(SUM(monto),0) FROM gastos_operativos")->fetchColumn();
$cuentasPorCobrar = (float)$pdo->query("SELECT COALESCE(SUM(saldo_pendiente),0) FROM pacientes")->fetchColumn();
$retencionClinica = $ingresosBrutos - $comisionesTotales;
$utilidadNeta = $retencionClinica - $gastosTotales;

$nominaEspecialistas = $pdo->query("
    SELECT u.id, u.nombre, u.titulo, u.area_clinica, u.porcentaje_comision,
           COUNT(pg.id) AS total_cobros,
           COALESCE(SUM(pg.total_pagado), 0) AS ingresos_generados,
           COALESCE(SUM(pg.comision_especialista), 0) AS comision_pagar
    FROM usuarios u
    LEFT JOIN pagos_caja pg ON pg.nutriologo_id = u.id
    WHERE u.rol IN ('nutriologo', 'psicologo') AND u.activo = 1
    GROUP BY u.id
    ORDER BY ingresos_generados DESC
")->fetchAll();

$ingresosPorArea = $pdo->query("
    SELECT categoria_ingreso, COUNT(*) AS operaciones, SUM(total_pagado) AS total
    FROM pagos_caja
    GROUP BY categoria_ingreso
    ORDER BY total DESC
")->fetchAll();

$gastosLista = $pdo->query("SELECT * FROM gastos_operativos ORDER BY id DESC")->fetchAll();
$catalogoLista = $pdo->query("SELECT * FROM catalogo_servicios ORDER BY id ASC")->fetchAll();

$subPills = [
    ['label' => 'Resumen y Nómina', 'href' => 'administracion.php?vista=resumen', 'active' => ($vista === 'resumen'), 'count' => null],
    ['label' => 'Gastos Operativos', 'href' => 'administracion.php?vista=gastos', 'active' => ($vista === 'gastos'), 'count' => count($gastosLista)],
    ['label' => 'Catálogo de Precios', 'href' => 'administracion.php?vista=catalogo', 'active' => ($vista === 'catalogo'), 'count' => count($catalogoLista)],
];

$actionTop = '';
if ($vista === 'gastos') {
    $actionTop = '<button type="button" onclick="openModal(\'modalNuevoGasto\')" class="btn btn-primary btn-sm">+ Registrar Gasto</button>';
} elseif ($vista === 'catalogo') {
    $actionTop = '<button type="button" onclick="openModal(\'modalNuevoServicio\')" class="btn btn-primary btn-sm">+ Nuevo Servicio</button>';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administración | <?= htmlspecialchars(CLINIC_NAME) ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<?php renderAppStart('Administración y Finanzas', 'Estado de resultados, liquidación de comisiones médicas y catálogo', 'administracion', $subPills, $actionTop); ?>

<?php if ($mensajeExito): ?>
    <div class="alert alert-success"><?= htmlspecialchars($mensajeExito) ?></div>
<?php endif; ?>
<?php if ($mensajeError): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($mensajeError) ?></div>
<?php endif; ?>

<!-- KPI EJECUTIVOS -->
<div class="kpi-row">
    <div class="kpi-card">
        <span class="kpi-label">Ingresos Cobrados en Caja</span>
        <div class="kpi-value" style="color:var(--primary);">$<?= number_format($ingresosBrutos, 2) ?></div>
        <div class="kpi-sub">Consultas, paquetes y checkups</div>
    </div>
    <div class="kpi-card">
        <span class="kpi-label">Honorarios a Especialistas</span>
        <div class="kpi-value">$<?= number_format($comisionesTotales, 2) ?></div>
        <div class="kpi-sub">Retención clínica: $<?= number_format($retencionClinica, 2) ?></div>
    </div>
    <div class="kpi-card">
        <span class="kpi-label">Gastos Operativos</span>
        <div class="kpi-value" style="color:var(--warning);">$<?= number_format($gastosTotales, 2) ?></div>
        <div class="kpi-sub">Renta, insumos, laboratorio y anuncios</div>
    </div>
    <div class="kpi-card">
        <span class="kpi-label">Cuentas por Cobrar (Cartera)</span>
        <div class="kpi-value" style="color:var(--danger);">$<?= number_format($cuentasPorCobrar, 2) ?></div>
        <div class="kpi-sub">Saldos pendientes de pacientes</div>
    </div>
</div>

<?php if ($vista === 'resumen'): ?>
    <!-- =========================================================
         VISTA 1: RESUMEN EJECUTIVO Y NÓMINA DE ESPECIALISTAS
         ========================================================= -->
    <div class="grid-sidebar">
        <div class="card">
            <div class="card-header">
                <div>
                    <h2>Nómina de Comisiones por Especialista</h2>
                    <p>Cálculo automático de honorarios médicos y retención para el consultorio.</p>
                </div>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Especialista</th>
                            <th>Cobros</th>
                            <th>Ingresos Generados</th>
                            <th>% Comisión</th>
                            <th>Pago a Especialista</th>
                            <th>Retención Clínica</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($nominaEspecialistas as $ne):
                            $retEsp = (float)$ne['ingresos_generados'] - (float)$ne['comision_pagar'];
                        ?>
                            <tr>
                                <td>
                                    <div class="avatar-row">
                                        <img src="<?= htmlspecialchars(getFotoEspecialista((int)$ne['id'])) ?>" alt="" class="avatar-sm">
                                        <div>
                                            <strong><?= htmlspecialchars($ne['nombre']) ?></strong>
                                            <div class="text-xs text-muted"><?= htmlspecialchars(ucfirst($ne['area_clinica'])) ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td><?= (int)$ne['total_cobros'] ?></td>
                                <td><strong>$<?= number_format((float)$ne['ingresos_generados'], 2) ?></strong></td>
                                <td><span class="badge badge-slate"><?= number_format((float)$ne['porcentaje_comision'], 0) ?>%</span></td>
                                <td><strong style="color:var(--primary);">$<?= number_format((float)$ne['comision_pagar'], 2) ?></strong></td>
                                <td class="text-muted">$<?= number_format($retEsp, 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <div>
                    <h2>Ingresos por Área Clínica</h2>
                    <p>Participación en la facturación total.</p>
                </div>
            </div>

            <div style="display:flex;flex-direction:column;gap:14px;">
                <?php foreach ($ingresosPorArea as $ia):
                    $pct = $ingresosBrutos > 0 ? round(((float)$ia['total'] / $ingresosBrutos) * 100) : 0;
                ?>
                    <div style="padding:12px;border:1px solid var(--border);border-radius:10px;">
                        <div class="flex-between" style="margin-bottom:6px;">
                            <strong><?= strtoupper(htmlspecialchars($ia['categoria_ingreso'])) ?></strong>
                            <span style="font-weight:700;color:var(--primary);">$<?= number_format((float)$ia['total'], 2) ?> (<?= $pct ?>%)</span>
                        </div>
                        <div style="height:6px;background:var(--border);border-radius:99px;overflow:hidden;">
                            <div style="height:100%;width:<?= $pct ?>%;background:var(--primary);"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

<?php elseif ($vista === 'gastos'): ?>
    <!-- =========================================================
         VISTA 2: GASTOS OPERATIVOS DEL CONSULTORIO
         ========================================================= -->
    <div class="card">
        <div class="card-header">
            <div>
                <h2>Gastos Operativos y Proveedores</h2>
                <p>Control de egresos de renta, laboratorio de checkups, insumos y publicidad.</p>
            </div>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Folio / Fecha</th>
                        <th>Categoría</th>
                        <th>Concepto y Proveedor</th>
                        <th>Método de Pago</th>
                        <th class="text-right">Monto</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($gastosLista as $g): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($g['folio_gasto']) ?></strong>
                                <div class="text-xs text-muted"><?= htmlspecialchars($g['fecha_gasto']) ?></div>
                            </td>
                            <td><span class="badge badge-slate"><?= strtoupper(str_replace('_', ' ', $g['categoria'])) ?></span></td>
                            <td>
                                <strong><?= htmlspecialchars($g['concepto']) ?></strong>
                                <div class="text-xs text-muted"><?= htmlspecialchars($g['proveedor'] ?? '') ?></div>
                            </td>
                            <td><?= strtoupper(str_replace('_', ' ', $g['metodo_pago'])) ?></td>
                            <td class="text-right"><strong style="color:var(--danger);">-$<?= number_format((float)$g['monto'], 2) ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- MODAL NUEVO GASTO -->
    <div id="modalNuevoGasto" class="modal-backdrop">
        <div class="modal-dialog">
            <div class="modal-header">
                <h3>Registrar Gasto Operativo</h3>
                <button type="button" class="modal-close" onclick="closeModal('modalNuevoGasto')">&times;</button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                    <input type="hidden" name="accion" value="registrar_gasto">

                    <div class="form-row">
                        <div class="form-group">
                            <label>Categoría *</label>
                            <select name="categoria" class="form-control">
                                <option value="renta_consultorio">Renta de Consultorios</option>
                                <option value="laboratorio_checkups">Laboratorio de Checkups</option>
                                <option value="insumos_clinicos">Insumos Clínicos y Sala de Espera</option>
                                <option value="marketing_ads">Publicidad y Captación</option>
                                <option value="software_servicios">Software y Servicios</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Fecha del gasto *</label>
                            <input type="date" name="fecha_gasto" class="form-control" value="<?= $hoy ?>" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Concepto *</label>
                        <input type="text" name="concepto" class="form-control" placeholder="Ej. Compra de tiras reactivas y hojas InBody" required>
                    </div>

                    <div class="form-row-3">
                        <div class="form-group">
                            <label>Proveedor</label>
                            <input type="text" name="proveedor" class="form-control" placeholder="Nombre del proveedor">
                        </div>
                        <div class="form-group">
                            <label>Monto ($) *</label>
                            <input type="number" step="0.01" name="monto" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Origen de pago</label>
                            <select name="metodo_pago" class="form-control">
                                <option value="transferencia">Transferencia Bancaria</option>
                                <option value="efectivo_caja">Efectivo de Caja Chica</option>
                                <option value="tarjeta_corporativa">Tarjeta Corporativa</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('modalNuevoGasto')">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Guardar Gasto</button>
                </div>
            </form>
        </div>
    </div>

<?php elseif ($vista === 'catalogo'): ?>
    <!-- =========================================================
         VISTA 3: CATÁLOGO DE SERVICIOS, PAQUETES Y CHECKUPS
         ========================================================= -->
    <div class="card">
        <div class="card-header">
            <div>
                <h2>Catálogo de Consultas, Checkups y Paquetes</h2>
                <p>Estos precios alimentan automáticamente el portal web, el cobro en caja POS y la facturación CFDI.</p>
            </div>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Servicio o Paquete</th>
                        <th>Área</th>
                        <th>Sesiones Incluidas</th>
                        <th>Clave SAT</th>
                        <th class="text-right">Precio</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($catalogoLista as $cs): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($cs['codigo']) ?></strong></td>
                            <td>
                                <strong><?= htmlspecialchars($cs['nombre']) ?></strong>
                                <div class="text-xs text-muted"><?= htmlspecialchars($cs['descripcion']) ?></div>
                            </td>
                            <td><span class="badge badge-teal"><?= strtoupper(htmlspecialchars($cs['area'])) ?></span></td>
                            <td class="text-xs">
                                <?= (int)$cs['sesiones_nutri_incluidas'] ?> Nutri · <?= (int)$cs['sesiones_psico_incluidas'] ?> Psico · <?= (int)$cs['incluye_checkup'] ? '1 Checkup' : '0 Checkup' ?>
                            </td>
                            <td class="text-xs text-muted"><?= htmlspecialchars($cs['clave_prod_serv_sat']) ?></td>
                            <td class="text-right"><strong style="color:var(--primary);">$<?= number_format((float)$cs['precio'], 2) ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- MODAL NUEVO SERVICIO -->
    <div id="modalNuevoServicio" class="modal-backdrop">
        <div class="modal-dialog">
            <div class="modal-header">
                <h3>Agregar Servicio o Paquete al Catálogo</h3>
                <button type="button" class="modal-close" onclick="closeModal('modalNuevoServicio')">&times;</button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                    <input type="hidden" name="accion" value="guardar_servicio">

                    <div class="form-row-3">
                        <div class="form-group">
                            <label>Código interno *</label>
                            <input type="text" name="codigo" class="form-control" placeholder="CHK-EXEC" required>
                        </div>
                        <div class="form-group">
                            <label>Área *</label>
                            <select name="area" class="form-control">
                                <option value="nutricion">Nutrición</option>
                                <option value="psicologia">Psicología</option>
                                <option value="psiconutricion">Psiconutrición</option>
                                <option value="checkup">Checkup Médico</option>
                                <option value="paquete">Paquete Integral</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Precio ($) *</label>
                            <input type="number" step="0.01" name="precio" class="form-control" placeholder="1500.00" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Nombre comercial del servicio *</label>
                        <input type="text" name="nombre" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label>Descripción breve</label>
                        <input type="text" name="descripcion" class="form-control">
                    </div>

                    <div class="form-row-3">
                        <div class="form-group">
                            <label>Sesiones Nutrición</label>
                            <input type="number" name="sesiones_nutri_incluidas" class="form-control" value="1">
                        </div>
                        <div class="form-group">
                            <label>Sesiones Psicología</label>
                            <input type="number" name="sesiones_psico_incluidas" class="form-control" value="0">
                        </div>
                        <div class="form-group">
                            <label>Duración (min)</label>
                            <input type="number" name="duracion_minutos" class="form-control" value="50">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('modalNuevoServicio')">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Guardar Servicio</button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php renderAppEnd(); ?>
</body>
</html>
