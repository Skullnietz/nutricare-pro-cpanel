<?php
declare(strict_types=1);
require_once __DIR__ . '/db_connection.php';
$pdo = getDB();
$pacienteId = (int)($_GET['paciente_id'] ?? 1);

$stmtPac = $pdo->prepare("SELECT p.*, u1.nombre AS nutriologo_nombre, u1.cedula_profesional AS cedula_nutri,
                          u2.nombre AS psicologo_nombre, u2.cedula_profesional AS cedula_psico
                          FROM pacientes p
                          LEFT JOIN usuarios u1 ON p.nutriologo_id = u1.id
                          LEFT JOIN usuarios u2 ON p.psicologo_id = u2.id
                          WHERE p.id = ?");
$stmtPac->execute([$pacienteId]);
$paciente = $stmtPac->fetch();

$stmtPlan = $pdo->prepare("SELECT * FROM planes_alimentacion WHERE paciente_id = ? ORDER BY id DESC LIMIT 1");
$stmtPlan->execute([$pacienteId]);
$plan = $stmtPlan->fetch();

$stmtAntro = $pdo->prepare("SELECT * FROM consultas_antropometria WHERE paciente_id = ? ORDER BY id DESC LIMIT 1");
$stmtAntro->execute([$pacienteId]);
$antro = $stmtAntro->fetch();

$stmtChk = $pdo->prepare("SELECT * FROM checkups_clinicos WHERE paciente_id = ? ORDER BY id DESC LIMIT 1");
$stmtChk->execute([$pacienteId]);
$checkup = $stmtChk->fetch();

$stmtPsi = $pdo->prepare("SELECT * FROM consultas_psicologia WHERE paciente_id = ? ORDER BY id DESC LIMIT 1");
$stmtPsi->execute([$pacienteId]);
$psico = $stmtPsi->fetch();

$actionTop = '<button onclick="window.print()" class="btn btn-primary btn-sm">Imprimir / Guardar PDF</button>';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reporte Clínico Integral | <?= htmlspecialchars(CLINIC_NAME) ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<?php renderAppStart('Reporte Clínico para el Paciente', 'Resumen imprimible de Checkup, Nutrición y Recomendaciones', 'consulta', [], $actionTop); ?>

<div class="no-print" style="margin-bottom:16px;">
    <a href="consulta.php?paciente_id=<?= $pacienteId ?>" class="btn btn-outline btn-sm">&larr; Volver al Expediente</a>
</div>

<div class="card" style="max-width:920px;margin:0 auto;border-top:4px solid var(--primary);">
    <div class="flex-between" style="border-bottom:1px solid var(--border);padding-bottom:16px;margin-bottom:18px;">
        <div>
            <h2 style="font-size:1.35rem;"><?= htmlspecialchars(CLINIC_NAME) ?></h2>
            <p class="text-xs text-muted"><?= htmlspecialchars(CLINIC_ADDRESS) ?> · Tel: <?= htmlspecialchars(CLINIC_PHONE) ?></p>
        </div>
        <div class="text-right">
            <span class="badge badge-teal">Expediente: <?= htmlspecialchars($paciente['folio_expediente'] ?? '') ?></span>
            <div class="text-xs text-muted" style="margin-top:4px;">Fecha de emisión: <?= date('Y-m-d') ?></div>
        </div>
    </div>

    <div class="grid-2" style="background:var(--bg-body);padding:14px 18px;border-radius:10px;margin-bottom:20px;font-size:0.88rem;">
        <div>
            <strong>Paciente:</strong> <?= htmlspecialchars($paciente['nombre_completo'] ?? '') ?> (<?= calcularEdad($paciente['fecha_nacimiento'] ?? null) ?> años)<br>
            <strong>Objetivo Clínico:</strong> <?= htmlspecialchars($paciente['objetivo_principal'] ?? '') ?>
        </div>
        <div>
            <strong>Nutrición y Checkup:</strong> <?= htmlspecialchars($paciente['nutriologo_nombre'] ?: 'Dra. Sofía Mendoza') ?> (<?= htmlspecialchars($paciente['cedula_nutri'] ?: 'Céd. 8492014') ?>)<br>
            <strong>Psicología y Psiconutrición:</strong> <?= htmlspecialchars($paciente['psicologo_nombre'] ?: 'Dr. Daniel Herrera') ?> (<?= htmlspecialchars($paciente['cedula_psico'] ?: 'Céd. 6839201') ?>)
        </div>
    </div>

    <?php if ($checkup): ?>
    <h3 style="margin-bottom:10px;">1. Resultados de Checkup Preventivo (<?= htmlspecialchars($checkup['folio_checkup']) ?>)</h3>
    <div class="grid-4" style="margin-bottom:14px;">
        <div class="kpi-card">
            <span class="kpi-label">Signos Vitales</span>
            <div style="font-size:1.1rem;font-weight:700;"><?= (int)$checkup['presion_sistolica'] ?>/<?= (int)$checkup['presion_diastolica'] ?> mmHg</div>
            <div class="kpi-sub">FC: <?= (int)$checkup['frecuencia_cardiaca'] ?> lpm · SpO2: <?= (int)$checkup['spo2_oxigenacion'] ?>%</div>
        </div>
        <div class="kpi-card">
            <span class="kpi-label">Glucosa / Insulina</span>
            <div style="font-size:1.1rem;font-weight:700;">HOMA-IR: <?= $checkup['indice_homa_ir'] ?></div>
            <div class="kpi-sub">Glucosa: <?= $checkup['glucosa_ayuno_mg'] ?> mg/dL · HbA1c: <?= $checkup['hba1c_pct'] ?>%</div>
        </div>
        <div class="kpi-card">
            <span class="kpi-label">Perfil Lipídico</span>
            <div style="font-size:1.1rem;font-weight:700;">Col: <?= $checkup['colesterol_total_mg'] ?> mg/dL</div>
            <div class="kpi-sub">TG: <?= $checkup['trigliceridos_mg'] ?> · HDL: <?= $checkup['hdl_mg'] ?></div>
        </div>
        <div class="kpi-card">
            <span class="kpi-label">Score Integral</span>
            <div style="font-size:1.1rem;font-weight:700;color:var(--primary);"><?= (int)$checkup['score_salud_integral'] ?> / 100</div>
            <div class="kpi-sub">TSH: <?= $checkup['tsh_tiroides'] ?> · Vit D: <?= $checkup['vitamina_d_ng'] ?></div>
        </div>
    </div>
    <p class="text-sm" style="margin-bottom:18px;">
        <strong>Interpretación:</strong> <?= htmlspecialchars($checkup['interpretacion_biomarcadores']) ?><br>
        <strong>Plan de Acción:</strong> <?= htmlspecialchars($checkup['plan_accion_checkup']) ?>
    </p>
    <?php endif; ?>

    <?php if ($antro): ?>
    <h3 style="border-top:1px solid var(--border);padding-top:14px;margin-bottom:8px;">2. Composición Corporal InBody / ISAK</h3>
    <p class="text-sm" style="margin-bottom:18px;">
        <strong>Peso:</strong> <?= (float)$antro['peso_kg'] ?> kg ·
        <strong>Talla:</strong> <?= (float)$antro['talla_cm'] ?> cm ·
        <strong>IMC:</strong> <?= (float)$antro['imc'] ?> kg/m² ·
        <strong>% Grasa:</strong> <?= $antro['porcentaje_grasa'] ?>% ·
        <strong>% Músculo:</strong> <?= $antro['porcentaje_musculo'] ?>% ·
        <strong>Cintura:</strong> <?= $antro['cintura_cm'] ?> cm ·
        <strong>Pliegues ISAK:</strong> <?= $antro['suma_4_pliegues_mm'] ?> mm<br>
        <strong>Diagnóstico Nutricional:</strong> <?= htmlspecialchars($antro['diagnostico_nutricional']) ?>
    </p>
    <?php endif; ?>

    <?php if ($plan): ?>
    <h3 style="border-top:1px solid var(--border);padding-top:14px;margin-bottom:10px;">3. Plan de Alimentación: <?= htmlspecialchars($plan['nombre_plan']) ?></h3>
    <div style="background:var(--primary-soft);padding:10px 14px;border-radius:8px;margin-bottom:12px;font-size:0.86rem;">
        <strong>Meta Diaria:</strong> <?= (int)$plan['kcal_objetivo'] ?> kcal ·
        <strong>Proteína:</strong> <?= (int)$plan['proteina_g'] ?>g ·
        <strong>Carbohidratos:</strong> <?= (int)$plan['carbohidratos_g'] ?>g ·
        <strong>Grasas:</strong> <?= (int)$plan['lipidos_g'] ?>g ·
        <strong>Hidratación:</strong> <?= (int)$plan['agua_ml'] ?> ml
    </div>

    <div class="table-wrap" style="margin-bottom:18px;">
        <table>
            <thead>
                <tr>
                    <th style="width:160px;">Tiempo de Comida</th>
                    <th>Menú y Opciones Sugeridas</th>
                </tr>
            </thead>
            <tbody>
                <tr><td><strong>Desayuno</strong></td><td><?= nl2br(htmlspecialchars($plan['menu_desayuno'] ?? '')) ?></td></tr>
                <tr><td><strong>Colación Matutina</strong></td><td><?= nl2br(htmlspecialchars($plan['menu_colacion_1'] ?? '')) ?></td></tr>
                <tr><td><strong>Comida Principal</strong></td><td><?= nl2br(htmlspecialchars($plan['menu_comida'] ?? '')) ?></td></tr>
                <tr><td><strong>Colación Vespertina</strong></td><td><?= nl2br(htmlspecialchars($plan['menu_colacion_2'] ?? '')) ?></td></tr>
                <tr><td><strong>Cena</strong></td><td><?= nl2br(htmlspecialchars($plan['menu_cena'] ?? '')) ?></td></tr>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php if ($psico): ?>
    <h3 style="border-top:1px solid var(--border);padding-top:14px;margin-bottom:8px;">4. Acuerdos de Psiconutrición y Bienestar</h3>
    <p class="text-sm">
        <strong>Tareas y Hábitos de la Semana:</strong><br>
        <?= nl2br(htmlspecialchars($psico['tareas_terapeuticas'] ?? '')) ?>
    </p>
    <?php endif; ?>
</div>

<?php renderAppEnd(); ?>
</body>
</html>
