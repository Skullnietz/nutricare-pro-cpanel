<?php
require_once __DIR__ . '/db_connection.php';
$pdo = getDB();
$mensajeExito = '';
$errorAgenda  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['agendar_publico'])) {
    $especialistaId = (int)($_POST['nutriologo_id'] ?? 1);
    $servicioId     = (int)($_POST['servicio_id'] ?? 1);
    $nombre         = trim($_POST['nombre'] ?? '');
    $telefono       = trim($_POST['telefono'] ?? '');
    $email          = trim($_POST['email'] ?? '');
    $fecha          = $_POST['fecha_cita'] ?? date('Y-m-d');
    $hora           = $_POST['hora_inicio'] ?? '10:00';
    $motivo         = trim($_POST['motivo'] ?? '');

    if ($nombre && $telefono && $fecha && $hora) {
        $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM citas WHERE nutriologo_id = ? AND fecha_cita = ? AND hora_inicio = ? AND estado NOT IN ('cancelada', 'no_asistio')");
        $stmtCheck->execute([$especialistaId, $fecha, $hora . ':00']);
        if ($stmtCheck->fetchColumn() > 0) {
            $errorAgenda = "El horario seleccionado ya está reservado con este especialista. Por favor elige otra hora.";
        } else {
            $stmtSrv = $pdo->prepare("SELECT * FROM catalogo_servicios WHERE id = ?");
            $stmtSrv->execute([$servicioId]);
            $srv = $stmtSrv->fetch();

            $stmtDoc = $pdo->prepare("SELECT * FROM usuarios WHERE id = ?");
            $stmtDoc->execute([$especialistaId]);
            $doc = $stmtDoc->fetch();

            $areaCita = $srv['area'] ?? 'nutricion';
            if ($areaCita === 'paquete') $areaCita = 'psiconutricion';
            $requiereAyuno = ($areaCita === 'checkup' || !empty($srv['incluye_checkup'])) ? 1 : 0;
            $prefijos = ['nutricion' => 'N-', 'psicologia' => 'P-', 'psiconutricion' => 'PN-', 'checkup' => 'CK-'];
            $turno = ($prefijos[$areaCita] ?? 'W-') . rand(10, 99);
            $duracion = (int)($srv['duracion_minutos'] ?? 50);
            $horaFin = date('H:i:s', strtotime($hora . " + {$duracion} minutes"));
            $costo = (float)($srv['precio'] ?? $doc['costo_primera_vez'] ?? 950);
            $consultorio = $doc['consultorio_asignado'] ?? 'Consultorio 1';
            $tipoCita = ($areaCita === 'checkup') ? 'checkup_integral' : (($areaCita === 'psicologia') ? 'psicoterapia' : (($areaCita === 'psiconutricion') ? 'psiconutricion_integral' : 'primera_vez'));

            $stmt = $pdo->prepare("INSERT INTO citas (turno_sala, nutriologo_id, servicio_id, area_cita, consultorio, nombre_prospecto, telefono_prospecto, email_prospecto, fecha_cita, hora_inicio, hora_fin, tipo_cita, origen, estado, requiere_ayuno, costo_estimado, motivo_notas) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'landing_web', 'pendiente_confirmar', ?, ?, ?)");
            $stmt->execute([$turno, $especialistaId, $servicioId, $areaCita, $consultorio, $nombre, $telefono, $email, $fecha, $hora . ':00', $horaFin, $tipoCita, $requiereAyuno, $costo, $motivo]);

            $avisoAyuno = $requiereAyuno ? " Recuerda acudir con 8 a 10 horas de ayuno para tu Checkup." : "";
            $mensajeExito = "¡Cita registrada con éxito! Tu folio de turno para Sala de Espera es {$turno}.{$avisoAyuno} Nuestro equipo de Mostrador te enviará confirmación por WhatsApp.";
        }
    }
}

