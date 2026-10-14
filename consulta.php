<?php
  require_once __DIR__ . '/db_connection.php';
$usuario = requireAuth();
$pdo = getDB();
$pacienteId = (int)($_GET['paciente_id'] ?? 1);
$stmtPac = $pdo->prepare("SELECT p.*, u.nombre AS nutriologo_nombre FROM pacientes p JOIN usuarios u ON p.nutriologo_id = u.id WHERE p.id = ?");
$stmtPac->execute([$pacienteId]);
$paciente = $stmtPac->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_consulta']) && $paciente && verify_csrf($_POST['csrf_token'] ?? '')) {
      $edad = calcularEdad($paciente['fecha_nacimiento']);
      $peso = (float)$_POST['peso_kg'];
    $talla = (float)$_POST['talla_cm'];
    $imc = round($peso / pow($talla / 100, 2), 2);
    $pctGrasa = !empty($_POST['porcentaje_grasa']) ? (float)$_POST['porcentaje_grasa'] : null;
    $pctMusc = !empty($_POST['porcentaje_musculo']) ? (float)$_POST['porcentaje_musculo'] : null;
    $masaGrasa = $pctGrasa ? round($peso * ($pctGrasa / 100), 2) : null;
    $masaMagra = $masaGrasa ? round($peso - $masaGrasa, 2) : null;
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

    $stmtIns = $pdo->prepare("INSERT INTO consultas_antropometria (paciente_id, nutriologo_id, fecha_consulta, peso_kg, talla_cm, imc, porcentaje_grasa, porcentaje_musculo, masa_grasa_kg, masa_magra_kg, cintura_cm, cadera_cm, icc, ict, pliegue_tricipital_mm, pliegue_bicipital_mm, pliegue_subescapular_mm, pliegue_suprailiaco_mm, suma_4_pliegues_mm, formula_usada, tmb_kcal, get_kcal, meta_kcal, pct_proteina, pct_carbohidratos, pct_lipidos, diagnostico_nutricional) VALUES (?, ?, CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmtIns->execute([$pacienteId, $usuario['id'], $peso, $talla, $imc, $pctGrasa, $pctMusc, $masaGrasa, $masaMagra, $cintura, $cadera, $icc, $ict, $pTri ?: null, $pBic ?: null, $pSub ?: null, $pSup ?: null, $sumaPliegues, $formula, $energia['tmb'], $energia['get'], $metaKcal, (int)$_POST['pct_proteina'], (int)$_POST['pct_carbohidratos'], (int)$_POST['pct_lipidos'], trim($_POST['diagnostico_nutricional'] ?? '')]);
          header("Location: consulta.php?paciente_id={$pacienteId}&guardado=1");
    exit;
}
<body>
      <header class="topbar">
          <div class="container flex-between">
              <div class="brand">🩺 Estación Clínica y Antropometría ISAK</div>
            <nav class="nav-links">
                <a href="recepcion.php">Recepción y Caja</a>
                <a href="consulta.php" class="active">Consulta</a>
                  <a href="imprimir_plan.php?paciente_id=<?= $pacienteId ?>">Imprimir Plan</a>
              </nav>
          </div>
      </header>
      <main class="container section-pad">
          <div class="card">
              <h3>📐 Evaluación Antropométrica ISAK + Cálculo Energético (Mifflin-St Jeor / Harris-Benedict / Katch-McArdle)</h3>
              <form method="POST" class="grid-2">
                  <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                  <input type="hidden" name="guardar_consulta" value="1">
                  <label>Peso (kg) * <input type="number" name="peso_kg" step="0.1" required></label>
                  <label>Talla (cm) * <input type="number" name="talla_cm" step="0.5" required></label>
                  <label>% Grasa Corporal <input type="number" name="porcentaje_grasa" step="0.1"></label>
                  <label>% Masa Muscular <input type="number" name="porcentaje_musculo" step="0.1"></label>
                  <label>Cintura (cm) <input type="number" name="cintura_cm" step="0.5"></label>
                  <label>Cadera (cm) <input type="number" name="cadera_cm" step="0.5"></label>
                  <label>Pliegue Tricipital (mm) <input type="number" name="pliegue_tricipital_mm" step="0.5"></label>
                  <label>Pliegue Bicipital (mm) <input type="number" name="pliegue_bicipital_mm" step="0.5"></label>
                  <label>Pliegue Subescapular (mm) <input type="number" name="pliegue_subescapular_mm" step="0.5"></label>
                  <label>Pliegue Suprailíaco (mm) <input type="number" name="pliegue_suprailiaco_mm" step="0.5"></label>
                  <label>Fórmula Energtica <select name="formula_usada"><option value="Mifflin-St Jeor">Mifflin-St Jeor</option><option value="Harris-Benedict">Harris-Benedict</option><option value="Katch-McArdle">Katch-McArdle</option></select></label>
                  <label>Meta Calórica (kcal) <input type="number" name="meta_kcal" value="1750"></label>
                  <label>% Proteína <input type="number" name="pct_proteina" value="25"></label>
                  <label>% Carbohidratos <input type="number" name="pct_carbohidratos" value="45"></label>
                  <label>% Lípidos <input type="number" name="pct_lipidos" value="30"></label>
                  <label>Diagnóstico Nutricional PES <textarea name="diagnostico_nutricional"></textarea></label>
                <button type="submit" class="btn btn-primary" style="grid-column:span 2;">Guardar Consulta Antropométrica</button>
              </form>
          </div>
      </main>
  </body>
  </html>
?>
<!DOCTYPE html>
  <html lang="es">
  <head>
      <meta charset="UTF-8">
      <title>Estación Clínica | <?= htmlspecialchars(CLINIC_NAME) ?></title>
      <link rel="stylesheet" href="style.css">
  </head>
