<?php
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
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reporte Clínico Integral (Checkup + Nutrición + Psiconutrición) | <?= htmlspecialchars(CLINIC_NAME) ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <?php renderNavbar('consulta'); ?>

    <div class="container section-pad">
        <div class="flex-between no-print" style="margin-bottom:16px;">
            <a href="consulta.php?paciente_id=<?= $pacienteId ?>" class="btn btn-outline">← Volver a Estación Clínica</a>
            <button onclick="window.print()" class="btn btn-primary">🖨️ Imprimir / Guardar Reporte Integral en PDF</button>
        </div>

        <div class="card" style="border-top: 5px solid var(--primary);">
            <div class="flex-between" style="border-bottom: 1px solid var(--border); padding-bottom: 14px; margin-bottom: 16px;">
                <div>
                    <h2><?= htmlspecialchars(CLINIC_NAME) ?></h2>
                    <p class="muted"><?= htmlspecialchars(CLINIC_ADDRESS) ?> · Tel: <?= htmlspecialchars(CLINIC_PHONE) ?></p>
                </div>
                <div style="text-align:right;">
                    <span class="badge badge-teal">EXPEDIENTE: <?= htmlspecialchars($paciente['folio_expediente'] ?? '') ?></span><br>
                    <strong>Fecha de Emisión:</strong> <?= date('Y-m-d') ?>
                </div>
            </div>

            <div class="grid-2" style="background:#f8fafc; padding:12px 16px; border-radius:10px; margin-bottom:18px;">
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
            <!-- SECCIÓN 1: RESULTADOS DE CHECKUP MÉDICO 360° -->
            <h3 style="margin-top:8px;">🔬 1. Resumen de Checkup Médico Integral 360° (<?= htmlspecialchars($checkup['folio_checkup']) ?>)</h3>
            <div class="grid-4" style="margin-bottom:12px;">
                <div class="kpi-card blue">
                    <div class="kpi-title">Signos Vitales</div>
                    <div class="kpi-value" style="font-size:17px;"><?= (int)$checkup['presion_sistolica'] ?>/<?= (int)$checkup['presion_diastolica'] ?> mmHg</div>
                    <div class="kpi-sub">FC: <?= (int)$checkup['frecuencia_cardiaca'] ?> lpm · SpO2: <?= (int)$checkup['spo2_oxigenacion'] ?>%</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-title">Metabolismo Glucosa / Insulina</div>
                    <div class="kpi-value" style="font-size:17px;">HOMA-IR: <?= $checkup['indice_homa_ir'] ?></div>
                    <div class="kpi-sub">Glucosa: <?= $checkup['glucosa_ayuno_mg'] ?> mg/dL · HbA1c: <?= $checkup['hba1c_pct'] ?>%</div>
                </div>
                <div class="kpi-card amber">
                    <div class="kpi-title">Perfil de Lípidos</div>
                    <div class="kpi-value" style="font-size:17px;">Col: <?= $checkup['colesterol_total_mg'] ?> mg/dL</div>
                    <div class="kpi-sub">Trig: <?= $checkup['trigliceridos_mg'] ?> · HDL: <?= $checkup['hdl_mg'] ?> · LDL: <?= $checkup['ldl_mg'] ?></div>
                </div>
                <div class="kpi-card purple">
                    <div class="kpi-title">Hormonal y Score 360°</div>
                    <div class="kpi-value" style="font-size:17px;">Score: <?= (int)$checkup['score_salud_integral'] ?> / 100</div>
                    <div class="kpi-sub">TSH: <?= $checkup['tsh_tiroides'] ?> · Cortisol: <?= $checkup['cortisol_matutino'] ?> · Vit D: <?= $checkup['vitamina_d_ng'] ?></div>
                </div>
            </div>
            <p style="margin-bottom:16px;">
                <strong>Interpretación del Checkup:</strong> <?= htmlspecialchars($checkup['interpretacion_biomarcadores']) ?><br>
                <strong>Plan de Acción Preventivo:</strong> <?= htmlspecialchars($checkup['plan_accion_checkup']) ?>
            </p>
            <?php endif; ?>

            <?php if ($antro): ?>
            <!-- SECCIÓN 2: ANTROPOMETRÍA ISAK -->
            <h3 style="border-top:1px solid var(--border); padding-top:14px;">📐 2. Composición Corporal y Antropometría ISAK</h3>
            <p style="margin-bottom:14px;">
                <strong>Peso:</strong> <?= (float)$antro['peso_kg'] ?> kg ·
                <strong>Talla:</strong> <?= (float)$antro['talla_cm'] ?> cm ·
                <strong>IMC:</strong> <?= (float)$antro['imc'] ?> kg/m² ·
                <strong>% Grasa:</strong> <?= $antro['porcentaje_grasa'] ?>% ·
                <strong>% Músculo:</strong> <?= $antro['porcentaje_musculo'] ?>% ·
                <strong>Cintura:</strong> <?= $antro['cintura_cm'] ?> cm (ICT: <?= $antro['ict'] ?>) ·
                <strong>Suma 4 Pliegues ISAK:</strong> <?= $antro['suma_4_pliegues_mm'] ?> mm<br>
                <strong>Diagnóstico Nutricional:</strong> <?= htmlspecialchars($antro['diagnostico_nutricional']) ?>
            </p>
            <?php endif; ?>

            <?php if ($plan): ?>
            <!-- SECCIÓN 3: PLAN DE ALIMENTACIÓN SMAE -->
            <h3 style="border-top:1px solid var(--border); padding-top:14px;">🥗 3. <?= htmlspecialchars($plan['nombre_plan']) ?></h3>
            <div class="result-box" style="margin-bottom:12px;">
                Meta Energética: <strong><?= (int)$plan['kcal_objetivo'] ?> kcal/día</strong> ·
                Proteína: <strong><?= (int)$plan['proteina_g'] ?>g</strong> ·
                Carbohidratos: <strong><?= (int)$plan['carbohidratos_g'] ?>g</strong> ·
                Lípidos: <strong><?= (int)$plan['lipidos_g'] ?>g</strong> ·
                Hidratación Meta: <strong><?= (int)$plan['agua_ml'] ?> ml/día</strong>
            </div>
            <table>
                <tbody>
                    <tr><th style="width:160px;">🌅 Desayuno</th><td><?= nl2br(htmlspecialchars($plan['menu_desayuno'])) ?></td></tr>
                    <tr><th>🍎 Colación Matutina</th><td><?= nl2br(htmlspecialchars($plan['menu_colacion_1'])) ?></td></tr>
                    <tr><th>🍽️ Comida Principal</th><td><?= nl2br(htmlspecialchars($plan['menu_comida'])) ?></td></tr>
                    <tr><th>🥜 Colación Vespertina</th><td><?= nl2br(htmlspecialchars($plan['menu_colacion_2'])) ?></td></tr>
                    <tr><th>🌙 Cena Ligera</th><td><?= nl2br(htmlspecialchars($plan['menu_cena'])) ?></td></tr>
                </tbody>
            </table>
            <p style="margin-top:12px;"><strong>Recomendaciones Nutricionales:</strong><br><?= nl2br(htmlspecialchars($plan['recomendaciones_generales'])) ?></p>
            <?php endif; ?>

            <?php if ($psico): ?>
            <!-- SECCIÓN 4: RECOMENDACIONES PSICONUTRICIONALES Y BIENESTAR EMOCIONAL -->
            <h3 style="border-top:1px solid var(--border); padding-top:14px; margin-top:16px;">🧠 4. Seguimiento Psicológico, Higiene del Sueño y Psiconutrición</h3>
            <p>
                <strong>Enfoque Terapéutico:</strong> <?= htmlspecialchars($psico['enfoque_terapeutico']) ?> ·
                <strong>Sueño reportado:</strong> <?= (float)$psico['calidad_sueno_hrs'] ?> hrs/noche<br>
                <strong>Tareas Terapéuticas y Hábitos Conscientes:</strong> <?= nl2br(htmlspecialchars($psico['tareas_terapeuticas'])) ?><br>
                <strong>Coordinación Interdisciplinaria:</strong> <?= nl2br(htmlspecialchars($psico['acuerdos_interdisciplinarios'])) ?>
            </p>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