$especialistas = $pdo->query("SELECT * FROM usuarios WHERE rol IN ('nutriologo', 'psicologo') AND activo = 1 ORDER BY id ASC")->fetchAll();
$servicios = $pdo->query("SELECT * FROM catalogo_servicios WHERE activo = 1 ORDER BY id ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(CLINIC_NAME) ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <?php renderNavbar('index'); ?>

    <section class="hero">
        <div class="container grid-2">
            <div>
                <span class="badge badge-teal">CLÍNICA INTEGRAL: NUTRICIÓN · PSICOLOGÍA · PSICONUTRICIÓN · CHECKUPS 360°</span>
                <h1>Salud metabólica, equilibrio mental y checkups preventivos en un solo lugar</h1>
                <p>Atención interdisciplinaria desde nuestro mostrador inteligente hasta tu plan personalizado: Nutrición Clínica y Deportiva ISAK II, Psicoterapia Cognitivo-Conductual (TCC), Psiconutrición y Checkups Cardiometabólicos con facturación inmediata CFDI 4.0.</p>
                <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:16px;">
                    <a href="#agendar" class="btn btn-primary">📅 Agendar Cita o Checkup Online</a>
                    <a href="recepcion.php" class="btn btn-outline" style="background:rgba(255,255,255,0.1);color:#fff;border-color:rgba(255,255,255,0.25);">🛎️ Ir a Mostrador / Sala de Espera</a>
                </div>
            </div>
            <div class="card">
                <h3>🔬 Pre-Checkup Gratuito: Riesgo Cardiometabólico + Hambre Emocional</h3>
                <div class="grid-2">
                    <label>Peso Actual (kg)
                        <input type="number" id="peso" value="74" step="0.1" oninput="calcularPreCheckup()">
                    </label>
                    <label>Estatura (cm)
                        <input type="number" id="talla" value="165" step="0.5" oninput="calcularPreCheckup()">
                    </label>
                    <label>Circunferencia Cintura (cm)
                        <input type="number" id="cintura" value="86" step="0.5" oninput="calcularPreCheckup()">
                    </label>
                    <label>Nivel de Estrés / Ansiedad por Comer (1-10)
                        <input type="number" id="estres" value="7" min="1" max="10" oninput="calcularPreCheckup()">
                    </label>
                </div>
                <div class="result-box" id="resPreCheckup">
                    IMC: 27.2 kg/m² (Sobrepeso) · ICT: 0.52 (Riesgo Metabólico Moderado) · Agua: 2.59 L/día<br>
                    💡 Sugerencia: <strong>Checkup Integral Mente-Cuerpo 360° + Psiconutrición</strong>
                </div>
            </div>
        </div>
    </section>

    <main class="container section-pad">
        <h2>🏥 Nuestras 4 Áreas Clínicas y Especialistas Titulares</h2>
        <p class="muted" style="margin-bottom:16px;">Equipo multidisciplinario coordinado con expediente compartido entre Nutrición, Psicología y Laboratorio de Checkups.</p>
        <div class="grid-4" style="margin-bottom:28px;">
            <?php foreach ($especialistas as $doc): ?>
                <div class="card" style="border-top: 4px solid <?= htmlspecialchars($doc['color_agenda']) ?>; margin-bottom:0;">
                    <div class="flex-between" style="margin-bottom:8px;">
                        <span class="badge <?= $doc['rol'] === 'psicologo' ? 'badge-purple' : 'badge-teal' ?>">
                            <?= $doc['rol'] === 'psicologo' ? '🧠 PSICOLOGÍA / TCA' : '🥗 NUTRICIÓN & CHECKUP' ?>
                        </span>
                        <small class="muted"><?= htmlspecialchars($doc['cedula_profesional']) ?></small>
                    </div>
                    <h3><?= htmlspecialchars($doc['nombre']) ?></h3>
                    <p style="font-weight:600; font-size:12px; color:#334155; margin-bottom:6px;"><?= htmlspecialchars($doc['titulo']) ?></p>
                    <p class="muted" style="margin-bottom:12px;"><?= htmlspecialchars($doc['especialidad']) ?></p>
                    <div style="border-top:1px solid var(--border); padding-top:10px; font-size:12.5px;">
                        📍 <?= htmlspecialchars($doc['consultorio_asignado']) ?><br>
                        1ª Vez: <strong>$<?= number_format((float)$doc['costo_primera_vez'], 2) ?></strong> · Seguimiento: <strong>$<?= number_format((float)$doc['costo_seguimiento'], 2) ?></strong>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="card">
            <h2>🔬 Catálogo de Consultas, Checkups Médicos y Paquetes Integrales</h2>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Servicio / Checkup / Paquete</th>
                            <th>Área</th>
                            <th>Incluye</th>
                            <th>Duración</th>
                            <th>Inversión (MXN)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($servicios as $s): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($s['codigo']) ?></strong></td>
                                <td>
                                    <strong><?= htmlspecialchars($s['nombre']) ?></strong><br>
                                    <small class="muted"><?= htmlspecialchars($s['descripcion']) ?></small>
                                </td>
                                <td>
                                    <?php
                                    $badgeMap = [
                                        'nutricion' => 'badge-teal',
                                        'psicologia' => 'badge-purple',
                                        'psiconutricion' => 'badge-rose',
                                        'checkup' => 'badge-blue',
                                        'paquete' => 'badge-amber'
                                    ];
                                    ?>
                                    <span class="badge <?= $badgeMap[$s['area']] ?? 'badge-slate' ?>"><?= strtoupper(htmlspecialchars($s['area'])) ?></span>
                                </td>
                                <td>
                                    <small>
                                        <?php if ($s['sesiones_nutri_incluidas'] > 0): ?>🥗 <?= (int)$s['sesiones_nutri_incluidas'] ?> Nutrición <?php endif; ?>
                                        <?php if ($s['sesiones_psico_incluidas'] > 0): ?>🧠 <?= (int)$s['sesiones_psico_incluidas'] ?> Psicología <?php endif; ?>
                                        <?php if ($s['incluye_checkup']): ?>🔬 Checkup Biomarcadores<?php endif; ?>
                                    </small>
                                </td>
                                <td><?= (int)$s['duracion_minutos'] ?> min</td>
                                <td><strong>$<?= number_format((float)$s['precio'], 2) ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <section id="agendar" class="card" style="margin-top:24px;">
            <h2>📅 Reserva tu Cita, Sesión Psicológica o Checkup en Línea</h2>
            <p class="muted" style="margin-bottom:14px;">Al agendar recibirás tu código de turno para pasar directamente a Sala de Espera al llegar al mostrador.</p>
            <?php if ($mensajeExito): ?>
                <div class="result-box"><?= htmlspecialchars($mensajeExito) ?></div>
            <?php endif; ?>
            <?php if ($errorAgenda): ?>
                <div class="alert-warning"><?= htmlspecialchars($errorAgenda) ?></div>
            <?php endif; ?>

            <form method="POST" class="grid-3">
                <input type="hidden" name="agendar_publico" value="1">
                <label>Servicio, Consulta o Checkup *
                    <select name="servicio_id" required>
                        <?php foreach ($servicios as $s): ?>
                            <option value="<?= (int)$s['id'] ?>">
                                [<?= strtoupper(htmlspecialchars($s['area'])) ?>] <?= htmlspecialchars($s['nombre']) ?> — $<?= number_format((float)$s['precio'], 0) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Especialista Titular *
                    <select name="nutriologo_id" required>
                        <?php foreach ($especialistas as $doc): ?>
                            <option value="<?= (int)$doc['id'] ?>">
                                <?= htmlspecialchars($doc['nombre']) ?> (<?= strtoupper($doc['area_clinica']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Nombre Completo del Paciente *
                    <input type="text" name="nombre" placeholder="Ej. Mariana Fernández López" required>
                </label>
                <label>Teléfono WhatsApp (10 dígitos) *
                    <input type="tel" name="telefono" placeholder="5512345678" required>
                </label>
                <label>Correo Electrónico (Para envío de Recibo / CFDI)
                    <input type="email" name="email" placeholder="paciente@correo.com">
                </label>
                <label>Fecha Deseada *
                    <input type="date" name="fecha_cita" value="<?= date('Y-m-d') ?>" required>
                </label>
                <label>Hora de Cita *
                    <input type="time" name="hora_inicio" value="10:00" required>
                </label>
                <label style="grid-column: span 2;">Motivo de Consulta / Checkup o Síntomas Principales
                    <input type="text" name="motivo" placeholder="Ej. Checkup metabólico anual, control de peso, manejo de ansiedad o psiconutrición">
                </label>
                <div style="grid-column: 1 / -1;">
                    <button type="submit" class="btn btn-primary">✅ Confirmar Reserva y Generar Turno de Sala de Espera</button>
                </div>
            </form>
        </section>
    </main>

    <script>
    function calcularPreCheckup() {
        const peso = parseFloat(document.getElementById('peso').value) || 0;
        const tallaCm = parseFloat(document.getElementById('talla').value) || 0;
        const cintura = parseFloat(document.getElementById('cintura').value) || 0;
        const estres = parseInt(document.getElementById('estres').value) || 5;

        if (peso > 0 && tallaCm > 0) {
            const imc = (peso / Math.pow(tallaCm / 100, 2)).toFixed(1);
            const ict = cintura > 0 ? (cintura / tallaCm).toFixed(2) : '0.00';
            const agua = ((peso * 35) / 1000).toFixed(2);
            let riesgoMet = ict >= 0.50 ? 'Riesgo Cardiometabólico Elevado (ICT ≥ 0.50)' : 'Riesgo Cardiometabólico Bajo';
            let recomendacion = 'Consulta de Nutrición + Antropometría ISAK';
            if (ict >= 0.50 && estres >= 6) {
                recomendacion = 'Checkup Integral Mente-Cuerpo 360° + Psiconutrición';
            } else if (ict >= 0.50) {
                recomendacion = 'Checkup Cardiometabólico y Resistencia a la Insulina';
            } else if (estres >= 7) {
                recomendacion = 'Evaluación Psicológica TCC / Psiconutrición';
            }
            document.getElementById('resPreCheckup').innerHTML =
                `IMC: <strong>${imc} kg/m²</strong> · ICT: <strong>${ict}</strong> (${riesgoMet}) · Meta de Agua: <strong>${agua} L/día</strong><br>` +
                `💡 Servicio recomendado: <strong>${recomendacion}</strong>`;
        }
    }
    </script>
</body>
</html>
