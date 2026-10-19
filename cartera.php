<?php
declare(strict_types=1);
require_once __DIR__ . '/db_connection.php';

$pdo = getDB();
$usuario = requireAuth();
$hoy = date('Y-m-d');
$mensajeExito = '';
$mensajeError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $mensajeError = 'Token de seguridad inválido.';
    } else {
        $accion = $_POST['accion'] ?? '';

        if ($accion === 'alta_paciente') {
            $nombre = trim($_POST['nombre_completo'] ?? '');
            $fechaNac = $_POST['fecha_nacimiento'] ?? '1990-01-01';
            $sexo = $_POST['sexo'] ?? 'F';
            $telefono = trim($_POST['telefono_whatsapp'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $ocupacion = trim($_POST['ocupacion'] ?? '');
            $modalidad = $_POST['modalidad_atencion'] ?? 'psiconutricion_integral';
            $nutriologoId = !empty($_POST['nutriologo_id']) ? (int)$_POST['nutriologo_id'] : null;
            $psicologoId = !empty($_POST['psicologo_id']) ? (int)$_POST['psicologo_id'] : null;
            $rfc = strtoupper(trim($_POST['rfc'] ?? 'XAXX010101000'));
            $razonSocial = strtoupper(trim($_POST['razon_social'] ?? $nombre));
            $regimen = $_POST['regimen_fiscal'] ?? '612';
            $cpFiscal = trim($_POST['codigo_postal_fiscal'] ?? '06600');
            $objetivo = trim($_POST['objetivo_principal'] ?? '');
            $motivoPsico = trim($_POST['motivo_psicologico'] ?? '');

            if ($nombre === '' || $telefono === '') {
                $mensajeError = 'Nombre completo y teléfono son obligatorios.';
            } else {
                $countPac = (int)$pdo->query("SELECT COUNT(*) FROM pacientes")->fetchColumn() + 1;
                $folio = 'EXP-' . date('Y') . '-' . str_pad((string)$countPac, 3, '0', STR_PAD_LEFT);

                $stmt = $pdo->prepare("INSERT INTO pacientes (folio_expediente, nutriologo_id, psicologo_id, modalidad_atencion, nombre_completo, fecha_nacimiento, sexo, telefono_whatsapp, email, ocupacion, estatus_cartera, nombre_paquete_activo, ultima_visita, proxima_cita_sugerida, rfc, razon_social, regimen_fiscal, codigo_postal_fiscal, uso_cfdi, email_facturacion, objetivo_principal, motivo_psicologico) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'activo', 'Sin paquete (Pago por sesión)', ?, ?, ?, ?, ?, ?, 'D01', ?, ?, ?)");
                $stmt->execute([
                    $folio,
                    $nutriologoId,
                    $psicologoId,
                    $modalidad,
                    $nombre,
                    $fechaNac,
                    $sexo,
                    $telefono,
                    $email,
                    $ocupacion,
                    $hoy,
                    date('Y-m-d', strtotime('+7 days')),
                    $rfc ?: 'XAXX010101000',
                    $razonSocial ?: strtoupper($nombre),
                    $regimen,
                    $cpFiscal ?: '06600',
                    $email,
                    $objetivo,
                    $motivoPsico
                ]);
                $mensajeExito = "Expediente {$folio} de {$nombre} creado exitosamente.";
            }
        }

        elseif ($accion === 'liquidar_saldo') {
            $pacId = (int)($_POST['paciente_id'] ?? 0);
            $stmtP = $pdo->prepare("SELECT * FROM pacientes WHERE id = ?");
            $stmtP->execute([$pacId]);
            if ($pac = $stmtP->fetch()) {
                $saldo = (float)$pac['saldo_pendiente'];
                if ($saldo > 0) {
                    $folioRec = 'REC-' . date('Ymd') . '-' . rand(100, 999);
                    $docId = (int)($pac['nutriologo_id'] ?: ($pac['psicologo_id'] ?: 1));
                    $comision = round($saldo * 0.70, 2);
                    $pdo->prepare("INSERT INTO pagos_caja (folio_recibo, paciente_id, nutriologo_id, categoria_ingreso, concepto, monto, descuento, impuestos, total_pagado, saldo_pendiente_generado, metodo_pago, referencia_pago, comision_especialista, requiere_factura, estado_factura, registrado_por) VALUES (?, ?, ?, 'abono_cartera', 'Liquidación de saldo pendiente en cartera', ?, 0, 0, ?, 0, 'transferencia', 'ABONO-CRM', ?, 0, 'no_solicitada', ?)")
                        ->execute([$folioRec, $pacId, $docId, $saldo, $saldo, $comision, $usuario['id']]);
                    $pdo->prepare("UPDATE pacientes SET saldo_pendiente = 0.00, total_historico_pagado = total_historico_pagado + ?, estatus_cartera = 'activo' WHERE id = ?")
                        ->execute([$saldo, $pacId]);
                    $mensajeExito = "Saldo de $" . number_format($saldo, 2) . " liquidado con recibo {$folioRec}.";
                }
            }
        }
    }
}

$filtro = $_GET['filtro'] ?? 'todos';
$busqueda = trim($_GET['q'] ?? '');

$sql = "
    SELECT p.*,
           un.nombre AS nutriologo_nombre,
           up.nombre AS psicologo_nombre
    FROM pacientes p
    LEFT JOIN usuarios un ON p.nutriologo_id = un.id
    LEFT JOIN usuarios up ON p.psicologo_id = up.id
    WHERE 1=1
";
$params = [];
if ($filtro === 'adeudo') {
    $sql .= " AND p.saldo_pendiente > 0";
} elseif ($filtro === 'paquete') {
    $sql .= " AND (p.sesiones_disponibles_nutri > 0 OR p.sesiones_disponibles_psico > 0 OR p.checkups_disponibles > 0)";
} elseif ($filtro === 'riesgo') {
    $sql .= " AND p.estatus_cartera = 'en_riesgo'";
}
if ($busqueda !== '') {
    $sql .= " AND (p.nombre_completo LIKE ? OR p.folio_expediente LIKE ? OR p.telefono_whatsapp LIKE ?)";
    $like = "%{$busqueda}%";
    $params = [$like, $like, $like];
}
$sql .= " ORDER BY p.id ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$pacientes = $stmt->fetchAll();

$nutriologos = $pdo->query("SELECT id, nombre FROM usuarios WHERE rol = 'nutriologo' AND activo = 1")->fetchAll();
$psicologos = $pdo->query("SELECT id, nombre FROM usuarios WHERE rol = 'psicologo' AND activo = 1")->fetchAll();

$subPills = [
    ['label' => 'Todos', 'href' => 'cartera.php?filtro=todos', 'active' => ($filtro === 'todos'), 'count' => null],
    ['label' => 'Con Paquete Activo', 'href' => 'cartera.php?filtro=paquete', 'active' => ($filtro === 'paquete'), 'count' => null],
    ['label' => 'Con Saldo Pendiente', 'href' => 'cartera.php?filtro=adeudo', 'active' => ($filtro === 'adeudo'), 'count' => null],
    ['label' => 'Seguimiento / Riesgo', 'href' => 'cartera.php?filtro=riesgo', 'active' => ($filtro === 'riesgo'), 'count' => null],
];
$actionTop = '<button type="button" onclick="openModal(\'modalNuevoPaciente\')" class="btn btn-primary btn-sm">+ Nuevo Paciente</button>';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Directorio de Pacientes | <?= htmlspecialchars(CLINIC_NAME) ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<?php renderAppStart('Pacientes y Cartera', 'Directorio clínico, control de paquetes prepagados y saldos', 'cartera', $subPills, $actionTop); ?>

<?php if ($mensajeExito): ?>
    <div class="alert alert-success"><?= htmlspecialchars($mensajeExito) ?></div>
<?php endif; ?>
<?php if ($mensajeError): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($mensajeError) ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <div>
            <h2>Expedientes Activos (<?= count($pacientes) ?>)</h2>
            <p>Consulta el estado de sesiones prepagadas, saldos por cobrar o abre el expediente clínico.</p>
        </div>
        <form method="GET" class="flex-gap">
            <input type="hidden" name="filtro" value="<?= htmlspecialchars($filtro) ?>">
            <input type="search" name="q" value="<?= htmlspecialchars($busqueda) ?>" class="form-control" placeholder="Buscar nombre, folio o teléfono..." style="width:260px;">
            <button type="submit" class="btn btn-outline btn-sm">Buscar</button>
        </form>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Paciente</th>
                    <th>Especialistas Asignados</th>
                    <th>Paquete y Sesiones Disponibles</th>
                    <th>Estado de Cuenta</th>
                    <th class="text-right">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($pacientes)): ?>
                    <tr>
                        <td colspan="5" class="text-center text-muted" style="padding:32px;">No se encontraron pacientes con ese criterio.</td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($pacientes as $p):
                    $edad = calcularEdad($p['fecha_nacimiento']);
                    $waCobro = generarLinkCobranzaWhatsApp($p['telefono_whatsapp'], $p['nombre_completo'], (float)$p['saldo_pendiente'], (string)$p['nombre_paquete_activo']);
                ?>
                    <tr>
                        <td>
                            <div class="avatar-row">
                                <img src="<?= htmlspecialchars(getFotoPaciente((int)$p['id'])) ?>" alt="" class="avatar-sm">
                                <div>
                                    <strong><?= htmlspecialchars($p['nombre_completo']) ?></strong>
                                    <div class="text-xs text-muted">
                                        <?= htmlspecialchars($p['folio_expediente']) ?> · <?= $edad ?> años · Tel: <?= htmlspecialchars($p['telefono_whatsapp']) ?>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div class="text-sm">
                                <?php if ($p['nutriologo_nombre']): ?>
                                    <div><span class="badge badge-teal">Nutri</span> <?= htmlspecialchars($p['nutriologo_nombre']) ?></div>
                                <?php endif; ?>
                                <?php if ($p['psicologo_nombre']): ?>
                                    <div style="margin-top:3px;"><span class="badge badge-purple">Psico</span> <?= htmlspecialchars($p['psicologo_nombre']) ?></div>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <strong class="text-sm"><?= htmlspecialchars($p['nombre_paquete_activo'] ?? 'Pago por sesión') ?></strong>
                            <div class="flex-gap" style="margin-top:4px;">
                                <span class="badge badge-teal"><?= (int)$p['sesiones_disponibles_nutri'] ?> Nutri</span>
                                <span class="badge badge-purple"><?= (int)$p['sesiones_disponibles_psico'] ?> Psico</span>
                                <span class="badge badge-blue"><?= (int)$p['checkups_disponibles'] ?> Checkup</span>
                            </div>
                        </td>
                        <td>
                            <?php if ((float)$p['saldo_pendiente'] > 0): ?>
                                <span class="badge badge-rose">Adeudo: $<?= number_format((float)$p['saldo_pendiente'], 2) ?></span>
                            <?php else: ?>
                                <span class="badge badge-teal">Al corriente</span>
                            <?php endif; ?>
                            <div class="text-xs text-muted" style="margin-top:3px;">
                                Pagado hist.: $<?= number_format((float)$p['total_historico_pagado'], 2) ?>
                            </div>
                        </td>
                        <td class="text-right">
                            <div class="flex-gap" style="justify-content:flex-end;">
                                <a href="consulta.php?paciente_id=<?= (int)$p['id'] ?>" class="btn btn-primary btn-xs">Abrir Expediente</a>
                                <?php if ((float)$p['saldo_pendiente'] > 0): ?>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                                        <input type="hidden" name="accion" value="liquidar_saldo">
                                        <input type="hidden" name="paciente_id" value="<?= (int)$p['id'] ?>">
                                        <button type="submit" class="btn btn-accent btn-xs">Liquidar</button>
                                    </form>
                                <?php endif; ?>
                                <a href="<?= htmlspecialchars($waCobro) ?>" target="_blank" class="btn btn-outline btn-xs">WhatsApp</a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- MODAL ALTA DE PACIENTE -->
