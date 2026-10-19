<?php
require_once __DIR__ . '/db_connection.php';
$usuario = requireAuth();
$pdo = getDB();

$pacienteId = (int)($_GET['paciente_id'] ?? 1);
$tabActiva  = $_GET['tab'] ?? 'nutricion';

$pacientesList = $pdo->query("SELECT id, folio_expediente, nombre_completo FROM pacientes ORDER BY id ASC")->fetchAll();
$stmtPac = $pdo->prepare("SELECT p.*, u1.nombre AS nutriologo_nombre, u2.nombre AS psicologo_nombre
                          FROM pacientes p
                          LEFT JOIN usuarios u1 ON p.nutriologo_id = u1.id
                          LEFT JOIN usuarios u2 ON p.psicologo_id = u2.id
                          WHERE p.id = ?");
$stmtPac->execute([$pacienteId]);
$paciente = $stmtPac->fetch();
if (!$paciente && !empty($pacientesList)) {
    $pacienteId = (int)$pacientesList[0]['id'];
    $stmtPac->execute([$pacienteId]);
    $paciente = $stmtPac->fetch();
}

// 1. Guardar Evaluación Nutricional + Antropometría ISAK + Plan SMAE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_consulta_nutricion']) && $paciente && verify_csrf($_POST['csrf_token'] ?? '')) {
    $edad = calcularEdad($paciente['fecha_nacimiento']);
    $peso = (float)$_POST['peso_kg'];
    $talla = (float)$_POST['talla_cm'];
    $imc = $talla > 0 ? round($peso / pow($talla / 100, 2), 2) : 0;
    $pctGrasa = !empty($_POST['porcentaje_grasa']) ? (float)$_POST['porcentaje_grasa'] : null;
    $pctMusc = !empty($_POST['porcentaje_musculo']) ? (float)$_POST['porcentaje_musculo'] : null;
    $masaGrasa = $pctGrasa ? round($peso * ($pctGrasa / 100), 2) : null;
    $masaMagra = $masaGrasa ? round($peso - $masaGrasa, 2) : null;
    $visceral = !empty($_POST['grasa_visceral']) ? (int)$_POST['grasa_visceral'] : null;
    $cintura = !empty($_POST['cintura_cm']) ? (float)$_POST['cintura_cm'] : null;
    $cadera = !empty($_POST['cadera_cm']) ? (float)$_POST['cadera_cm'] : null;
    $icc = ($cintura && $cadera) ? round($cintura / $cadera, 2) : null;
    $ict = ($cintura && $talla) ? round($cintura / $talla, 2) : null;
    $pTri = (float)($_POST['pliegue_tricipital_mm'] ?? 0);
    $pBic = (float)($_POST['pliegue_bicipital_mm'] ?? 0);
    $pSub = (float)($_POST['pliegue_subescapular_mm'] ?? 0);
    $pSup = (float)($_POST['pliegue_suprailiaco_mm'] ?? 0);
    $sumaPliegues = ($pTri + $pBic + $pSub + $pSup) > 0 ? ($pTri + $pBic + $pSub + $pSup) : null;
    $formula = $_POST['formula_usada'] ?? 'Mifflin-St Jeor';
    $energia = calcularGastoEnergetico($peso, $talla, $edad, $paciente['sexo'], $paciente['nivel_actividad_fisica'], $formula, $masaMagra);
    $metaKcal = (int)($_POST['meta_kcal'] ?: $energia['get']);
    $pctP = (int)($_POST['pct_proteina'] ?? 25);
    $pctC = (int)($_POST['pct_carbohidratos'] ?? 45);
    $pctL = (int)($_POST['pct_lipidos'] ?? 30);

    $stmtIns = $pdo->prepare("INSERT INTO consultas_antropometria (paciente_id, nutriologo_id, fecha_consulta, peso_kg, talla_cm, imc, porcentaje_grasa, porcentaje_musculo, masa_grasa_kg, masa_magra_kg, grasa_visceral, cintura_cm, cadera_cm, icc, ict, pliegue_tricipital_mm, pliegue_bicipital_mm, pliegue_subescapular_mm, pliegue_suprailiaco_mm, suma_4_pliegues_mm, formula_usada, tmb_kcal, get_kcal, meta_kcal, pct_proteina, pct_carbohidratos, pct_lipidos, diagnostico_nutricional) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmtIns->execute([$pacienteId, $usuario['id'], date('Y-m-d'), $peso, $talla, $imc, $pctGrasa, $pctMusc, $masaGrasa, $masaMagra, $visceral, $cintura, $cadera, $icc, $ict, $pTri ?: null, $pBic ?: null, $pSub ?: null, $pSup ?: null, $sumaPliegues, $formula, $energia['tmb'], $energia['get'], $metaKcal, $pctP, $pctC, $pctL, trim($_POST['diagnostico_nutricional'] ?? '')]);

    // Si se llenó el Plan de Alimentación SMAE, guardarlo también
    if (!empty($_POST['nombre_plan']) && !empty($_POST['menu_desayuno'])) {
        $protG = (int)round(($metaKcal * ($pctP / 100)) / 4);
        $carbG = (int)round(($metaKcal * ($pctC / 100)) / 4);
        $lipG  = (int)round(($metaKcal * ($pctL / 100)) / 9);
        $aguaMl = (int)round($peso * 35);

        $stmtPlan = $pdo->prepare("INSERT INTO planes_alimentacion (paciente_id, nutriologo_id, nombre_plan, kcal_objetivo, proteina_g, carbohidratos_g, lipidos_g, agua_ml, eq_verduras, eq_frutas, eq_cereales_sg, eq_leguminosas, eq_aoa_bajo, eq_leche_semi, eq_grasas_sp, eq_grasas_cp, menu_desayuno, menu_colacion_1, menu_comida, menu_colacion_2, menu_cena, recomendaciones_generales) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmtPlan->execute([
            $pacienteId, $usuario['id'], trim($_POST['nombre_plan']), $metaKcal, $protG, $carbG, $lipG, $aguaMl,
            (float)($_POST['eq_verduras'] ?? 5), (float)($_POST['eq_frutas'] ?? 3), (float)($_POST['eq_cereales_sg'] ?? 5),
            (float)($_POST['eq_leguminosas'] ?? 1), (float)($_POST['eq_aoa_bajo'] ?? 6), (float)($_POST['eq_leche_semi'] ?? 1),
            (float)($_POST['eq_grasas_sp'] ?? 4), (float)($_POST['eq_grasas_cp'] ?? 2),
            trim($_POST['menu_desayuno']), trim($_POST['menu_colacion_1'] ?? ''), trim($_POST['menu_comida'] ?? ''),
            trim($_POST['menu_colacion_2'] ?? ''), trim($_POST['menu_cena'] ?? ''), trim($_POST['recomendaciones_generales'] ?? '')
        ]);
    }

    header("Location: consulta.php?paciente_id={$pacienteId}&tab=nutricion&guardado=nutricion");
    exit;
}

