<?php
declare(strict_types=1);
require_once __DIR__ . '/db_connection.php';

$pdo = getDB();
$usuario = requireAuth();
$hoy = date('Y-m-d');

$pacienteId = isset($_GET['paciente_id']) ? (int)$_GET['paciente_id'] : 1;
$tabActiva = $_GET['tab'] ?? 'nutricion';
$mensajeExito = '';
$mensajeError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $mensajeError = 'Token de seguridad inválido.';
    } else {
        $accion = $_POST['accion'] ?? '';
        $pacienteId = (int)($_POST['paciente_id'] ?? $pacienteId);

        if ($accion === 'guardar_nutricion') {
            $peso = (float)($_POST['peso_kg'] ?? 70);
            $talla = (float)($_POST['talla_cm'] ?? 165);
            $grasaPct = (float)($_POST['porcentaje_grasa'] ?? 28);
            $musculoPct = (float)($_POST['porcentaje_musculo'] ?? 32);
            $visceral = (int)($_POST['grasa_visceral'] ?? 6);
            $cintura = (float)($_POST['cintura_cm'] ?? 80);
            $cadera = (float)($_POST['cadera_cm'] ?? 98);
            $pTri = (float)($_POST['pliegue_tricipital_mm'] ?? 15);
            $pBic = (float)($_POST['pliegue_bicipital_mm'] ?? 8);
            $pSub = (float)($_POST['pliegue_subescapular_mm'] ?? 14);
            $pSup = (float)($_POST['pliegue_suprailiaco_mm'] ?? 16);
            $formula = $_POST['formula_usada'] ?? 'Mifflin-St Jeor';
            $metaKcal = (int)($_POST['meta_kcal'] ?? 1800);
            $pctPro = (int)($_POST['pct_proteina'] ?? 25);
            $pctCho = (int)($_POST['pct_carbohidratos'] ?? 45);
            $pctLip = (int)($_POST['pct_lipidos'] ?? 30);
            $diag = trim($_POST['diagnostico_nutricional'] ?? '');

            $stmtP = $pdo->prepare("SELECT * FROM pacientes WHERE id = ?");
            $stmtP->execute([$pacienteId]);
            $pac = $stmtP->fetch();

            $tallaM = max(1.0, $talla / 100.0);
            $imc = round($peso / ($tallaM * $tallaM), 2);
            $masaGrasaKg = round($peso * ($grasaPct / 100.0), 2);
            $masaMagraKg = round($peso - $masaGrasaKg, 2);
            $icc = $cadera > 0 ? round($cintura / $cadera, 2) : 0.80;
            $ict = $talla > 0 ? round($cintura / $talla, 2) : 0.48;
            $sumaPliegues = round($pTri + $pBic + $pSub + $pSup, 1);

            $edad = calcularEdad($pac['fecha_nacimiento'] ?? '1992-01-01');
            $sexo = $pac['sexo'] ?? 'F';
            $act = $pac['nivel_actividad_fisica'] ?? 'moderado';
            $gasto = calcularGastoEnergetico($peso, $talla, $edad, $sexo, $act, $formula, $masaMagraKg);

            $stmtIns = $pdo->prepare("INSERT INTO consultas_antropometria (paciente_id, nutriologo_id, fecha_consulta, peso_kg, talla_cm, imc, porcentaje_grasa, porcentaje_musculo, masa_grasa_kg, masa_magra_kg, grasa_visceral, cintura_cm, cadera_cm, icc, ict, pliegue_tricipital_mm, pliegue_bicipital_mm, pliegue_subescapular_mm, pliegue_suprailiaco_mm, suma_4_pliegues_mm, formula_usada, tmb_kcal, get_kcal, meta_kcal, pct_proteina, pct_carbohidratos, pct_lipidos, diagnostico_nutricional) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmtIns->execute([
                $pacienteId, 1, $hoy, $peso, $talla, $imc, $grasaPct, $musculoPct, $masaGrasaKg, $masaMagraKg,
                $visceral, $cintura, $cadera, $icc, $ict, $pTri, $pBic, $pSub, $pSup, $sumaPliegues,
                $formula, $gasto['tmb'], $gasto['get'], $metaKcal, $pctPro, $pctCho, $pctLip, $diag
            ]);

            if (!empty($_POST['nombre_plan'])) {
                $proG = (int)round(($metaKcal * ($pctPro / 100)) / 4);
                $choG = (int)round(($metaKcal * ($pctCho / 100)) / 4);
                $lipG = (int)round(($metaKcal * ($pctLip / 100)) / 9);
                $stmtPlan = $pdo->prepare("INSERT INTO planes_alimentacion (paciente_id, nutriologo_id, nombre_plan, kcal_objetivo, proteina_g, carbohidratos_g, lipidos_g, agua_ml, eq_verduras, eq_frutas, eq_cereales_sg, eq_leguminosas, eq_aoa_bajo, eq_leche_semi, eq_grasas_sp, eq_grasas_cp, menu_desayuno, menu_colacion_1, menu_comida, menu_colacion_2, menu_cena, recomendaciones_generales) VALUES (?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmtPlan->execute([
                    $pacienteId,
                    trim($_POST['nombre_plan']),
                    $metaKcal, $proG, $choG, $lipG,
                    (int)($_POST['agua_ml'] ?? 2500),
                    (float)($_POST['eq_verduras'] ?? 5),
                    (float)($_POST['eq_frutas'] ?? 3),
                    (float)($_POST['eq_cereales_sg'] ?? 5),
                    (float)($_POST['eq_leguminosas'] ?? 1),
                    (float)($_POST['eq_aoa_bajo'] ?? 6),
                    (float)($_POST['eq_leche_semi'] ?? 1),
                    (float)($_POST['eq_grasas_sp'] ?? 4),
                    (float)($_POST['eq_grasas_cp'] ?? 2),
                    trim($_POST['menu_desayuno'] ?? ''),
                    trim($_POST['menu_colacion_1'] ?? ''),
                    trim($_POST['menu_comida'] ?? ''),
                    trim($_POST['menu_colacion_2'] ?? ''),
                    trim($_POST['menu_cena'] ?? ''),
                    trim($_POST['recomendaciones_generales'] ?? '')
                ]);
            }

            $mensajeExito = "Evaluación antropométrica (IMC {$imc}) y plan nutricional guardados.";
            $tabActiva = 'historial';
        }

        elseif ($accion === 'guardar_psicologia') {
            $enfoque = trim($_POST['enfoque_terapeutico'] ?? 'Cognitivo-Conductual (TCC)');
            $phq9 = (int)($_POST['phq9_depresion'] ?? 5);
            $gad7 = (int)($_POST['gad7_ansiedad'] ?? 7);
            $bes = (int)($_POST['bes_atracon'] ?? 10);
            $estres = (int)($_POST['nivel_estres'] ?? 5);
            $sueno = (float)($_POST['calidad_sueno_hrs'] ?? 7.0);
            $riesgo = $_POST['riesgo_clinico'] ?? 'bajo';
            $examen = trim($_POST['estado_mental_examen'] ?? '');
            $soap = trim($_POST['notas_soap'] ?? '');
            $conducta = trim($_POST['conducta_alimentaria_emocional'] ?? '');
            $tareas = trim($_POST['tareas_terapeuticas'] ?? '');
            $acuerdos = trim($_POST['acuerdos_interdisciplinarios'] ?? '');

            $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM consultas_psicologia WHERE paciente_id = ?");
            $stmtCount->execute([$pacienteId]);
            $numSesion = (int)$stmtCount->fetchColumn() + 1;

            $stmtIns = $pdo->prepare("INSERT INTO consultas_psicologia (paciente_id, psicologo_id, fecha_sesion, numero_sesion, modalidad_sesion, enfoque_terapeutico, phq9_depresion, gad7_ansiedad, bes_atracon, nivel_estres, calidad_sueno_hrs, riesgo_clinico, estado_mental_examen, notas_soap, conducta_alimentaria_emocional, tareas_terapeuticas, acuerdos_interdisciplinarios) VALUES (?, 3, ?, ?, 'psicoterapia_individual', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmtIns->execute([
                $pacienteId, $hoy, $numSesion, $enfoque, $phq9, $gad7, $bes, $estres, $sueno,
                $riesgo, $examen, $soap, $conducta, $tareas, $acuerdos
            ]);

            $mensajeExito = "Sesión psicológica #{$numSesion} registrada en el expediente.";
            $tabActiva = 'historial';
        }

        elseif ($accion === 'guardar_checkup') {
            $tipoCheckup = $_POST['tipo_checkup'] ?? 'Checkup Integral Mente-Cuerpo 360°';
            $paSis = (int)($_POST['presion_sistolica'] ?? 115);
            $paDia = (int)($_POST['presion_diastolica'] ?? 75);
            $fc = (int)($_POST['frecuencia_cardiaca'] ?? 68);
            $temp = (float)($_POST['temperatura_c'] ?? 36.5);
            $spo2 = (int)($_POST['spo2_oxigenacion'] ?? 98);
            $glucosa = (float)($_POST['glucosa_ayuno_mg'] ?? 90);
            $insulina = (float)($_POST['insulina_basal_uui'] ?? 8.5);
            $hba1c = (float)($_POST['hba1c_pct'] ?? 5.2);
            $colesterol = (float)($_POST['colesterol_total_mg'] ?? 180);
            $trigliceridos = (float)($_POST['trigliceridos_mg'] ?? 115);
            $hdl = (float)($_POST['hdl_mg'] ?? 55);
            $ldl = (float)($_POST['ldl_mg'] ?? 100);
            $tsh = (float)($_POST['tsh_tiroides'] ?? 2.1);
            $cortisol = (float)($_POST['cortisol_matutino'] ?? 14.0);
            $vitD = (float)($_POST['vitamina_d_ng'] ?? 32.0);
            $interp = trim($_POST['interpretacion_biomarcadores'] ?? '');
            $planAccion = trim($_POST['plan_accion_checkup'] ?? '');

            $homa = calcularHomaIR($glucosa, $insulina);
            $semaforoMet = ($homa !== null && $homa >= 2.9) ? 'alerta' : (($homa !== null && $homa >= 2.0) ? 'precaucion' : 'optimo');
            $semaforoPsi = ($cortisol > 19.0) ? 'precaucion' : 'optimo';
            $score = ($semaforoMet === 'optimo') ? 92 : (($semaforoMet === 'precaucion') ? 84 : 72);

            $countChk = (int)$pdo->query("SELECT COUNT(*) FROM checkups_clinicos")->fetchColumn() + 1;
            $folioChk = 'CHK-' . date('Y') . '-' . str_pad((string)$countChk, 3, '0', STR_PAD_LEFT);

            $stmtChk = $pdo->prepare("INSERT INTO checkups_clinicos (folio_checkup, paciente_id, especialista_id, fecha_checkup, tipo_checkup, presion_sistolica, presion_diastolica, frecuencia_cardiaca, temperatura_c, spo2_oxigenacion, glucosa_ayuno_mg, insulina_basal_uui, indice_homa_ir, hba1c_pct, colesterol_total_mg, trigliceridos_mg, hdl_mg, ldl_mg, tsh_tiroides, cortisol_matutino, vitamina_d_ng, semaforo_metabolico, semaforo_psicoemocional, score_salud_integral, interpretacion_biomarcadores, plan_accion_checkup) VALUES (?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmtChk->execute([
                $folioChk, $pacienteId, $hoy, $tipoCheckup, $paSis, $paDia, $fc, $temp, $spo2,
                $glucosa, $insulina, $homa, $hba1c, $colesterol, $trigliceridos, $hdl, $ldl,
                $tsh, $cortisol, $vitD, $semaforoMet, $semaforoPsi, $score, $interp, $planAccion
            ]);

            $mensajeExito = "Checkup {$folioChk} guardado (HOMA-IR: {$homa}, Score: {$score}/100).";
            $tabActiva = 'historial';
        }
    }
}