<div id="modalNuevoPaciente" class="modal-backdrop">
    <div class="modal-dialog">
        <div class="modal-header">
            <h3>Registrar Nuevo Paciente</h3>
            <button type="button" class="modal-close" onclick="closeModal('modalNuevoPaciente')">&times;</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="accion" value="alta_paciente">

                <div class="form-row">
                    <div class="form-group">
                        <label>Nombre completo *</label>
                        <input type="text" name="nombre_completo" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Teléfono WhatsApp *</label>
                        <input type="text" name="telefono_whatsapp" class="form-control" required>
                    </div>
                </div>

                <div class="form-row-3">
                    <div class="form-group">
                        <label>Fecha de nacimiento *</label>
                        <input type="date" name="fecha_nacimiento" class="form-control" value="1994-06-15" required>
                    </div>
                    <div class="form-group">
                        <label>Sexo biológico</label>
                        <select name="sexo" class="form-control">
                            <option value="F">Femenino</option>
                            <option value="M">Masculino</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Modalidad</label>
                        <select name="modalidad_atencion" class="form-control">
                            <option value="psiconutricion_integral">Psiconutrición Integral</option>
                            <option value="nutricion">Nutrición Clínica</option>
                            <option value="psicologia">Psicología Clínica</option>
                            <option value="checkup_preventivo">Checkup Preventivo</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Nutriólogo titular</label>
                        <select name="nutriologo_id" class="form-control">
                            <option value="">-- Sin asignar --</option>
                            <?php foreach ($nutriologos as $n): ?>
                                <option value="<?= (int)$n['id'] ?>"><?= htmlspecialchars($n['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Psicólogo titular</label>
                        <select name="psicologo_id" class="form-control">
                            <option value="">-- Sin asignar --</option>
                            <?php foreach ($psicologos as $ps): ?>
                                <option value="<?= (int)$ps['id'] ?>"><?= htmlspecialchars($ps['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-row-3">
                    <div class="form-group">
                        <label>RFC Fiscal</label>
                        <input type="text" name="rfc" class="form-control" placeholder="XAXX010101000">
                    </div>
                    <div class="form-group">
                        <label>Código Postal Fiscal</label>
                        <input type="text" name="codigo_postal_fiscal" class="form-control" placeholder="06600">
                    </div>
                    <div class="form-group">
                        <label>Correo electrónico</label>
                        <input type="email" name="email" class="form-control" placeholder="paciente@correo.com">
                    </div>
                </div>

                <div class="form-group">
                    <label>Objetivo nutricional / metabólico</label>
                    <input type="text" name="objetivo_principal" class="form-control" placeholder="Ej. Control de glucosa y reducción de porcentaje graso">
                </div>

                <div class="form-group">
                    <label>Motivo psicológico / emocional</label>
                    <input type="text" name="motivo_psicologico" class="form-control" placeholder="Ej. Manejo de ansiedad vespertina y estrés laboral">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('modalNuevoPaciente')">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar Paciente</button>
            </div>
        </form>
    </div>
</div>

<?php renderAppEnd(); ?>
</body>
</html>