// 2. Guardar Sesión de Psicología Clínica / Psiconutrición (SOAP + PHQ-9 / GAD-7 / BES)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_consulta_psicologia']) && $paciente && verify_csrf($_POST['csrf_token'] ?? '')) {
    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM consultas_psicologia WHERE paciente_id = ?");
    $stmtCount->execute([$pacienteId]);
    $numSesion = (int)$stmtCount->fetchColumn() + 1;

    $stmtPsi = $pdo->prepare("INSERT INTO consultas_psicologia (paciente_id, psicologo_id, fecha_sesion, numero_sesion, modalidad_sesion, enfoque_terapeutico, phq9_depresion, gad7_ansiedad, bes_atracon, nivel_estres, calidad_sueno_hrs, riesgo_clinico, estado_mental_examen, notas_soap, conducta_alimentaria_emocional, tareas_terapeuticas, acuerdos_interdisciplinarios) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmtPsi->execute([
        $pacienteId,
        $usuario['id'],
        date('Y-m-d'),
        $numSesion,
        $_POST['modalidad_sesion'] ?? 'psicoterapia_individual',
        trim($_POST['enfoque_terapeutico'] ?? 'Cognitivo-Conductual (TCC)'),
        (int)($_POST['phq9_depresion'] ?? 0),
        (int)($_POST['gad7_ansiedad'] ?? 0),
        (int)($_POST['bes_atracon'] ?? 0),
        (int)($_POST['nivel_estres'] ?? 5),
        (float)($_POST['calidad_sueno_hrs'] ?? 7.0),
        $_POST['riesgo_clinico'] ?? 'bajo',
        trim($_POST['estado_mental_examen'] ?? ''),
        trim($_POST['notas_soap'] ?? ''),
        trim($_POST['conducta_alimentaria_emocional'] ?? ''),
        trim($_POST['tareas_terapeuticas'] ?? ''),
        trim($_POST['acuerdos_interdisciplinarios'] ?? '')
    ]);

    header("Location: consulta.php?paciente_id={$pacienteId}&tab=psicologia&guardado=psicologia");
    exit;
}

// 3. Guardar Checkup Médico Integral 360° (Signos Vitales + Biomarcadores + HOMA-IR)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_checkup_clinico']) && $paciente && verify_csrf($_POST['csrf_token'] ?? '')) {
    $folioChk = 'CHK-' . date('Y') . '-' . rand(100, 999);
    $glucosa  = !empty($_POST['glucosa_ayuno_mg']) ? (float)$_POST['glucosa_ayuno_mg'] : null;
    $insulina = !empty($_POST['insulina_basal_uui']) ? (float)$_POST['insulina_basal_uui'] : null;
    $homaIr   = calcularHomaIR($glucosa, $insulina);
    $trig     = !empty($_POST['trigliceridos_mg']) ? (float)$_POST['trigliceridos_mg'] : null;
    $cortisol = !empty($_POST['cortisol_matutino']) ? (float)$_POST['cortisol_matutino'] : null;

    $semMet = ($homaIr !== null && $homaIr >= 2.9) || ($glucosa !== null && $glucosa >= 110) ? 'alerta' : (($homaIr !== null && $homaIr >= 2.0) || ($trig !== null && $trig >= 150) ? 'precaucion' : 'optimo');
    $semPsi = ($cortisol !== null && $cortisol >= 22) ? 'alerta' : (($cortisol !== null && $cortisol >= 18) ? 'precaucion' : 'optimo');
    $score  = (int)($_POST['score_salud_integral'] ?? ($semMet === 'optimo' ? 90 : ($semMet === 'precaucion' ? 82 : 70)));

    $stmtChk = $pdo->prepare("INSERT INTO checkups_clinicos (folio_checkup, paciente_id, especialista_id, fecha_checkup, tipo_checkup, presion_sistolica, presion_diastolica, frecuencia_cardiaca, temperatura_c, spo2_oxigenacion, glucosa_ayuno_mg, insulina_basal_uui, indice_homa_ir, hba1c_pct, colesterol_total_mg, trigliceridos_mg, hdl_mg, ldl_mg, tsh_tiroides, cortisol_matutino, vitamina_d_ng, semaforo_metabolico, semaforo_psicoemocional, score_salud_integral, interpretacion_biomarcadores, plan_accion_checkup) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmtChk->execute([
        $folioChk, $pacienteId, $usuario['id'], date('Y-m-d'),
        $_POST['tipo_checkup'] ?? 'Checkup Integral Mente-Cuerpo 360°',
        (int)($_POST['presion_sistolica'] ?? 118),
        (int)($_POST['presion_diastolica'] ?? 76),
        (int)($_POST['frecuencia_cardiaca'] ?? 68),
        (float)($_POST['temperatura_c'] ?? 36.5),
        (int)($_POST['spo2_oxigenacion'] ?? 98),
        $glucosa, $insulina, $homaIr,
        !empty($_POST['hba1c_pct']) ? (float)$_POST['hba1c_pct'] : null,
        !empty($_POST['colesterol_total_mg']) ? (float)$_POST['colesterol_total_mg'] : null,
        $trig,
        !empty($_POST['hdl_mg']) ? (float)$_POST['hdl_mg'] : null,
        !empty($_POST['ldl_mg']) ? (float)$_POST['ldl_mg'] : null,
        !empty($_POST['tsh_tiroides']) ? (float)$_POST['tsh_tiroides'] : null,
        $cortisol,
        !empty($_POST['vitamina_d_ng']) ? (float)$_POST['vitamina_d_ng'] : null,
        $semMet, $semPsi, $score,
        trim($_POST['interpretacion_biomarcadores'] ?? ''),
        trim($_POST['plan_accion_checkup'] ?? '')
    ]);

    header("Location: consulta.php?paciente_id={$pacienteId}&tab=checkup&guardado=checkup");
    exit;
}

