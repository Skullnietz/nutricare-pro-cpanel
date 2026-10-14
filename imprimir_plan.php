<?php
require_once __DIR__ . '/db_connection.php';
$pdo = getDB();
$pacienteId = (int)($_GET['paciente_id'] ?? 1);
$stmtPlan = $pdo->prepare("SELECT * FROM planes_alimentacion WHERE paciente_id = ? ORDER BY creado_en DESC LIMIT 1");
$stmtPlan->execute([$pacienteId]);
$plan = $stmtPlan->fetch();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Plan de Alimentación Imprimible | <?= htmlspecialchars(CLINIC_NAME) ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="container section-pad">
        <button onclick="window.print()" class="btn btn-primary no-print">🖨️ Imprimir / Guardar PDF</button>
        <?php if ($plan): ?>
        <div class="card" style="margin-top:16px;">
            <h2>🥗 <?= htmlspecialchars($plan['nombre_plan']) ?></h2>
            <p><strong>Meta Diaria:</strong> <?= (int)$plan['kcal_objetivo'] ?> kcal · P: <?= (int)$plan['proteina_g'] ?>g · C: <?= (int)$plan['carbohidratos_g'] ?>g · L: <?= (int)$plan['lipidos_g'] ?>g · Agua: <?= (int)$plan['agua_ml'] ?> ml</p>
            <table>
                <tr><th>Desayuno</th><td><?= nl2br(htmlspecialchars($plan['menu_desayuno'])) ?></td></tr>
                <tr><th>Colación 1</th><td><?= nl2br(htmlspecialchars($plan['menu_colacion_1'])) ?></td></tr>
                <tr><th>Comida</th><td><?= nl2br(htmlspecialchars($plan['menu_comida'])) ?></td></tr>
                <tr><th>Colación 2</th><td><?= nl2br(htmlspecialchars($plan['menu_colacion_2'])) ?></td></tr>
                <tr><th>Cena</th><td><?= nl2br(htmlspecialchars($plan['menu_cena'])) ?></td></tr>
            </table>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