$pacientesLista = $pdo->query("SELECT id, folio_expediente, nombre_completo FROM pacientes ORDER BY id ASC")->fetchAll();
$stmtPac = $pdo->prepare("
    SELECT p.*, un.nombre AS nutriologo_nombre, up.nombre AS psicologo_nombre
    FROM pacientes p
    LEFT JOIN usuarios un ON p.nutriologo_id = un.id
    LEFT JOIN usuarios up ON p.psicologo_id = up.id
    WHERE p.id = ?
");
$stmtPac->execute([$pacienteId]);
$paciente = $stmtPac->fetch() ?: $pdo->query("SELECT * FROM pacientes LIMIT 1")->fetch();
$pacienteId = (int)$paciente['id'];

$stmtAntro = $pdo->prepare("SELECT * FROM consultas_antropometria WHERE paciente_id = ? ORDER BY id DESC");
$stmtAntro->execute([$pacienteId]);
$historialAntro = $stmtAntro->fetchAll();
$ultimaAntro = $historialAntro[0] ?? null;

$stmtPlan = $pdo->prepare("SELECT * FROM planes_alimentacion WHERE paciente_id = ? ORDER BY id DESC LIMIT 1");
$stmtPlan->execute([$pacienteId]);
$ultimoPlan = $stmtPlan->fetch();

$stmtPsico = $pdo->prepare("SELECT * FROM consultas_psicologia WHERE paciente_id = ? ORDER BY id DESC");
$stmtPsico->execute([$pacienteId]);
$historialPsico = $stmtPsico->fetchAll();
$ultimaPsico = $historialPsico[0] ?? null;

$stmtCheckup = $pdo->prepare("SELECT * FROM checkups_clinicos WHERE paciente_id = ? ORDER BY id DESC");
$stmtCheckup->execute([$pacienteId]);
$historialCheckups = $stmtCheckup->fetchAll();
$ultimoCheckup = $historialCheckups[0] ?? null;

$subPills = [
    ['label' => '1. Nutrición y Menú SMAE', 'href' => "consulta.php?paciente_id={$pacienteId}&tab=nutricion", 'active' => ($tabActiva === 'nutricion'), 'count' => null],
    ['label' => '2. Psicología y Escalas', 'href' => "consulta.php?paciente_id={$pacienteId}&tab=psicologia", 'active' => ($tabActiva === 'psicologia'), 'count' => null],
    ['label' => '3. Checkup 360°', 'href' => "consulta.php?paciente_id={$pacienteId}&tab=checkup", 'active' => ($tabActiva === 'checkup'), 'count' => null],
    ['label' => '4. Historial Evolución', 'href' => "consulta.php?paciente_id={$pacienteId}&tab=historial", 'active' => ($tabActiva === 'historial'), 'count' => count($historialAntro) + count($historialPsico) + count($historialCheckups)],
];
$actionTop = '<a href="imprimir_plan.php?paciente_id=' . $pacienteId . '" target="_blank" class="btn btn-outline btn-sm">Imprimir Reporte Clínico</a>';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consulta Clínica | <?= htmlspecialchars($paciente['nombre_completo']) ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<?php renderAppStart('Consulta Clínica', 'Expediente electrónico de Nutrición, Psicología y Checkup', 'consulta', $subPills, $actionTop); ?>

<?php if ($mensajeExito): ?>
    <div class="alert alert-success"><?= htmlspecialchars($mensajeExito) ?></div>
<?php endif; ?>
<?php if ($mensajeError): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($mensajeError) ?></div>
<?php endif; ?>

<!-- BARRA COMPACTA DEL PACIENTE EN CONSULTA -->
<div class="card card-compact" style="margin-bottom:22px;background:var(--primary-soft);border-color:#cde2dc;">
    <div class="flex-between" style="flex-wrap:wrap;gap:16px;">
        <div class="avatar-row">
            <img src="<?= htmlspecialchars(getFotoPaciente($pacienteId)) ?>" alt="" class="avatar-md">
            <div>
                <div class="flex-gap">
                    <h2 style="font-size:1.2rem;margin:0;"><?= htmlspecialchars($paciente['nombre_completo']) ?></h2>
                    <span class="badge badge-teal"><?= htmlspecialchars($paciente['folio_expediente']) ?></span>
                    <span class="badge badge-slate"><?= calcularEdad($paciente['fecha_nacimiento']) ?> años</span>
                </div>
                <div class="text-xs text-muted" style="margin-top:4px;">
                    <strong>Objetivo:</strong> <?= htmlspecialchars($paciente['objetivo_principal'] ?: 'Evaluación integral') ?>
                    <?php if (!empty($paciente['alergias_intolerancias'])): ?>
                        · <strong>Alergias:</strong> <?= htmlspecialchars($paciente['alergias_intolerancias']) ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <form method="GET" class="flex-gap">
            <input type="hidden" name="tab" value="<?= htmlspecialchars($tabActiva) ?>">
            <label class="text-xs text-muted" style="margin:0;">Cambiar paciente:</label>
            <select name="paciente_id" class="form-control" style="width:240px;" onchange="this.form.submit()">
                <?php foreach ($pacientesLista as $pl): ?>
                    <option value="<?= (int)$pl['id'] ?>" <?= ((int)$pl['id'] === $pacienteId) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($pl['nombre_completo']) ?> (<?= htmlspecialchars($pl['folio_expediente']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
</div>

<?php if ($tabActiva === 'nutricion'): ?>
    <!-- =========================================================
         PESTAÑA 1: NUTRICIÓN, ANTROPOMETRÍA ISAK Y PLAN SMAE
         ========================================================= -->
    <form method="POST" action="consulta.php?paciente_id=<?= $pacienteId ?>&tab=nutricion">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
        <input type="hidden" name="accion" value="guardar_nutricion">
        <input type="hidden" name="paciente_id" value="<?= $pacienteId ?>">

        <div class="grid-2">
            <div class="card">
                <div class="card-header">
                    <div>
                        <h2>1. Antropometría InBody / ISAK II</h2>
                        <p>Medición corporal y cálculo energético del paciente.</p>
                    </div>
                </div>

                <div class="form-row-4">
                    <div class="form-group">
                        <label>Peso (kg) *</label>
                        <input type="number" step="0.1" name="peso_kg" class="form-control" value="<?= (float)($ultimaAntro['peso_kg'] ?? 69.8) ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Talla (cm) *</label>
                        <input type="number" step="0.1" name="talla_cm" class="form-control" value="<?= (float)($ultimaAntro['talla_cm'] ?? 163.0) ?>" required>
                    </div>
                    <div class="form-group">
                        <label>% Grasa</label>
                        <input type="number" step="0.1" name="porcentaje_grasa" class="form-control" value="<?= (float)($ultimaAntro['porcentaje_grasa'] ?? 31.2) ?>">
                    </div>
                    <div class="form-group">
                        <label>% Músculo</label>
                        <input type="number" step="0.1" name="porcentaje_musculo" class="form-control" value="<?= (float)($ultimaAntro['porcentaje_musculo'] ?? 27.9) ?>">
                    </div>
                </div>

                <div class="form-row-3">
                    <div class="form-group">
                        <label>Cintura (cm)</label>
                        <input type="number" step="0.1" name="cintura_cm" class="form-control" value="<?= (float)($ultimaAntro['cintura_cm'] ?? 82.0) ?>">
                    </div>
                    <div class="form-group">
                        <label>Cadera (cm)</label>
                        <input type="number" step="0.1" name="cadera_cm" class="form-control" value="<?= (float)($ultimaAntro['cadera_cm'] ?? 100.5) ?>">
                    </div>
                    <div class="form-group">
                        <label>Grasa Visceral (Nivel)</label>
                        <input type="number" name="grasa_visceral" class="form-control" value="<?= (int)($ultimaAntro['grasa_visceral'] ?? 7) ?>">
                    </div>
                </div>

                <div class="form-row-4">
                    <div class="form-group">
                        <label>P. Tricipital (mm)</label>
                        <input type="number" step="0.5" name="pliegue_tricipital_mm" class="form-control" value="<?= (float)($ultimaAntro['pliegue_tricipital_mm'] ?? 17.0) ?>">
                    </div>
                    <div class="form-group">
                        <label>P. Bicipital (mm)</label>
                        <input type="number" step="0.5" name="pliegue_bicipital_mm" class="form-control" value="<?= (float)($ultimaAntro['pliegue_bicipital_mm'] ?? 9.5) ?>">
                    </div>
                    <div class="form-group">
                        <label>P. Subescapular</label>
                        <input type="number" step="0.5" name="pliegue_subescapular_mm" class="form-control" value="<?= (float)($ultimaAntro['pliegue_subescapular_mm'] ?? 16.0) ?>">
                    </div>
                    <div class="form-group">
                        <label>P. Suprailíaco</label>
                        <input type="number" step="0.5" name="pliegue_suprailiaco_mm" class="form-control" value="<?= (float)($ultimaAntro['pliegue_suprailiaco_mm'] ?? 18.5) ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Fórmula Energética</label>
                        <select name="formula_usada" class="form-control">
                            <option value="Mifflin-St Jeor">Mifflin-St Jeor</option>
                            <option value="Harris-Benedict">Harris-Benedict</option>
                            <option value="Katch-McArdle">Katch-McArdle (Masa Magra)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Meta Prescrita (Kcal/día) *</label>
                        <input type="number" name="meta_kcal" class="form-control" value="<?= (int)($ultimaAntro['meta_kcal'] ?? 1800) ?>" required>
                    </div>
                </div>

                <div class="form-row-3">
                    <div class="form-group">
                        <label>% Proteína</label>
                        <input type="number" name="pct_proteina" class="form-control" value="<?= (int)($ultimaAntro['pct_proteina'] ?? 28) ?>">
                    </div>
                    <div class="form-group">
                        <label>% Carbohidratos</label>
                        <input type="number" name="pct_carbohidratos" class="form-control" value="<?= (int)($ultimaAntro['pct_carbohidratos'] ?? 42) ?>">
                    </div>
                    <div class="form-group">
                        <label>% Lípidos</label>
                        <input type="number" name="pct_lipidos" class="form-control" value="<?= (int)($ultimaAntro['pct_lipidos'] ?? 30) ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label>Diagnóstico Nutricional PES</label>
                    <textarea name="diagnostico_nutricional" class="form-control" rows="2"><?= htmlspecialchars($ultimaAntro['diagnostico_nutricional'] ?? '') ?></textarea>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <div>
                        <h2>2. Plan Alimenticio y Equivalentes SMAE</h2>
                        <p>Menú personalizado que se imprimirá para el paciente.</p>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Nombre del Plan *</label>
                        <input type="text" name="nombre_plan" class="form-control" value="<?= htmlspecialchars($ultimoPlan['nombre_plan'] ?? 'Plan Antiinflamatorio Personalizado') ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Meta de Agua (ml/día)</label>
                        <input type="number" name="agua_ml" class="form-control" value="<?= (int)($ultimoPlan['agua_ml'] ?? 2600) ?>">
                    </div>
                </div>

                <div class="form-row-4">
                    <div class="form-group">
                        <label>Eq. Verduras</label>
                        <input type="number" step="0.5" name="eq_verduras" class="form-control" value="<?= (float)($ultimoPlan['eq_verduras'] ?? 5) ?>">
                    </div>
                    <div class="form-group">
                        <label>Eq. Frutas</label>
                        <input type="number" step="0.5" name="eq_frutas" class="form-control" value="<?= (float)($ultimoPlan['eq_frutas'] ?? 3) ?>">
                    </div>
                    <div class="form-group">
                        <label>Eq. Cereales</label>
                        <input type="number" step="0.5" name="eq_cereales_sg" class="form-control" value="<?= (float)($ultimoPlan['eq_cereales_sg'] ?? 5) ?>">
                    </div>
                    <div class="form-group">
                        <label>Eq. AOA (Proteína)</label>
                        <input type="number" step="0.5" name="eq_aoa_bajo" class="form-control" value="<?= (float)($ultimoPlan['eq_aoa_bajo'] ?? 6) ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label>Desayuno</label>
                    <textarea name="menu_desayuno" class="form-control" rows="2"><?= htmlspecialchars($ultimoPlan['menu_desayuno'] ?? '') ?></textarea>
                </div>
                <div class="form-group">
                    <label>Comida Principal</label>
                    <textarea name="menu_comida" class="form-control" rows="2"><?= htmlspecialchars($ultimoPlan['menu_comida'] ?? '') ?></textarea>
                </div>
                <div class="form-group">
                    <label>Colación Vespertina (Regulación de Saciedad)</label>
                    <textarea name="menu_colacion_2" class="form-control" rows="2"><?= htmlspecialchars($ultimoPlan['menu_colacion_2'] ?? '') ?></textarea>
                </div>
                <div class="form-group">
                    <label>Cena</label>
                    <textarea name="menu_cena" class="form-control" rows="2"><?= htmlspecialchars($ultimoPlan['menu_cena'] ?? '') ?></textarea>
                </div>

                <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;padding:11px;">
                    Guardar Evaluación Nutricional y Plan
                </button>
            </div>
        </div>
    </form>

<?php elseif ($tabActiva === 'psicologia'): ?>
    <!-- =========================================================
         PESTAÑA 2: PSICOLOGÍA CLÍNICA Y PSICONUTRICIÓN (SOAP)
         ========================================================= -->
    <div class="card" style="max-width:860px;margin:0 auto;">
        <div class="card-header">
            <div>
                <h2>Nota Clínica de Psicología y Psiconutrición</h2>
                <p>Tamizaje psicométrico (PHQ-9, GAD-7, BES) y evolución clínica en formato SOAP.</p>
            </div>
        </div>

        <form method="POST" action="consulta.php?paciente_id=<?= $pacienteId ?>&tab=psicologia">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="accion" value="guardar_psicologia">
            <input type="hidden" name="paciente_id" value="<?= $pacienteId ?>">

            <div class="form-row-4">
                <div class="form-group">
                    <label>PHQ-9 Depresión (0-27)</label>
                    <input type="number" min="0" max="27" name="phq9_depresion" class="form-control" value="<?= (int)($ultimaPsico['phq9_depresion'] ?? 5) ?>">
                </div>
                <div class="form-group">
                    <label>GAD-7 Ansiedad (0-21)</label>
                    <input type="number" min="0" max="21" name="gad7_ansiedad" class="form-control" value="<?= (int)($ultimaPsico['gad7_ansiedad'] ?? 8) ?>">
                </div>
                <div class="form-group">
                    <label>Escala BES Atracón (0-46)</label>
                    <input type="number" min="0" max="46" name="bes_atracon" class="form-control" value="<?= (int)($ultimaPsico['bes_atracon'] ?? 12) ?>">
                </div>
                <div class="form-group">
                    <label>Horas de Sueño / Noche</label>
                    <input type="number" step="0.5" name="calidad_sueno_hrs" class="form-control" value="<?= (float)($ultimaPsico['calidad_sueno_hrs'] ?? 7.0) ?>">
                </div>
            </div>

            <div class="form-row-3">
                <div class="form-group">
                    <label>Enfoque Terapéutico</label>
                    <select name="enfoque_terapeutico" class="form-control">
                        <option value="Psiconutrición / TCC + ACT">Psiconutrición / TCC + ACT</option>
                        <option value="Cognitivo-Conductual (TCC)">Cognitivo-Conductual (TCC)</option>
                        <option value="Terapia de Aceptación y Compromiso">Terapia de Aceptación y Compromiso (ACT)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Estrés Percibido (1-10)</label>
                    <input type="number" min="1" max="10" name="nivel_estres" class="form-control" value="<?= (int)($ultimaPsico['nivel_estres'] ?? 5) ?>">
                </div>
                <div class="form-group">
                    <label>Riesgo Clínico</label>
                    <select name="riesgo_clinico" class="form-control">
                        <option value="bajo">Bajo (Estable)</option>
                        <option value="moderado">Moderado (Seguimiento estrecho)</option>
                        <option value="alto">Alto (Prioritario)</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>Nota de Evolución Clínica SOAP (Subjetivo, Objetivo, Análisis, Plan) *</label>
                <textarea name="notas_soap" class="form-control" rows="4" required><?= htmlspecialchars($ultimaPsico['notas_soap'] ?? '') ?></textarea>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Conducta Alimentaria y Regulación Emocional</label>
                    <textarea name="conducta_alimentaria_emocional" class="form-control" rows="2"><?= htmlspecialchars($ultimaPsico['conducta_alimentaria_emocional'] ?? '') ?></textarea>
                </div>
                <div class="form-group">
                    <label>Tareas Terapéuticas y Acuerdos con Nutrición</label>
                    <textarea name="tareas_terapeuticas" class="form-control" rows="2"><?= htmlspecialchars($ultimaPsico['tareas_terapeuticas'] ?? '') ?></textarea>
                </div>
            </div>

            <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;padding:11px;">
                Guardar Sesión Psicológica
            </button>
        </form>
    </div>

<?php elseif ($tabActiva === 'checkup'): ?>
    <!-- =========================================================
         PESTAÑA 3: CHECKUP MÉDICO Y METABÓLICO 360°
         ========================================================= -->
    <div class="card" style="max-width:860px;margin:0 auto;">
        <div class="card-header">
            <div>
                <h2>Checkup Clínico y Biomarcadores Metabólicos</h2>
                <p>Calcula automáticamente el índice de resistencia a la insulina HOMA-IR y el semáforo de salud.</p>
            </div>
        </div>

        <form method="POST" action="consulta.php?paciente_id=<?= $pacienteId ?>&tab=checkup">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="accion" value="guardar_checkup">
            <input type="hidden" name="paciente_id" value="<?= $pacienteId ?>">

            <div class="form-group">
                <label>Tipo de Checkup Realizado</label>
                <select name="tipo_checkup" class="form-control">
                    <option value="Checkup Integral Mente-Cuerpo 360°">Checkup Integral Mente-Cuerpo 360°</option>
                    <option value="Checkup Cardiometabólico y Resistencia a la Insulina">Checkup Cardiometabólico y Resistencia a la Insulina</option>
                    <option value="Checkup Deportivo de Alto Rendimiento + Plicometría ISAK II">Checkup Deportivo de Alto Rendimiento + Plicometría ISAK II</option>
                </select>
            </div>

            <div class="form-row-4">
                <div class="form-group">
                    <label>Presión Sistólica (mmHg)</label>
                    <input type="number" name="presion_sistolica" class="form-control" value="<?= (int)($ultimoCheckup['presion_sistolica'] ?? 116) ?>">
                </div>
                <div class="form-group">
                    <label>Presión Diastólica (mmHg)</label>
                    <input type="number" name="presion_diastolica" class="form-control" value="<?= (int)($ultimoCheckup['presion_diastolica'] ?? 74) ?>">
                </div>
                <div class="form-group">
                    <label>Frec. Cardíaca (lpm)</label>
                    <input type="number" name="frecuencia_cardiaca" class="form-control" value="<?= (int)($ultimoCheckup['frecuencia_cardiaca'] ?? 66) ?>">
                </div>
                <div class="form-group">
                    <label>SpO2 Oxigenación (%)</label>
                    <input type="number" name="spo2_oxigenacion" class="form-control" value="<?= (int)($ultimoCheckup['spo2_oxigenacion'] ?? 98) ?>">
                </div>
            </div>

            <div class="form-row-4">
                <div class="form-group">
                    <label>Glucosa Ayuno (mg/dL) *</label>
                    <input type="number" step="0.1" name="glucosa_ayuno_mg" class="form-control" value="<?= (float)($ultimoCheckup['glucosa_ayuno_mg'] ?? 94.0) ?>" required>
                </div>
                <div class="form-group">
                    <label>Insulina Basal (uUI/mL) *</label>
                    <input type="number" step="0.1" name="insulina_basal_uui" class="form-control" value="<?= (float)($ultimoCheckup['insulina_basal_uui'] ?? 10.2) ?>" required>
                </div>
                <div class="form-group">
                    <label>HbA1c (%)</label>
                    <input type="number" step="0.1" name="hba1c_pct" class="form-control" value="<?= (float)($ultimoCheckup['hba1c_pct'] ?? 5.3) ?>">
                </div>
                <div class="form-group">
                    <label>Triglicéridos (mg/dL)</label>
                    <input type="number" step="0.1" name="trigliceridos_mg" class="form-control" value="<?= (float)($ultimoCheckup['trigliceridos_mg'] ?? 128.0) ?>">
                </div>
            </div>

            <div class="form-row-4">
                <div class="form-group">
                    <label>Colesterol Total (mg/dL)</label>
                    <input type="number" step="0.1" name="colesterol_total_mg" class="form-control" value="<?= (float)($ultimoCheckup['colesterol_total_mg'] ?? 184.0) ?>">
                </div>
                <div class="form-group">
                    <label>Colesterol HDL (mg/dL)</label>
                    <input type="number" step="0.1" name="hdl_mg" class="form-control" value="<?= (float)($ultimoCheckup['hdl_mg'] ?? 56.0) ?>">
                </div>
                <div class="form-group">
                    <label>TSH Tiroides (mUI/L)</label>
                    <input type="number" step="0.01" name="tsh_tiroides" class="form-control" value="<?= (float)($ultimoCheckup['tsh_tiroides'] ?? 2.10) ?>">
                </div>
                <div class="form-group">
                    <label>Cortisol Matutino (ug/dL)</label>
                    <input type="number" step="0.1" name="cortisol_matutino" class="form-control" value="<?= (float)($ultimoCheckup['cortisol_matutino'] ?? 14.5) ?>">
                </div>
            </div>

            <div class="form-group">
                <label>Interpretación Clínica de Biomarcadores *</label>
                <textarea name="interpretacion_biomarcadores" class="form-control" rows="2" required><?= htmlspecialchars($ultimoCheckup['interpretacion_biomarcadores'] ?? '') ?></textarea>
            </div>

            <div class="form-group">
                <label>Plan de Acción Médico-Nutricional *</label>
                <textarea name="plan_accion_checkup" class="form-control" rows="2" required><?= htmlspecialchars($ultimoCheckup['plan_accion_checkup'] ?? '') ?></textarea>
            </div>

            <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;padding:11px;">
                Guardar Checkup Clínico
            </button>
        </form>
    </div>

<?php elseif ($tabActiva === 'historial'): ?>
    <!-- =========================================================
         PESTAÑA 4: HISTORIAL LONGITUDINAL DEL PACIENTE
         ========================================================= -->
    <div class="grid-3" style="margin-bottom:24px;">
        <div class="card">
            <span class="text-xs text-muted">Último Peso / Composición</span>
            <?php if ($ultimaAntro): ?>
                <div style="font-size:1.5rem;font-weight:700;margin:6px 0;"><?= (float)$ultimaAntro['peso_kg'] ?> kg <span class="badge badge-teal">IMC <?= (float)$ultimaAntro['imc'] ?></span></div>
                <div class="text-xs text-muted"><?= (float)$ultimaAntro['porcentaje_grasa'] ?>% grasa · <?= (float)$ultimaAntro['cintura_cm'] ?> cm cintura · Meta: <?= (int)$ultimaAntro['meta_kcal'] ?> kcal</div>
            <?php else: ?>
                <div class="text-sm text-muted" style="margin-top:8px;">Sin registros antropométricos.</div>
            <?php endif; ?>
        </div>

        <div class="card">
            <span class="text-xs text-muted">Último Tamizaje Psicológico</span>
            <?php if ($ultimaPsico):
                $gadInfo = interpretarGAD7((int)$ultimaPsico['gad7_ansiedad']);
            ?>
                <div style="font-size:1.5rem;font-weight:700;margin:6px 0;">GAD-7: <?= (int)$ultimaPsico['gad7_ansiedad'] ?> <span class="badge <?= $gadInfo['badge'] ?>"><?= $gadInfo['nivel'] ?></span></div>
                <div class="text-xs text-muted">PHQ-9: <?= (int)$ultimaPsico['phq9_depresion'] ?> pts · BES Atracón: <?= (int)$ultimaPsico['bes_atracon'] ?> pts · Sueño: <?= (float)$ultimaPsico['calidad_sueno_hrs'] ?>h</div>
            <?php else: ?>
                <div class="text-sm text-muted" style="margin-top:8px;">Sin sesiones psicológicas registradas.</div>
            <?php endif; ?>
        </div>

        <div class="card">
            <span class="text-xs text-muted">Último Checkup Metabólico</span>
            <?php if ($ultimoCheckup):
                $homaInfo = interpretarHomaIR((float)$ultimoCheckup['indice_homa_ir']);
            ?>
                <div style="font-size:1.5rem;font-weight:700;margin:6px 0;">HOMA-IR <?= (float)$ultimoCheckup['indice_homa_ir'] ?> <span class="badge <?= $homaInfo['badge'] ?>">Score <?= (int)$ultimoCheckup['score_salud_integral'] ?></span></div>
                <div class="text-xs text-muted">Glucosa: <?= (float)$ultimoCheckup['glucosa_ayuno_mg'] ?> mg/dL · PA: <?= (int)$ultimoCheckup['presion_sistolica'] ?>/<?= (int)$ultimoCheckup['presion_diastolica'] ?> mmHg</div>
            <?php else: ?>
                <div class="text-sm text-muted" style="margin-top:8px;">Sin checkups registrados.</div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card" style="margin-bottom:22px;">
        <div class="card-header">
            <h2>Evolución Antropométrica</h2>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Peso / IMC</th>
                        <th>% Grasa / % Músculo</th>
                        <th>Cintura / Suma Pliegues</th>
                        <th>Diagnóstico Clínico</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($historialAntro as $ha): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($ha['fecha_consulta']) ?></strong></td>
                            <td><?= (float)$ha['peso_kg'] ?> kg (IMC <?= (float)$ha['imc'] ?>)</td>
                            <td><?= (float)$ha['porcentaje_grasa'] ?>% / <?= (float)$ha['porcentaje_musculo'] ?>%</td>
                            <td><?= (float)$ha['cintura_cm'] ?> cm / <?= (float)$ha['suma_4_pliegues_mm'] ?> mm</td>
                            <td class="text-sm text-muted"><?= htmlspecialchars($ha['diagnostico_nutricional']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="grid-2">
        <div class="card">
            <div class="card-header">
                <h2>Sesiones de Psicología y Psiconutrición</h2>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Escalas</th>
                            <th>Resumen SOAP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($historialPsico as $hp): ?>
                            <tr>
                                <td>
                                    <strong>#<?= (int)$hp['numero_sesion'] ?></strong>
                                    <div class="text-xs text-muted"><?= htmlspecialchars($hp['fecha_sesion']) ?></div>
                                </td>
                                <td>
                                    <span class="badge badge-purple">GAD-7: <?= (int)$hp['gad7_ansiedad'] ?></span>
                                    <div class="text-xs text-muted">BES: <?= (int)$hp['bes_atracon'] ?> · PHQ: <?= (int)$hp['phq9_depresion'] ?></div>
                                </td>
                                <td class="text-xs text-muted"><?= nl2br(htmlspecialchars($hp['notas_soap'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2>Checkups Realizados</h2>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Folio / Fecha</th>
                            <th>Biomarcadores</th>
                            <th>Interpretación</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($historialCheckups as $hc): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($hc['folio_checkup']) ?></strong>
                                    <div class="text-xs text-muted"><?= htmlspecialchars($hc['fecha_checkup']) ?></div>
                                </td>
                                <td>
                                    <span class="badge badge-teal">HOMA <?= (float)$hc['indice_homa_ir'] ?></span>
                                    <div class="text-xs text-muted">Gluc: <?= (float)$hc['glucosa_ayuno_mg'] ?> · TG: <?= (float)$hc['trigliceridos_mg'] ?></div>
                                </td>
                                <td class="text-xs text-muted"><?= htmlspecialchars($hc['interpretacion_biomarcadores']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php renderAppEnd(); ?>
</body>
</html>