// Consultar Historial Clínico Integral del Paciente
$stmtHistNut = $pdo->prepare("SELECT ca.*, u.nombre AS autor FROM consultas_antropometria ca JOIN usuarios u ON ca.nutriologo_id = u.id WHERE ca.paciente_id = ? ORDER BY ca.fecha_consulta DESC, ca.id DESC");
$stmtHistNut->execute([$pacienteId]);
$historialNutricion = $stmtHistNut->fetchAll();
$ultimaNut = $historialNutricion[0] ?? null;

$stmtPlanAct = $pdo->prepare("SELECT * FROM planes_alimentacion WHERE paciente_id = ? ORDER BY id DESC LIMIT 1");
$stmtPlanAct->execute([$pacienteId]);
$planActual = $stmtPlanAct->fetch();

$stmtHistPsi = $pdo->prepare("SELECT cp.*, u.nombre AS autor FROM consultas_psicologia cp JOIN usuarios u ON cp.psicologo_id = u.id WHERE cp.paciente_id = ? ORDER BY cp.fecha_sesion DESC, cp.id DESC");
$stmtHistPsi->execute([$pacienteId]);
$historialPsicologia = $stmtHistPsi->fetchAll();
$ultimaPsi = $historialPsicologia[0] ?? null;

$stmtHistChk = $pdo->prepare("SELECT ck.*, u.nombre AS autor FROM checkups_clinicos ck JOIN usuarios u ON ck.especialista_id = u.id WHERE ck.paciente_id = ? ORDER BY ck.fecha_checkup DESC, ck.id DESC");
$stmtHistChk->execute([$pacienteId]);
$historialCheckups = $stmtHistChk->fetchAll();
$ultimoChk = $historialCheckups[0] ?? null;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Estación Clínica: Nutrición, Psicología y Checkups | <?= htmlspecialchars(CLINIC_NAME) ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <?php renderNavbar('consulta'); ?>

    <main class="container section-pad">
        <?php if (isset($_GET['guardado'])): ?>
            <div class="result-box">
                ✅ Evaluación de <strong><?= strtoupper(htmlspecialchars($_GET['guardado'])) ?></strong> guardada exitosamente en el expediente clínico electrónico.
                <a href="imprimir_plan.php?paciente_id=<?= $pacienteId ?>" class="btn btn-primary btn-sm" style="margin-left:10px;">🖨️ Ver Reporte Integral Imprimible / PDF</a>
            </div>
        <?php endif; ?>

        <!-- CABECERA DEL EXPEDIENTE CLÍNICO INTERDISCIPLINARIO -->
        <div class="card" style="background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); color:#fff;">
            <div class="flex-between">
                <div>
                    <span class="badge badge-teal"><?= htmlspecialchars($paciente['folio_expediente'] ?? 'EXP') ?></span>
                    <span class="badge badge-purple" style="margin-left:6px;"><?= strtoupper(str_replace('_', ' ', htmlspecialchars($paciente['modalidad_atencion'] ?? ''))) ?></span>
                    <h2 style="color:#fff; margin-top:6px;"><?= htmlspecialchars($paciente['nombre_completo'] ?? '') ?> (<?= calcularEdad($paciente['fecha_nacimiento'] ?? null) ?> años)</h2>
                    <p style="color:#cbd5e1; font-size:12.5px;">
                        🥗 <strong>Meta Nutricional:</strong> <?= htmlspecialchars($paciente['objetivo_principal'] ?? '') ?><br>
                        🧠 <strong>Enfoque Psicológico:</strong> <?= htmlspecialchars($paciente['motivo_psicologico'] ?: 'Seguimiento de bienestar emocional y adherencia') ?><br>
                        💊 <strong>Alergias / Médicos:</strong> <?= htmlspecialchars(($paciente['alergias_intolerancias'] ?: 'Sin alergias') . ' · ' . ($paciente['medicamentos_suplementos'] ?: 'Sin fármacos')) ?>
                    </p>
                </div>
                <div style="display:flex; flex-direction:column; gap:8px; align-items:flex-end;">
                    <form method="GET" style="display:flex; gap:6px; align-items:center;">
                        <input type="hidden" name="tab" value="<?= htmlspecialchars($tabActiva) ?>">
                        <select name="paciente_id" onchange="this.form.submit()" style="background:#334155; color:#fff; border-color:#475569; margin:0;">
                            <?php foreach ($pacientesList as $pl): ?>
                                <option value="<?= (int)$pl['id'] ?>" <?= $pacienteId === (int)$pl['id'] ? 'selected' : '' ?>>
                                    📂 <?= htmlspecialchars($pl['folio_expediente'] . ' — ' . $pl['nombre_completo']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                    <div style="display:flex; gap:8px;">
                        <a href="imprimir_plan.php?paciente_id=<?= $pacienteId ?>" class="btn btn-primary btn-sm">🖨️ Imprimir Plan + Checkup 360°</a>
                        <a href="recepcion.php?fecha=<?= date('Y-m-d') ?>" class="btn btn-outline btn-sm" style="background:rgba(255,255,255,0.1);color:#fff;border-color:rgba(255,255,255,0.2);">💳 Enviar a Caja POS</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- KPIS CLÍNICOS UNIFICADOS DEL PACIENTE -->
        <div class="grid-4" style="margin-bottom:18px;">
            <div class="kpi-card">
                <div class="kpi-title">Composición Corporal (ISAK)</div>
                <div class="kpi-value"><?= $ultimaNut ? $ultimaNut['peso_kg'] . ' kg · IMC ' . $ultimaNut['imc'] : 'Sin registro' ?></div>
                <div class="kpi-sub">
                    Grasa: <?= $ultimaNut['porcentaje_grasa'] ?? '—' ?>% · Músculo: <?= $ultimaNut['porcentaje_musculo'] ?? '—' ?>% · 4 Pliegues: <?= $ultimaNut['suma_4_pliegues_mm'] ?? '—' ?> mm
                </div>
            </div>
            <div class="kpi-card blue">
                <div class="kpi-title">Último Checkup 360° (HOMA-IR)</div>
                <div class="kpi-value">
                    <?= $ultimoChk && $ultimoChk['indice_homa_ir'] ? 'HOMA-IR: ' . $ultimoChk['indice_homa_ir'] : 'Score: ' . ($ultimoChk['score_salud_integral'] ?? '—') ?>
                </div>
                <div class="kpi-sub">
                    PA: <?= $ultimoChk ? $ultimoChk['presion_sistolica'] . '/' . $ultimoChk['presion_diastolica'] : '—' ?> mmHg · Glucosa: <?= $ultimoChk['glucosa_ayuno_mg'] ?? '—' ?> mg/dL
                </div>
            </div>
            <div class="kpi-card purple">
                <div class="kpi-title">Tamizaje Psicoemocional</div>
                <div class="kpi-value">
                    GAD-7: <?= $ultimaPsi['gad7_ansiedad'] ?? '—' ?> · BES: <?= $ultimaPsi['bes_atracon'] ?? '—' ?>
                </div>
                <div class="kpi-sub">
                    PHQ-9: <?= $ultimaPsi['phq9_depresion'] ?? '—' ?> · Sueño: <?= $ultimaPsi['calidad_sueno_hrs'] ?? '—' ?> hrs/noche
                </div>
            </div>
            <div class="kpi-card amber">
                <div class="kpi-title">Sesiones / Checkups en Paquete</div>
                <div class="kpi-value">
                    🥗 <?= (int)($paciente['sesiones_disponibles_nutri'] ?? 0) ?> · 🧠 <?= (int)($paciente['sesiones_disponibles_psico'] ?? 0) ?> · 🔬 <?= (int)($paciente['checkups_disponibles'] ?? 0) ?>
                </div>
                <div class="kpi-sub"><?= htmlspecialchars($paciente['nombre_paquete_activo'] ?? 'Pago por consulta') ?></div>
            </div>
        </div>

        <!-- PESTAÑAS DE LAS 3 ESPECIALIDADES CLÍNICAS -->
        <div class="tabs-bar">
            <a href="consulta.php?paciente_id=<?= $pacienteId ?>&tab=nutricion" class="tab-btn <?= $tabActiva === 'nutricion' ? 'active' : '' ?>">
                🥗 1. Nutrición, Antropometría ISAK y Plan SMAE
            </a>
            <a href="consulta.php?paciente_id=<?= $pacienteId ?>&tab=psicologia" class="tab-btn <?= $tabActiva === 'psicologia' ? 'active' : '' ?>">
                🧠 2. Psicología Clínica, TCC y Psiconutrición (SOAP + Escalas)
            </a>
            <a href="consulta.php?paciente_id=<?= $pacienteId ?>&tab=checkup" class="tab-btn <?= $tabActiva === 'checkup' ? 'active' : '' ?>">
                🔬 3. Checkup Médico 360°, Signos Vitales y Biomarcadores
            </a>
        </div>

        <?php if ($tabActiva === 'nutricion'): ?>
        <!-- PESTAÑA 1: NUTRICIÓN, ISAK Y PLAN SMAE -->
        <div class="card">
            <h3>📐 Evaluación Antropométrica ISAK (4 Pliegues), Gasto Energético y Prescripción SMAE</h3>
            <form method="POST" class="grid-3">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="guardar_consulta_nutricion" value="1">

                <label>Peso Actual (kg) * <input type="number" name="peso_kg" step="0.1" value="<?= $ultimaNut['peso_kg'] ?? 70.0 ?>" required></label>
                <label>Estatura (cm) * <input type="number" name="talla_cm" step="0.5" value="<?= $ultimaNut['talla_cm'] ?? 165.0 ?>" required></label>
                <label>% Grasa Corporal (InBody / Bioimpedancia) <input type="number" name="porcentaje_grasa" step="0.1" value="<?= $ultimaNut['porcentaje_grasa'] ?? 28.5 ?>"></label>
                <label>% Masa Muscular Esquelética <input type="number" name="porcentaje_musculo" step="0.1" value="<?= $ultimaNut['porcentaje_musculo'] ?? 31.0 ?>"></label>
                <label>Nivel de Grasa Visceral <input type="number" name="grasa_visceral" value="<?= $ultimaNut['grasa_visceral'] ?? 7 ?>"></label>
                <label>Circunferencia Cintura (cm) <input type="number" name="cintura_cm" step="0.5" value="<?= $ultimaNut['cintura_cm'] ?? 82.0 ?>"></label>
                <label>Circunferencia Cadera (cm) <input type="number" name="cadera_cm" step="0.5" value="<?= $ultimaNut['cadera_cm'] ?? 100.0 ?>"></label>
                <label>Pliegue Tricipital ISAK (mm) <input type="number" name="pliegue_tricipital_mm" step="0.5" value="<?= $ultimaNut['pliegue_tricipital_mm'] ?? 16.0 ?>"></label>
                <label>Pliegue Bicipital ISAK (mm) <input type="number" name="pliegue_bicipital_mm" step="0.5" value="<?= $ultimaNut['pliegue_bicipital_mm'] ?? 9.0 ?>"></label>
                <label>Pliegue Subescapular ISAK (mm) <input type="number" name="pliegue_subescapular_mm" step="0.5" value="<?= $ultimaNut['pliegue_subescapular_mm'] ?? 15.0 ?>"></label>
                <label>Pliegue Suprailíaco ISAK (mm) <input type="number" name="pliegue_suprailiaco_mm" step="0.5" value="<?= $ultimaNut['pliegue_suprailiaco_mm'] ?? 18.0 ?>"></label>
                <label>Fórmula de Gasto Energético
                    <select name="formula_usada">
                        <option value="Mifflin-St Jeor">Mifflin-St Jeor (Clínica / Metabólica)</option>
                        <option value="Katch-McArdle">Katch-McArdle (Deportiva por Masa Magra)</option>
                        <option value="Harris-Benedict">Harris-Benedict (Clásica)</option>
                    </select>
                </label>
                <label>Meta Calórica Prescrita (kcal/día) * <input type="number" name="meta_kcal" value="<?= $ultimaNut['meta_kcal'] ?? 1800 ?>" required></label>
                <label>% Proteína <input type="number" name="pct_proteina" value="<?= $ultimaNut['pct_proteina'] ?? 25 ?>"></label>
                <label>% Carbohidratos <input type="number" name="pct_carbohidratos" value="<?= $ultimaNut['pct_carbohidratos'] ?? 45 ?>"></label>
                <label>% Lípidos <input type="number" name="pct_lipidos" value="<?= $ultimaNut['pct_lipidos'] ?? 30 ?>"></label>
                <label style="grid-column: span 2;">Diagnóstico Nutricional Estructurado (Formato PES)
                    <input type="text" name="diagnostico_nutricional" value="<?= htmlspecialchars($ultimaNut['diagnostico_nutricional'] ?? '') ?>" placeholder="Problema + Etiología + Signos/Síntomas">
                </label>

                <div style="grid-column: 1 / -1; border-top: 1px solid var(--border); padding-top: 14px; margin-top: 6px;">
                    <h4>🥗 Plan de Alimentación por Equivalentes SMAE y Menú Entregable</h4>
                </div>
                <label style="grid-column: span 3;">Nombre del Plan Nutricional
                    <input type="text" name="nombre_plan" value="<?= htmlspecialchars($planActual['nombre_plan'] ?? 'Plan Integral de Recomposición Corporal y Saciedad Consciente') ?>">
                </label>
                <label>Eq. Verduras <input type="number" step="0.5" name="eq_verduras" value="<?= $planActual['eq_verduras'] ?? 5 ?>"></label>
                <label>Eq. Frutas <input type="number" step="0.5" name="eq_frutas" value="<?= $planActual['eq_frutas'] ?? 3 ?>"></label>
                <label>Eq. Cereales s/g <input type="number" step="0.5" name="eq_cereales_sg" value="<?= $planActual['eq_cereales_sg'] ?? 5 ?>"></label>
                <label>Eq. Leguminosas <input type="number" step="0.5" name="eq_leguminosas" value="<?= $planActual['eq_leguminosas'] ?? 1 ?>"></label>
                <label>Eq. AOA (Proteína) <input type="number" step="0.5" name="eq_aoa_bajo" value="<?= $planActual['eq_aoa_bajo'] ?? 6 ?>"></label>
                <label>Eq. Grasas Saludables <input type="number" step="0.5" name="eq_grasas_sp" value="<?= $planActual['eq_grasas_sp'] ?? 4 ?>"></label>

                <label>Menú Desayuno *
                    <textarea name="menu_desayuno" rows="2"><?= htmlspecialchars($planActual['menu_desayuno'] ?? 'Omelette de 2 huevos + claras con espinacas, 1 reb pan masa madre y 1/3 aguacate.') ?></textarea>
                </label>
                <label>Colación Matutina
                    <textarea name="menu_colacion_1" rows="2"><?= htmlspecialchars($planActual['menu_colacion_1'] ?? 'Yogur griego natural sin azúcar con 10 almendras y frutos rojos.') ?></textarea>
                </label>
                <label>Menú Comida *
                    <textarea name="menu_comida" rows="2"><?= htmlspecialchars($planActual['menu_comida'] ?? '150g pechuga o salmón a la plancha + 2/3 taza arroz o quinoa + ensalada verde.') ?></textarea>
                </label>
                <label>Colación Vespertina (Estratégica Anti-Ansiedad)
                    <textarea name="menu_colacion_2" rows="2"><?= htmlspecialchars($planActual['menu_colacion_2'] ?? '1 manzana en gajos + 1 cda crema de almendras natural.') ?></textarea>
                </label>
                <label>Menú Cena *
                    <textarea name="menu_cena" rows="2"><?= htmlspecialchars($planActual['menu_cena'] ?? '2 tostadas horneadas con 120g de atún o pollo, nopales y aguacate.') ?></textarea>
                </label>
                <label>Recomendaciones Clínicas e Hidratación
                    <textarea name="recomendaciones_generales" rows="2"><?= htmlspecialchars($planActual['recomendaciones_generales'] ?? 'Hidratación 2.5L/día. Practicar escala de hambre-saciedad.') ?></textarea>
                </label>

                <div style="grid-column: 1 / -1;">
                    <button type="submit" class="btn btn-primary" style="width:100%; padding:11px;">💾 Guardar Evaluación Antropométrica ISAK y Actualizar Plan SMAE</button>
                </div>
            </form>
        </div>

        <?php elseif ($tabActiva === 'psicologia'): ?>
        <!-- PESTAÑA 2: PSICOLOGÍA CLÍNICA Y PSICONUTRICIÓN -->
        <div class="card">
            <h3>🧠 Nota Clínica de Psicología y Psiconutrición (Formato SOAP + Tamizaje PHQ-9, GAD-7 y BES)</h3>
            <form method="POST" class="grid-3">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="guardar_consulta_psicologia" value="1">

                <label>Modalidad de Sesión *
                    <select name="modalidad_sesion">
                        <option value="psicoterapia_individual">🧠 Psicoterapia Individual (TCC / Regulación Emocional)</option>
                        <option value="psiconutricion_tca">🤝 Psiconutrición / Conducta Alimentaria (TCA / Atracón)</option>
                        <option value="evaluacion_inicial">📋 Evaluación Psicológica Inicial</option>
                        <option value="intervencion_crisis">🚨 Intervención en Crisis / Contención</option>
                    </select>
                </label>
                <label>Enfoque Terapéutico *
                    <input type="text" name="enfoque_terapeutico" value="Terapia Cognitivo-Conductual (TCC) + ACT / Mindful Eating" required>
                </label>
                <label>Nivel de Riesgo Clínico
                    <select name="riesgo_clinico">
                        <option value="bajo">🟢 Riesgo Bajo / Estable</option>
                        <option value="moderado">🟡 Riesgo Moderado (Vigilancia)</option>
                        <option value="alto">🔴 Riesgo Alto (Protocolo Prioritario)</option>
                    </select>
                </label>

                <label>Escala PHQ-9 (Depresión: 0 a 27 pts)
                    <input type="number" name="phq9_depresion" min="0" max="27" value="<?= $ultimaPsi['phq9_depresion'] ?? 5 ?>">
                </label>
                <label>Escala GAD-7 (Ansiedad: 0 a 21 pts)
                    <input type="number" name="gad7_ansiedad" min="0" max="21" value="<?= $ultimaPsi['gad7_ansiedad'] ?? 8 ?>">
                </label>
                <label>Escala BES (Atracón / Hambre Emocional: 0 a 46 pts)
                    <input type="number" name="bes_atracon" min="0" max="46" value="<?= $ultimaPsi['bes_atracon'] ?? 12 ?>">
                </label>

                <label>Nivel de Estrés Percibido (1 a 10)
                    <input type="number" name="nivel_estres" min="1" max="10" value="<?= $ultimaPsi['nivel_estres'] ?? 5 ?>">
                </label>
                <label>Calidad / Horas de Sueño Promedio (hrs)
                    <input type="number" step="0.5" name="calidad_sueno_hrs" value="<?= $ultimaPsi['calidad_sueno_hrs'] ?? 7.0 ?>">
                </label>
                <label>Examen del Estado Mental (EEM)
                    <input type="text" name="estado_mental_examen" value="<?= htmlspecialchars($ultimaPsi['estado_mental_examen'] ?? 'Orientado en 3 esferas, eutímico, discurso coherente, insight conservado.') ?>">
                </label>

                <label style="grid-column: span 3;">Nota Clínica Estructurada SOAP (Subjetivo, Objetivo, Análisis, Plan) *
                    <textarea name="notas_soap" rows="4" required placeholder="S: Motivo reportado en sesión... O: Observaciones conductuales y escalas... A: Formulación cognitivo-conductual... P: Intervención realizada..."><?= htmlspecialchars($ultimaPsi['notas_soap'] ?? '') ?></textarea>
                </label>

                <label>Relación con la Comida / Conducta Alimentaria (Psiconutrición)
                    <textarea name="conducta_alimentaria_emocional" rows="2" placeholder="Detonantes de hambre emocional, culpa post-ingesta, restricción..."><?= htmlspecialchars($ultimaPsi['conducta_alimentaria_emocional'] ?? '') ?></textarea>
                </label>
                <label>Tareas Terapéuticas entre Sesiones
                    <textarea name="tareas_terapeuticas" rows="2" placeholder="Ej. Autorregistro de pensamientos automáticos, respiración 4-7-8..."><?= htmlspecialchars($ultimaPsi['tareas_terapeuticas'] ?? '') ?></textarea>
                </label>
                <label>Acuerdos Interdisciplinarios con Nutrición
                    <textarea name="acuerdos_interdisciplinarios" rows="2" placeholder="Indicaciones compartidas con el nutriólogo titular..."><?= htmlspecialchars($ultimaPsi['acuerdos_interdisciplinarios'] ?? '') ?></textarea>
                </label>

                <div style="grid-column: 1 / -1;">
                    <button type="submit" class="btn btn-purple" style="width:100%; padding:11px;">💾 Guardar Sesión Psicológica / Psiconutricional en Expediente</button>
                </div>
            </form>
        </div>

        <?php else: ?>
        <!-- PESTAÑA 3: CHECKUPS CLÍNICOS 360°, SIGNOS VITALES Y BIOMARCADORES -->
        <div class="card">
            <h3>🔬 Checkup Médico Integral 360° — Signos Vitales, Panel Cardiometabólico, HOMA-IR y Cortisol</h3>
            <p class="muted" style="margin-bottom:14px;">El índice HOMA-IR y el semáforo de riesgo cardiometabólico/psicoemocional se calculan de forma autónoma al ingresar Glucosa e Insulina basal.</p>
            <form method="POST" class="grid-4">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="guardar_checkup_clinico" value="1">

                <label style="grid-column: span 2;">Tipo de Checkup Realizado *
                    <select name="tipo_checkup">
                        <option value="Checkup Integral Mente-Cuerpo 360°">🔬 Checkup Integral Mente-Cuerpo 360° (Metabólico + Antropometría + Estrés)</option>
                        <option value="Checkup Cardiometabólico y Resistencia a la Insulina">❤️ Checkup Cardiometabólico y Resistencia a la Insulina (HOMA-IR + Lípidos)</option>
                        <option value="Checkup Deportivo de Alto Rendimiento + Plicometría ISAK II">⚡ Checkup Deportivo de Alto Rendimiento + Plicometría ISAK II</option>
                        <option value="Checkup Hormonal, Tiroides y Cortisol">🦋 Checkup Hormonal, Tiroides y Fatiga Adrenal</option>
                    </select>
                </label>
                <label>Presión Sistólica (mmHg)
                    <input type="number" name="presion_sistolica" value="<?= $ultimoChk['presion_sistolica'] ?? 116 ?>">
                </label>
                <label>Presión Diastólica (mmHg)
                    <input type="number" name="presion_diastolica" value="<?= $ultimoChk['presion_diastolica'] ?? 74 ?>">
                </label>

                <label>Frecuencia Cardíaca (lpm)
                    <input type="number" name="frecuencia_cardiaca" value="<?= $ultimoChk['frecuencia_cardiaca'] ?? 66 ?>">
                </label>
                <label>Temperatura (°C)
                    <input type="number" step="0.1" name="temperatura_c" value="<?= $ultimoChk['temperatura_c'] ?? 36.4 ?>">
                </label>
                <label>Oxigenación SpO2 (%)
                    <input type="number" name="spo2_oxigenacion" value="<?= $ultimoChk['spo2_oxigenacion'] ?? 98 ?>">
                </label>
                <label>Score de Salud Integral (0-100)
                    <input type="number" name="score_salud_integral" min="1" max="100" value="<?= $ultimoChk['score_salud_integral'] ?? 86 ?>">
                </label>

                <label>Glucosa en Ayuno (mg/dL) *
                    <input type="number" step="0.1" name="glucosa_ayuno_mg" id="chk_glucosa" value="<?= $ultimoChk['glucosa_ayuno_mg'] ?? 92.0 ?>" oninput="calcHomaPreview()" required>
                </label>
                <label>Insulina Basal (μUI/mL) *
                    <input type="number" step="0.1" name="insulina_basal_uui" id="chk_insulina" value="<?= $ultimoChk['insulina_basal_uui'] ?? 9.5 ?>" oninput="calcHomaPreview()" required>
                </label>
                <label>Hemoglobina Glucosilada HbA1c (%)
                    <input type="number" step="0.1" name="hba1c_pct" value="<?= $ultimoChk['hba1c_pct'] ?? 5.3 ?>">
                </label>
                <label>HOMA-IR Calculado <small class="muted">(Gluc×Ins/405)</small>
                    <input type="text" id="chk_homa_preview" value="<?= $ultimoChk['indice_homa_ir'] ?? 2.16 ?>" readonly style="background:#f0fdfa; font-weight:700; color:#0f766e;">
                </label>

                <label>Colesterol Total (mg/dL)
                    <input type="number" step="0.1" name="colesterol_total_mg" value="<?= $ultimoChk['colesterol_total_mg'] ?? 185.0 ?>">
                </label>
                <label>Triglicéridos (mg/dL)
                    <input type="number" step="0.1" name="trigliceridos_mg" value="<?= $ultimoChk['trigliceridos_mg'] ?? 125.0 ?>">
                </label>
                <label>Colesterol HDL (mg/dL)
                    <input type="number" step="0.1" name="hdl_mg" value="<?= $ultimoChk['hdl_mg'] ?? 55.0 ?>">
                </label>
                <label>Colesterol LDL (mg/dL)
                    <input type="number" step="0.1" name="ldl_mg" value="<?= $ultimoChk['ldl_mg'] ?? 105.0 ?>">
                </label>

                <label>TSH Tiroides (mUI/L)
                    <input type="number" step="0.01" name="tsh_tiroides" value="<?= $ultimoChk['tsh_tiroides'] ?? 2.10 ?>">
                </label>
                <label>Cortisol Matutino (μg/dL) <small class="muted">(Estrés)</small>
                    <input type="number" step="0.1" name="cortisol_matutino" value="<?= $ultimoChk['cortisol_matutino'] ?? 14.5 ?>">
                </label>
                <label style="grid-column: span 2;">Vitamina D 25-OH (ng/mL)
                    <input type="number" step="0.1" name="vitamina_d_ng" value="<?= $ultimoChk['vitamina_d_ng'] ?? 34.0 ?>">
                </label>

                <label style="grid-column: span 2;">Interpretación Clínica de Biomarcadores y Signos Vitales *
                    <textarea name="interpretacion_biomarcadores" rows="3" required><?= htmlspecialchars($ultimoChk['interpretacion_biomarcadores'] ?? 'Sensibilidad a la insulina en rango favorable, adecuada respuesta lipídica y signos vitales normotensos.') ?></textarea>
                </label>
                <label style="grid-column: span 2;">Plan de Acción Preventivo y Suplementación del Checkup *
                    <textarea name="plan_accion_checkup" rows="3" required><?= htmlspecialchars($ultimoChk['plan_accion_checkup'] ?? 'Continuar plan antiinflamatorio SMAE, suplementación dirigida y control de biomarcadores en 12 semanas.') ?></textarea>
                </label>

                <div style="grid-column: 1 / -1;">
                    <button type="submit" class="btn btn-blue" style="width:100%; padding:11px;">🔬 Guardar Checkup Integral 360° y Actualizar Semáforo Clínico</button>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <!-- HISTORIAL EVOLUTIVO UNIFICADO DEL PACIENTE -->
        <div class="grid-3">
            <div class="card">
                <h3>🔬 Historial de Checkups 360°</h3>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr><th>Fecha / Folio</th><th>PA / Signos</th><th>Gluc / HOMA-IR</th><th>Score</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($historialCheckups as $hc):
                                $homaInfo = interpretarHomaIR($hc['indice_homa_ir'] !== null ? (float)$hc['indice_homa_ir'] : null);
                            ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($hc['fecha_checkup']) ?></strong><br>
                                    <small class="muted"><?= htmlspecialchars($hc['folio_checkup']) ?></small>
                                </td>
                                <td><?= (int)$hc['presion_sistolica'] ?>/<?= (int)$hc['presion_diastolica'] ?> mmHg<br><small class="muted"><?= (int)$hc['frecuencia_cardiaca'] ?> lpm</small></td>
                                <td>
                                    <?= (float)$hc['glucosa_ayuno_mg'] ?> mg/dL<br>
                                    <span class="badge <?= $homaInfo['badge'] ?>">HOMA: <?= $hc['indice_homa_ir'] ?></span>
                                </td>
                                <td><strong><?= (int)$hc['score_salud_integral'] ?>/100</strong></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card">
                <h3>🥗 Historial Antropométrico ISAK</h3>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr><th>Fecha</th><th>Peso / IMC</th><th>% Grasa / Músc</th><th>4 Pliegues / Meta</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($historialNutricion as $hn): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($hn['fecha_consulta']) ?></strong></td>
                                <td><strong><?= (float)$hn['peso_kg'] ?> kg</strong><br><small class="muted">IMC: <?= (float)$hn['imc'] ?></small></td>
                                <td>G: <?= $hn['porcentaje_grasa'] ?>%<br>M: <?= $hn['porcentaje_musculo'] ?>%</td>
                                <td><?= $hn['suma_4_pliegues_mm'] ?: '—' ?> mm<br><span class="badge badge-teal"><?= (int)$hn['meta_kcal'] ?> kcal</span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card">
                <h3>🧠 Historial Psicológico y TCA</h3>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr><th>Sesión / Fecha</th><th>PHQ-9 / GAD-7</th><th>Escala BES</th><th>Acuerdo / SOAP</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($historialPsicologia as $hp):
                                $gadInfo = interpretarGAD7((int)$hp['gad7_ansiedad']);
                                $besInfo = interpretarBES((int)$hp['bes_atracon']);
                            ?>
                            <tr>
                                <td><strong>#<?= (int)$hp['numero_sesion'] ?></strong> (<?= htmlspecialchars($hp['fecha_sesion']) ?>)</td>
                                <td>
                                    PHQ-9: <?= (int)$hp['phq9_depresion'] ?><br>
                                    <span class="badge <?= $gadInfo['badge'] ?>">GAD-7: <?= (int)$hp['gad7_ansiedad'] ?></span>
                                </td>
                                <td><span class="badge <?= $besInfo['badge'] ?>">BES: <?= (int)$hp['bes_atracon'] ?></span></td>
                                <td><small><?= htmlspecialchars(substr((string)$hp['acuerdos_interdisciplinarios'], 0, 65)) ?>...</small></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>

    <script>
    function calcHomaPreview() {
        const g = parseFloat(document.getElementById('chk_glucosa')?.value) || 0;
        const i = parseFloat(document.getElementById('chk_insulina')?.value) || 0;
        const out = document.getElementById('chk_homa_preview');
        if (out && g > 0 && i > 0) {
            out.value = ((g * i) / 405).toFixed(2);
        }
    }
    </script>
</body>
</html>
