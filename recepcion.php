<?php
require_once __DIR__ . '/db_connection.php';
$usuario = requireAuth();
$pdo = getDB();
$fechaFiltro = $_GET['fecha'] ?? date('Y-m-d');
$docFiltro   = isset($_GET['nutriologo_id']) ? (int)$_GET['nutriologo_id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cambiar_estado_cita']) && verify_csrf($_POST['csrf_token'] ?? '')) {
      $citaId = (int)$_POST['cita_id'];
      $nuevoEstado = $_POST['nuevo_estado'];
      if ($nuevoEstado === 'en_sala') {
                $pdo->prepare("UPDATE citas SET estado = ?, hora_llegada_sala = CURTIME() WHERE id = ?")->execute([$nuevoEstado, $citaId]);
            } else {
                $pdo->prepare("UPDATE citas SET estado = ? WHERE id = ?")->execute([$nuevoEstado, $citaId]);
            }
      header("Location: recepcion.php?fecha={$fechaFiltro}&nutriologo_id={$docFiltro}");
      exit;
  }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['registrar_cobro']) && verify_csrf($_POST['csrf_token'] ?? '')) {
      $folio = 'REC-' . date('Ymd') . '-' . rand(100, 999);
      $total = max(0, (float)$_POST['monto'] - (float)($_POST['descuento'] ?? 0));
      $stmt = $pdo->prepare("INSERT INTO pagos_caja (folio_recibo, nutriologo_id, concepto, monto, descuento, total_pagado, metodo_pago, registrado_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
      $stmt->execute([$folio, (int)$_POST['nutriologo_id'], trim($_POST['concepto']), (float)$_POST['monto'], (float)($_POST['descuento'] ?? 0), $total, $_POST['metodo_pago'], $usuario['id']]);
      header("Location: recepcion.php?fecha={$fechaFiltro}&nutriologo_id={$docFiltro}");
      exit;
  }

$sqlCitas = "SELECT c.*, COALESCE(p.nombre_completo, c.nombre_prospecto) AS nombre_mostrar, COALESCE(p.telefono_whatsapp, c.telefono_prospecto) AS telefono_mostrar, u.nombre AS doctor_nombre, u.color_agenda FROM citas c LEFT JOIN pacientes p ON c.paciente_id = p.id JOIN usuarios u ON c.nutriologo_id = u.id WHERE c.fecha_cita = :fecha";
$params = ['fecha' => $fechaFiltro];
if ($docFiltro > 0) { $sqlCitas .= " AND c.nutriologo_id = :doc"; $params['doc'] = $docFiltro; }
$sqlCitas .= " ORDER BY c.hora_inicio ASC";
$stmtCitas = $pdo->prepare($sqlCitas);
$stmtCitas->execute($params);
$citasDia = $stmtCitas->fetchAll();

$stmtCorte = $pdo->prepare("SELECT u.nombre AS nutriologo, pg.metodo_pago, COUNT(pg.id) AS num_cobros, SUM(pg.total_pagado) AS subtotal FROM pagos_caja pg JOIN usuarios u ON pg.nutriologo_id = u.id WHERE DATE(pg.fecha_pago) = ? GROUP BY u.nombre, pg.metodo_pago");
$stmtCorte->execute([$fechaFiltro]);
$corteCaja = $stmtCorte->fetchAll(
  <body>
      <header class="topbar">
          <div class="container flex-between">
              <div class="brand">️ Recepción, Sala de Espera y Caja</div>
              <nav class="nav-links">
                  <a href="index.php">Página Pública</a>
                  <a href="recepcion.php" class="active">Recepción y Caja</a>
                  <a href="consulta.php">Estación Clínica</a>
              </nav>
          </div>
      </header>
      <main class="container section-pad">
          <div class="card">
              <h2>📋 Agenda del Día y Sala de Espera (<?= htmlspecialchars($fechaFiltro) ?>)</h2>
              <table>
                  <thead>
                      <tr><th>Hora</th><th>Paciente</th><th>Especialista</th><th>Tipo</th><th>Estado</th><th>Acciones</th></tr>
                  </thead>
                  <tbody>
                      <?php foreach ($citasDia as $c): ?>
                      <tr>
                          <td><strong><?= substr($c['hora_inicio'], 0, 5) ?></strong><br><small>Llegada: <?= $c['hora_llegada_sala'] ?: '—' ?></small></td>
                          <td><strong><?= htmlspecialchars($c['nombre_mostrar']) ?></strong><br><small><?= htmlspecialchars($c['telefono_mostrar']) ?></small></td>
                          <td><?= htmlspecialchars($c['doctor_nombre']) ?></td>
                          <td><?= htmlspecialchars($c['tipo_cita']) ?></td>
                          <td><span class="badge badge-teal"><?= htmlspecialchars($c['estado']) ?></span></td>
                          <td>
                              <form method="POST" style="display:inline;">
                                  <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                  <input type="hidden" name="cambiar_estado_cita" value="1">
                                  <input type="hidden" name="cita_id" value="<?= $c['id'] ?>">
                                  <button name="nuevo_estado" value="en_sala" class="btn btn-outline btn-sm">En Sala</button>
                                  <button name="nuevo_estado" value="en_consulta" class="btn btn-primary btn-sm">En Consulta</button>
                              </form>
                              <a href="<?= generarLinkWhatsApp($c['telefono_mostrar'], $c['nombre_mostrar'], $c['fecha_cita'], substr($c['hora_inicio'],0,5), $c['doctor_nombre']) ?>" target="_blank" class="btn btn-whatsapp btn-sm">WhatsApp</a>
                          </td>
                      </tr>
                      <?php endforeach; ?>
                  </tbody>
              </table>
          </div>
          <div class="grid-2" style="margin-top:20px;">
              <div class="card">
                  <h3>💳 Registrar Cobro en Caja</h3>
                  <form method="POST">
                      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                      <input type="hidden" name="registrar_cobro" value="1">
                      <label>Especialista <select name="nutriologo_id"><option value="1">Dra. Sofía Mendoza</option><option value="2">Dr. Alejandro Vega</option></select></label>
                      <label>Concepto <input type="text" name="concepto" value="Consulta Nutricional" required></label>
                      <label>Monto ($ MXN) <input type="number" name="monto" value="750" required></label>
                      <label>Método <select name="metodo_pago"><option value="efectivo">Efectivo</option><option value="tarjeta">Tarjeta</option><option value="transferencia">Transferencia</option></select></label>
                      <button type="submit" class="btn btn-primary">Guardar Cobro</button>
                  </form>
              </div>
              <div class="card">
                  <h3>📊 Corte de Caja por Especialista</h3>
                  <table>
                      <thead><tr><th>Nutriólogo</th><th>Método</th><th>Cobros</th><th>Subtotal</th></tr></thead>
                      <tbody>
                          <?php foreach ($corteCaja as $f): ?>
                          <tr><td><?= htmlspecialchars($f['nutriologo']) ?></td><td><?= htmlspecialchars($f['metodo_pago']) ?></td><td><?= (int)$f['num_cobros'] ?></td><td>$<?= number_format($f['subtotal'], 2) ?></td></tr>
                          <?php endforeach; ?>
                      </tbody>
                  </table>
              </div>
          </div>
      </main>
  </body>
  </html>
);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Recepción y Caja | <?= htmlspecialchars(CLINIC_NAME) ?></title>
    <link rel="stylesheet" href="style.css">
</head>
