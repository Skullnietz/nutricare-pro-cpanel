<?php
require_once __DIR__ . '/db_connection.php';
$usuario = requireAuth();
$pdo = getDB();

// 1. Registrar nuevo Gasto Operativo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['registrar_gasto']) && verify_csrf($_POST['csrf_token'] ?? '')) {
    $folioGasto = 'GAS-' . date('Y') . '-' . rand(100, 999);
    $categoria  = $_POST['categoria'] ?? 'insumos_clinicos';
    $concepto   = trim($_POST['concepto']);
    $proveedor  = trim($_POST['proveedor'] ?? '');
    $monto      = (float)$_POST['monto'];
    $metodo     = $_POST['metodo_pago'] ?? 'transferencia';
    $deducible  = !empty($_POST['deducible_impuestos']) ? 1 : 0;
    $fecha      = $_POST['fecha_gasto'] ?: date('Y-m-d');

    $stmtG = $pdo->prepare("INSERT INTO gastos_operativos (folio_gasto, categoria, concepto, proveedor, monto, metodo_pago, deducible_impuestos, fecha_gasto, registrado_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmtG->execute([$folioGasto, $categoria, $concepto, $proveedor, $monto, $metodo, $deducible, $fecha, $usuario['id']]);
    header("Location: administracion.php?msg=gasto_ok");
    exit;
}

// 2. Agregar o Actualizar Servicio / Checkup / Paquete en Catálogo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['nuevo_servicio_catalogo']) && verify_csrf($_POST['csrf_token'] ?? '')) {
    $codigo = strtoupper(trim($_POST['codigo']));
    $nombre = trim($_POST['nombre']);
    $area   = $_POST['area'] ?? 'checkup';
    $desc   = trim($_POST['descripcion'] ?? '');
    $precio = (float)$_POST['precio'];
    $sNut   = (int)($_POST['sesiones_nutri_incluidas'] ?? 0);
    $sPsi   = (int)($_POST['sesiones_psico_incluidas'] ?? 0);
    $incChk = !empty($_POST['incluye_checkup']) ? 1 : 0;
    $claveSat = $_POST['clave_prod_serv_sat'] ?: '85121800';

    $stmtS = $pdo->prepare("INSERT INTO catalogo_servicios (codigo, nombre, area, descripcion, precio, sesiones_nutri_incluidas, sesiones_psico_incluidas, incluye_checkup, duracion_minutos, clave_prod_serv_sat, clave_unidad_sat, exento_iva, activo) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 50, ?, 'E48', 1, 1)");
    $stmtS->execute([$codigo, $nombre, $area, $desc, $precio, $sNut, $sPsi, $incChk, $claveSat]);
    header("Location: administracion.php?msg=servicio_ok");
    exit;
}

// Métricas Financieras Globales (Estado de Resultados Autónomo)
$finanzas = $pdo->query("SELECT
    COALESCE(SUM(total_pagado), 0) AS ingresos_brutos,
    COALESCE(SUM(comision_especialista), 0) AS comisiones_totales,
    COUNT(*) AS num_transacciones
    FROM pagos_caja")->fetch();

$totalGastos = (float)$pdo->query("SELECT COALESCE(SUM(monto), 0) FROM gastos_operativos")->fetchColumn();
$ingresosBrutos = (float)$finanzas['ingresos_brutos'];
$comisionesTotales = (float)$finanzas['comisiones_totales'];
$ingresoRetenidoClinica = $ingresosBrutos - $comisionesTotales;
$flujoCajaOperativo = $ingresosBrutos - $totalGastos;
$utilidadNetaDespuesComisiones = $ingresosBrutos - $comisionesTotales - $totalGastos;

// Liquidación de Comisiones por Especialista
$comisionesPorDoctor = $pdo->query("SELECT
    u.id, u.nombre, u.rol, u.area_clinica, u.porcentaje_comision,
    COUNT(pg.id) AS num_cobros,
    COALESCE(SUM(pg.total_pagado), 0) AS total_recaudado,
    COALESCE(SUM(pg.comision_especialista), 0) AS pago_especialista,
    COALESCE(SUM(pg.total_pagado - pg.comision_especialista), 0) AS retencion_clinica
    FROM usuarios u
    LEFT JOIN pagos_caja pg ON pg.nutriologo_id = u.id
    WHERE u.rol IN ('nutriologo', 'psicologo')
    GROUP BY u.id, u.nombre, u.rol, u.area_clinica, u.porcentaje_comision
    ORDER BY total_recaudado DESC")->fetchAll();

// Ingresos por Área / Línea de Servicio (Nutrición, Psicología, Psiconutrición, Checkup, Paquetes)
$ingresosPorArea = $pdo->query("SELECT
    categoria_ingreso,
    COUNT(*) AS operaciones,
    COALESCE(SUM(total_pagado), 0) AS monto_total
    FROM pagos_caja
    GROUP BY categoria_ingreso
    ORDER BY monto_total DESC")->fetchAll();

$gastosList = $pdo->query("SELECT * FROM gastos_operativos ORDER BY fecha_gasto DESC, id DESC")->fetchAll();
$cortesList = $pdo->query("SELECT c.*, u.nombre AS responsable FROM cortes_caja c LEFT JOIN usuarios u ON c.cerrado_por = u.id ORDER BY c.id DESC")->fetchAll();
$serviciosList = $pdo->query("SELECT * FROM catalogo_servicios ORDER BY id ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administración Autónoma, Finanzas y Comisiones | <?= htmlspecialchars(CLINIC_NAME) ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <?php renderNavbar('administracion'); ?>

    <main class="container section-pad">
        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'gasto_ok'): ?>
            <div class="result-box">✅ Gasto operativo registrado y descontado del flujo financiero del consultorio.</div>
        <?php elseif (isset($_GET['msg']) && $_GET['msg'] === 'servicio_ok'): ?>
            <div class="result-box">✅ Nuevo servicio / Checkup / paquete agregado al catálogo oficial de Mostrador y Portal Web.</div>
        <?php endif; ?>

        <!-- KPIS DE DIRECCIÓN Y ADMINISTRACIÓN AUTÓNOMA -->
        <div class="grid-4" style="margin-bottom:20px;">
            <div class="kpi-card">
                <div class="kpi-title">Ingresos Brutos Cobrados</div>
                <div class="kpi-value">$<?= number_format($ingresosBrutos, 2) ?></div>
                <div class="kpi-sub"><?= (int)$finanzas['num_transacciones'] ?> cobros registrados en POS</div>
            </div>
            <div class="kpi-card purple">
                <div class="kpi-title">Nómina de Comisiones Especialistas</div>
                <div class="kpi-value">$<?= number_format($comisionesTotales, 2) ?></div>
                <div class="kpi-sub">Retención Clínica (30%): $<?= number_format($ingresoRetenidoClinica, 2) ?></div>
            </div>
            <div class="kpi-card amber">
                <div class="kpi-title">Gastos Operativos y Laboratorio</div>
                <div class="kpi-value">$<?= number_format($totalGastos, 2) ?></div>
                <div class="kpi-sub">Renta, Insumos, Checkups y Ads</div>
            </div>
            <div class="kpi-card blue">
                <div class="kpi-title">Flujo de Caja Operativo</div>
                <div class="kpi-value">$<?= number_format($flujoCajaOperativo, 2) ?></div>
                <div class="kpi-sub">Balance (Ingresos - Egresos Fijos)</div>
            </div>
        </div>

        <div class="grid-2">
            <!-- TABLA DE LIQUIDACIÓN DE COMISIONES POR ESPECIALISTA -->
            <div class="card">
                <h3>👩‍⚕️👨‍⚕️ Liquidación Autónoma de Comisiones por Especialista</h3>
                <p class="muted" style="margin-bottom:10px;">Cálculo automático de honorarios para Nutriólogos y Psicólogos según lo recaudado en Mostrador.</p>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Especialista</th>
                                <th>Área</th>
                                <th>% Com.</th>
                                <th>Recaudado</th>
                                <th>Pago a Doctor</th>
                                <th>Retención Clínica</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($comisionesPorDoctor as $cd): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($cd['nombre']) ?></strong><br><small class="muted"><?= (int)$cd['num_cobros'] ?> atenciones</small></td>
                                <td><span class="badge <?= $cd['rol'] === 'psicologo' ? 'badge-purple' : 'badge-teal' ?>"><?= strtoupper(htmlspecialchars($cd['area_clinica'])) ?></span></td>
                                <td><?= (float)$cd['porcentaje_comision'] ?>%</td>
                                <td>$<?= number_format((float)$cd['total_recaudado'], 2) ?></td>
                                <td><strong style="color:var(--primary);">$<?= number_format((float)$cd['pago_especialista'], 2) ?></strong></td>
                                <td><strong>$<?= number_format((float)$cd['retencion_clinica'], 2) ?></strong></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- INGRESOS POR LÍNEA DE SERVICIO Y HISTORIAL DE CORTES DE CAJA -->
            <div class="card">
                <h3>📈 Ingresos por Unidad de Negocio (Nutrición, Psicología, Checkups y Paquetes)</h3>
                <div class="table-wrap" style="margin-bottom:16px;">
                    <table>
                        <thead>
                            <tr>
                                <th>Línea de Servicio</th>
                                <th>Transacciones</th>
                                <th>Ingresos Cobrados</th>
                                <th>Participación</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ingresosPorArea as $ia):
                                $pct = $ingresosBrutos > 0 ? round(((float)$ia['monto_total'] / $ingresosBrutos) * 100, 1) : 0;
                            ?>
                            <tr>
                                <td><span class="badge badge-blue"><?= strtoupper(htmlspecialchars($ia['categoria_ingreso'])) ?></span></td>
                                <td><?= (int)$ia['operaciones'] ?> cobros</td>
                                <td><strong>$<?= number_format((float)$ia['monto_total'], 2) ?></strong></td>
                                <td><strong><?= $pct ?>%</strong></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <h4>🔒 Últimos Cortes de Caja y Arqueos Auditados</h4>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr><th>Fecha / Turno</th><th>Efectivo Esperado</th><th>Efectivo Contado</th><th>Diferencia</th><th>Total Neto Día</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cortesList as $cl): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($cl['fecha_corte']) ?></strong><br><small class="muted"><?= htmlspecialchars($cl['turno']) ?></small></td>
                                <td>$<?= number_format((float)$cl['efectivo_esperado'], 2) ?></td>
                                <td>$<?= number_format((float)$cl['efectivo_contado_fisico'], 2) ?></td>
                                <td>
                                    <?php if ((float)$cl['diferencia_arqueo'] == 0.0): ?>
                                        <span class="badge badge-teal">Cuadrado ($0)</span>
                                    <?php else: ?>
                                        <span class="badge badge-rose">$<?= number_format((float)$cl['diferencia_arqueo'], 2) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><strong>$<?= number_format((float)$cl['total_neto_dia'], 2) ?></strong></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- CONTROL DE GASTOS OPERATIVOS Y PROVEEDORES DE CHECKUPS -->
        <div class="grid-2">
            <div class="card">
                <h3>💸 Registrar Gasto Operativo, Insumos o Laboratorio de Checkups</h3>
                <form method="POST" class="grid-2">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="registrar_gasto" value="1">

                    <label>Categoría del Egreso *
                        <select name="categoria" required>
                            <option value="laboratorio_checkups">🔬 Laboratorio Subrogado / Reactivos Checkups</option>
                            <option value="insumos_clinicos">🩺 Insumos Clínicos, InBody y Papelería</option>
                            <option value="renta_consultorio">🏢 Renta de Consultorios y Mantenimiento</option>
                            <option value="marketing_ads">📣 Publicidad Digital (Meta Ads / Google Ads)</option>
                            <option value="servicios_basicos">⚡ Internet, Luz, Agua y Telefonía</option>
                            <option value="comisiones_especialistas">👩‍⚕️ Pago de Comisiones a Especialistas</option>
                        </select>
                    </label>
                    <label>Monto del Gasto ($ MXN) *
                        <input type="number" step="0.01" name="monto" placeholder="1500.00" required>
                    </label>
                    <label style="grid-column: span 2;">Concepto / Descripción del Gasto *
                        <input type="text" name="concepto" placeholder="Ej. Compra de tiras reactivas y procesamiento de 5 Checkups 360°" required>
                    </label>
                    <label>Proveedor / Beneficiario
                        <input type="text" name="proveedor" placeholder="Ej. Laboratorio Clínico / Distribuidora Médica">
                    </label>
                    <label>Origen de Fondos *
                        <select name="metodo_pago">
                            <option value="transferencia">🏦 Transferencia Bancaria Empresarial</option>
                            <option value="efectivo_caja">💵 Efectivo de Caja Chica (Descuenta del Arqueo)</option>
                            <option value="tarjeta_corporativa">💳 Tarjeta Corporativa</option>
                        </select>
                    </label>
                    <label>Fecha del Gasto *
                        <input type="date" name="fecha_gasto" value="<?= date('Y-m-d') ?>" required>
                    </label>
                    <label style="display:flex; align-items:center; gap:8px; padding-top:22px;">
                        <input type="checkbox" name="deducible_impuestos" value="1" checked style="width:18px; height:18px; margin:0;">
                        <span>Cuenta con CFDI de Gasto Deducible</span>
                    </label>
                    <div style="grid-column: span 2;">
                        <button type="submit" class="btn btn-amber" style="width:100%;">📥 Registrar Egreso Operativo</button>
                    </div>
                </form>

                <div class="table-wrap" style="margin-top:14px; max-height:220px; overflow-y:auto;">
                    <table>
                        <thead>
                            <tr><th>Folio / Fecha</th><th>Categoría / Concepto</th><th>Método</th><th>Monto</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($gastosList as $g): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($g['folio_gasto']) ?></strong><br><small class="muted"><?= htmlspecialchars($g['fecha_gasto']) ?></small></td>
                                <td>
                                    <span class="badge badge-slate"><?= htmlspecialchars($g['categoria']) ?></span><br>
                                    <small><?= htmlspecialchars($g['concepto']) ?></small>
                                </td>
                                <td><small><?= htmlspecialchars($g['metodo_pago']) ?></small></td>
                                <td><strong style="color:var(--rose);">-$<?= number_format((float)$g['monto'], 2) ?></strong></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- GESTIÓN DEL CATÁLOGO DE SERVICIOS, CHECKUPS Y PAQUETES -->
            <div class="card">
                <h3>🏷️ Configurar Catálogo de Consultas, Checkups 360° y Paquetes</h3>
                <form method="POST" class="grid-2">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="nuevo_servicio_catalogo" value="1">

                    <label>Código Interno *
                        <input type="text" name="codigo" placeholder="Ej. CHK-EJEC" required>
                    </label>
                    <label>Área Clínica *
                        <select name="area">
                            <option value="checkup">🔬 Checkup Médico Integral</option>
                            <option value="paquete">📦 Paquete / Membresía Prepagada</option>
                            <option value="psiconutricion">🤝 Psiconutrición Interdisciplinaria</option>
                            <option value="nutricion">🥗 Nutrición Clínica / Deportiva</option>
                            <option value="psicologia">🧠 Psicología Clínica / TCC</option>
                        </select>
                    </label>
                    <label style="grid-column: span 2;">Nombre Comercial del Servicio / Checkup *
                        <input type="text" name="nombre" placeholder="Ej. Checkup Ejecutivo Antiestrés + Cortisol + InBody" required>
                    </label>
                    <label style="grid-column: span 2;">Descripción para Portal Web y Recibos
                        <input type="text" name="descripcion" placeholder="Incluye signos vitales, perfil lipídico, cortisol y consulta">
                    </label>
                    <label>Precio de Lista ($ MXN) *
                        <input type="number" step="0.01" name="precio" value="1850.00" required>
                    </label>
                    <label>Clave SAT CFDI 4.0
                        <input type="text" name="clave_prod_serv_sat" value="85121800">
                    </label>
                    <label>Sesiones Nutrición Incluidas
                        <input type="number" name="sesiones_nutri_incluidas" value="1">
                    </label>
                    <label>Sesiones Psicología Incluidas
                        <input type="number" name="sesiones_psico_incluidas" value="1">
                    </label>
                    <label style="grid-column: span 2; display:flex; align-items:center; gap:8px;">
                        <input type="checkbox" name="incluye_checkup" value="1" checked style="width:18px; height:18px; margin:0;">
                        <span>🔬 Incluye Checkup de Laboratorio / Signos Vitales (Requiere ayuno en agenda)</span>
                    </label>
                    <div style="grid-column: span 2;">
                        <button type="submit" class="btn btn-primary" style="width:100%;">➕ Publicar en Catálogo de Mostrador y Reserva Online</button>
                    </div>
                </form>
            </div>
        </div>
    </main>
</body>
</html>
