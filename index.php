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

    <!-- HERO SECTION CON ORBES ANIMADOS Y SIMULADOR CLÍNICO 360° -->
    <section class="hero">
        <div class="hero-orb orb-teal"></div>
        <div class="hero-orb orb-purple"></div>
        <div class="container hero-content grid-sidebar">
            <div style="padding-right:10px;">
                <div style="display:inline-flex; align-items:center; gap:8px; background:rgba(255,255,255,0.09); border:1px solid rgba(255,255,255,0.18); padding:6px 14px; border-radius:99px; font-size:12px; font-weight:700; color:#99f6e4;">
                    <span class="live-dot"></span> CLÍNICA INTEGRAL CERTIFICADA · NUTRICIÓN · PSICOLOGÍA · CHECKUPS 360°
                </div>
                <h1>
                    Ciencia médica, <span class="text-gradient">nutrición de precisión y salud mental</span> en un solo ecosistema
                </h1>
                <p>
                    Transformamos tu composición corporal, metabolismo y bienestar emocional mediante evaluaciones antropométricas <strong>ISAK Nivel II</strong>, psicoterapia <strong>Cognitivo-Conductual (TCC)</strong>, <strong>Psiconutrición</strong> y <strong>Checkups Cardiometabólicos HOMA-IR</strong> con gestión digital desde tu llegada a Sala de Espera hasta tu factura CFDI 4.0.
                </p>
                <div style="display:flex; gap:12px; flex-wrap:wrap; margin-top:22px;">
                    <a href="#agendar" class="btn btn-primary btn-lg">📅 Agendar Consulta o Checkup 360°</a>
                    <a href="#catalogo-interactivo" class="btn btn-outline btn-lg" style="background:rgba(255,255,255,0.08); color:#fff; border-color:rgba(255,255,255,0.22);">🔬 Explorar Checkups y Paquetes</a>
                    <a href="recepcion.php" class="btn btn-outline btn-lg" style="background:rgba(15,23,42,0.55); color:#5eead4; border-color:rgba(94,234,212,0.35);">🛎️ Acceso Mostrador / Sala en Vivo</a>
                </div>

                <div class="trust-strip">
                    <div class="trust-item">
                        <strong>+2,850</strong>
                        <span>Pacientes en Cartera Activa</span>
                    </div>
                    <div class="trust-item">
                        <strong>ISAK II + InBody</strong>
                        <span>Cineantropometría Avanzada</span>
                    </div>
                    <div class="trust-item">
                        <strong>TCC &amp; TCA</strong>
                        <span>Psicología y Psiconutrición</span>
                    </div>
                    <div class="trust-item">
                        <strong>CFDI 4.0</strong>
                        <span>Facturación Deducible D01</span>
                    </div>
                </div>
            </div>

            <!-- WIDGET INTERACTIVO: SIMULADOR BIOMÉTRICO Y PSICOEMOCIONAL 360° -->
            <div class="glass-card">
                <div class="flex-between" style="margin-bottom:14px;">
                    <div>
                        <span class="badge badge-teal">DIAGNÓSTICO INTERACTIVO GRATUITO</span>
                        <h3 style="margin-top:4px; font-size:18px;">🔬 Simulador de Pre-Checkup 360°</h3>
                    </div>
                    <div style="text-align:center; background:#0f172a; color:#fff; padding:8px 14px; border-radius:14px;">
                        <small style="display:block; font-size:10px; color:#94a3b8; text-transform:uppercase;">Score Salud</small>
                        <strong id="sim_score" style="font-size:22px; color:#5eead4;">84/100</strong>
                    </div>
                </div>

                <div class="grid-2" style="gap:12px;">
                    <label>Peso Actual: <strong id="lbl_peso" style="color:var(--primary);">74 kg</strong>
                        <input type="range" id="peso" min="40" max="150" step="0.5" value="74" oninput="calcularPreCheckup()">
                    </label>
                    <label>Estatura: <strong id="lbl_talla" style="color:var(--primary);">165 cm</strong>
                        <input type="range" id="talla" min="140" max="205" step="1" value="165" oninput="calcularPreCheckup()">
                    </label>
                    <label>Cintura Abdominal: <strong id="lbl_cintura" style="color:var(--blue);">86 cm</strong>
                        <input type="range" id="cintura" min="55" max="140" step="1" value="86" oninput="calcularPreCheckup()">
                    </label>
                    <label>Estrés / Hambre Emocional: <strong id="lbl_estres" style="color:var(--purple);">7 / 10</strong>
                        <input type="range" id="estres" min="1" max="10" step="1" value="7" oninput="calcularPreCheckup()">
                    </label>
                </div>

                <!-- Medidores visuales en vivo -->
                <div style="background:#f8fafc; border:1px solid var(--border); border-radius:12px; padding:14px; margin:10px 0 14px;">
                    <div class="flex-between" style="font-size:12.5px; margin-bottom:4px;">
                        <span><strong>IMC:</strong> <span id="val_imc">27.2 kg/m²</span> (<span id="txt_imc">Sobrepeso</span>)</span>
                        <span><strong>ICT Cintura/Talla:</strong> <span id="val_ict">0.52</span></span>
                    </div>
                    <div class="progress-track">
                        <div class="progress-fill" id="bar_imc" style="width: 68%;"></div>
                    </div>
                    <div class="flex-between" style="font-size:12px; color:var(--muted); margin-top:8px;">
                        <span>💧 Hidratación Óptima: <strong id="val_agua" style="color:var(--text);">2.59 L/día</strong></span>
                        <span id="txt_riesgo_met" class="badge badge-amber">Riesgo Metabólico Moderado</span>
                    </div>
                </div>

                <div class="result-box" id="resPreCheckup" style="margin-bottom:14px;">
                    💡 Ruta Clínica Sugerida: <strong>Checkup Integral Mente-Cuerpo 360° (HOMA-IR + Antropometría + Psiconutrición)</strong>
                </div>

                <button type="button" onclick="seleccionarServicioRecomendado()" class="btn btn-primary" style="width:100%; padding:11px;">
                    ✨ Agendar Ruta Clínica Recomendada Ahora
                </button>
            </div>
        </div>
    </section>

    <main class="container section-pad">
        <!-- SECCIÓN 1: BENTO GRID DE LAS 4 ÁREAS CLÍNICAS -->
        <div class="reveal" style="text-align:center; max-width:760px; margin: 12px auto 32px;">
            <span class="badge badge-teal">ATENCIÓN CLÍNICA MULTIDISCIPLINARIA</span>
            <h2 style="font-size:28px; margin-top:8px;">Cuatro especialidades integradas bajo un mismo expediente clínico</h2>
            <p class="muted">Olvídate de tratamientos aislados. Nuestros nutriólogos, psicólogos clínicos y área de Checkups trabajan en sincronía para darte resultados sostenibles.</p>
        </div>

        <div class="grid-4 reveal" style="margin-bottom:44px;">
            <div class="pillar-card" style="border-top: 4px solid var(--primary);">
                <div class="pillar-icon" style="background:#ccfbf1; color:#0f766e;">🥗</div>
                <span class="badge badge-teal">ÁREA 01 · NUTRICIÓN</span>
                <h3 style="margin-top:8px;">Nutrición Clínica y Deportiva ISAK II</h3>
                <p class="muted" style="margin-bottom:12px;">Bioimpedancia InBody, plicometría de 4 pliegues ISAK, cálculo exacto TMB/GET (Mifflin-St Jeor y Katch-McArdle) y menús personalizados SMAE.</p>
                <ul style="font-size:12px; color:var(--text-secondary); padding-left:16px;">
                    <li>Recomposición corporal e hipertrofia</li>
                    <li>Diabetes, SOP y resistencia a la insulina</li>
                    <li>Plan entregable impreso y en PDF</li>
                </ul>
            </div>

            <div class="pillar-card" style="border-top: 4px solid var(--purple);">
                <div class="pillar-icon" style="background:#f3e8ff; color:#6b21a8;">🧠</div>
                <span class="badge badge-purple">ÁREA 02 · PSICOLOGÍA</span>
                <h3 style="margin-top:8px;">Psicología Clínica y Psicoterapia TCC</h3>
                <p class="muted" style="margin-bottom:12px;">Terapia Cognitivo-Conductual basada en evidencia con monitoreo psicométrico objetivo en cada sesión mediante escalas clínicas internacionales.</p>
                <ul style="font-size:12px; color:var(--text-secondary); padding-left:16px;">
                    <li>Tamizaje clínico PHQ-9 y GAD-7</li>
                    <li>Manejo de ansiedad, depresión y burnout</li>
                    <li>Protocolos de higiene del sueño</li>
                </ul>
            </div>

            <div class="pillar-card" style="border-top: 4px solid var(--rose);">
                <div class="pillar-icon" style="background:#ffe4e6; color:#be123c;">🤝</div>
                <span class="badge badge-rose">ÁREA 03 · PSICONUTRICIÓN</span>
                <h3 style="margin-top:8px;">Psiconutrición y Conducta Alimentaria</h3>
                <p class="muted" style="margin-bottom:12px;">Intervención conjunta Nutrición + Psicología para sanar tu relación con la comida sin dietas restrictivas ni ciclos de culpa.</p>
                <ul style="font-size:12px; color:var(--text-secondary); padding-left:16px;">
                    <li>Escala BES de atracón y hambre emocional</li>
                    <li>Trastornos de la Conducta Alimentaria (TCA)</li>
                    <li>Alimentación consciente (Mindful Eating)</li>
                </ul>
            </div>

            <div class="pillar-card" style="border-top: 4px solid var(--blue);">
                <div class="pillar-icon" style="background:#dbeafe; color:#1d4ed8;">🔬</div>
                <span class="badge badge-blue">ÁREA 04 · CHECKUPS 360°</span>
                <h3 style="margin-top:8px;">Checkups Médicos y Biomarcadores</h3>
                <p class="muted" style="margin-bottom:12px;">Evaluación preventiva integral con signos vitales, perfil lipídico, función tiroidea, cortisol matutino y cálculo de sensibilidad insulínica.</p>
                <ul style="font-size:12px; color:var(--text-secondary); padding-left:16px;">
                    <li>Índice HOMA-IR, Glucosa y HbA1c</li>
                    <li>Perfil tiroideo TSH, Vitamina D y Cortisol</li>
                    <li>Semáforo clínico y Score de Salud 360°</li>
                </ul>
            </div>
        </div>

        <!-- SECCIÓN 2: FLUJO DE EXPERIENCIA DEL PACIENTE (STEPPER) -->
        <div class="card reveal" style="background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); color:#fff; margin-bottom:44px;">
            <div class="flex-between">
                <div>
                    <span class="badge badge-teal">EXPERIENCIA SIN FRICCIONES</span>
                    <h2 style="color:#fff; margin-top:6px;">¿Cómo funciona tu visita en NutriMind &amp; Care Pro?</h2>
                </div>
                <a href="recepcion.php" class="btn btn-outline btn-sm" style="background:rgba(255,255,255,0.08); color:#fff; border-color:rgba(255,255,255,0.2);">📺 Ver Pantalla de Sala de Espera</a>
            </div>
            <div class="stepper-grid">
                <div class="step-card" style="background:rgba(255,255,255,0.05); border-color:rgba(255,255,255,0.12); color:#fff;">
                    <div class="step-number">1</div>
                    <h4 style="color:#5eead4; margin-bottom:6px;">Reserva y Turno Digital</h4>
                    <p style="font-size:12.5px; color:#cbd5e1;">Elige tu consulta, paquete o Checkup en línea. El sistema te asigna un código único de turno (<code>N-</code>, <code>P-</code>, <code>PN-</code> o <code>CK-</code>) y te avisa si requieres ayuno.</p>
                </div>
                <div class="step-card" style="background:rgba(255,255,255,0.05); border-color:rgba(255,255,255,0.12); color:#fff;">
                    <div class="step-number">2</div>
                    <h4 style="color:#c4b5fd; margin-bottom:6px;">Mostrador y Sala en Vivo</h4>
                    <p style="font-size:12.5px; color:#cbd5e1;">Al llegar, nuestro mostrador registra tu Check-in en 1 clic. Visualiza tu turno en la pantalla de Sala de Espera y pasa al consultorio asignado.</p>
                </div>
                <div class="step-card" style="background:rgba(255,255,255,0.05); border-color:rgba(255,255,255,0.12); color:#fff;">
                    <div class="step-number">3</div>
                    <h4 style="color:#93c5fd; margin-bottom:6px;">Estación Clínica 360°</h4>
                    <p style="font-size:12.5px; color:#cbd5e1;">Tu especialista evalúa antropometría ISAK, escalas psicológicas (PHQ-9/GAD-7/BES) y biomarcadores HOMA-IR en un expediente unificado.</p>
                </div>
                <div class="step-card" style="background:rgba(255,255,255,0.05); border-color:rgba(255,255,255,0.12); color:#fff;">
                    <div class="step-number">4</div>
                    <h4 style="color:#fda4af; margin-bottom:6px;">Plan PDF, POS y CFDI 4.0</h4>
                    <p style="font-size:12.5px; color:#cbd5e1;">Recibe tu reporte integral impreso o en PDF, liquida en nuestro Punto de Venta o redime tu paquete, y descarga tu factura deducible CFDI 4.0.</p>
                </div>
            </div>
        </div>

        <!-- SECCIÓN 3: DIRECTORIO DE ESPECIALISTAS CON AGENDADO EN 1 CLIC -->
        <div class="reveal" style="margin-bottom:44px;">
            <div class="flex-between" style="margin-bottom:18px;">
                <div>
                    <span class="badge badge-purple">CUERPO MÉDICO Y CLÍNICO TITULAR</span>
                    <h2 style="margin-top:4px;">Especialistas certificados con atención personalizada</h2>
                </div>
                <span class="muted">Haz clic en cualquier especialista para seleccionarlo automáticamente en la agenda</span>
            </div>

            <div class="grid-4">
                <?php foreach ($especialistas as $doc):
                    $partes = explode(' ', str_replace(['Dra.', 'Dr.', 'Mtra.'], '', trim($doc['nombre'])));
                    $iniciales = strtoupper(substr($partes[0] ?? 'N', 0, 1) . substr($partes[1] ?? 'C', 0, 1));
                ?>
                    <div class="card card-hover" style="border-top: 4px solid <?= htmlspecialchars($doc['color_agenda']) ?>; margin-bottom:0; display:flex; flex-direction:column; justify-content:space-between;">
                        <div>
                            <div class="flex-between" style="margin-bottom:14px;">
                                <div class="doc-avatar" style="background: <?= htmlspecialchars($doc['color_agenda']) ?>;">
                                    <?= htmlspecialchars($iniciales) ?>
                                </div>
                                <div style="text-align:right;">
                                    <span class="badge <?= $doc['rol'] === 'psicologo' ? 'badge-purple' : 'badge-teal' ?>">
                                        <?= $doc['rol'] === 'psicologo' ? '🧠 PSICOLOGÍA / TCA' : '🥗 NUTRICIÓN & CHECKUP' ?>
                                    </span>
                                    <small class="muted" style="display:block; margin-top:4px;"><?= htmlspecialchars($doc['cedula_profesional']) ?></small>
                                </div>
                            </div>
                            <h3 style="font-size:17px; margin-bottom:4px;"><?= htmlspecialchars($doc['nombre']) ?></h3>
                            <p style="font-weight:700; font-size:12px; color:var(--primary-dark); margin-bottom:8px;"><?= htmlspecialchars($doc['titulo']) ?></p>
                            <p class="muted" style="margin-bottom:14px; font-size:12.5px;"><?= htmlspecialchars($doc['especialidad']) ?></p>
                        </div>

                        <div style="border-top:1px solid var(--border); padding-top:12px;">
                            <div class="flex-between" style="font-size:12px; margin-bottom:10px;">
                                <span class="muted">📍 <?= htmlspecialchars($doc['consultorio_asignado']) ?></span>
                                <span>1ª Vez: <strong>$<?= number_format((float)$doc['costo_primera_vez'], 0) ?></strong> · Seg: <strong>$<?= number_format((float)$doc['costo_seguimiento'], 0) ?></strong></span>
                            </div>
                            <button type="button" onclick="seleccionarDoctorAgenda(<?= (int)$doc['id'] ?>)" class="btn btn-outline" style="width:100%;">
                                📅 Agendar con <?= htmlspecialchars($doc['nombre']) ?>
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- SECCIÓN 4: CATÁLOGO INTERACTIVO CON FILTROS EN VIVO -->
        <div class="card reveal" id="catalogo-interactivo">
            <div class="flex-between" style="margin-bottom:14px;">
                <div>
                    <span class="badge badge-blue">TARIFAS TRANSPARENTES Y DEDUCIBLES DE IMPUESTOS (CFDI 4.0)</span>
                    <h2 style="margin-top:4px;">🔬 Catálogo de Consultas, Checkups 360° y Paquetes Integrales</h2>
                </div>
            </div>

            <div class="filter-pills">
                <button type="button" class="filter-pill active" onclick="filtrarCatalogo('todos', this)">✨ Todos los Servicios (<?= count($servicios) ?>)</button>
                <button type="button" class="filter-pill" onclick="filtrarCatalogo('checkup', this)">🔬 Checkups Médicos 360°</button>
                <button type="button" class="filter-pill" onclick="filtrarCatalogo('paquete', this)">📦 Paquetes y Membresías (Ahorro)</button>
                <button type="button" class="filter-pill" onclick="filtrarCatalogo('psiconutricion', this)">🤝 Psiconutrición (TCA)</button>
                <button type="button" class="filter-pill" onclick="filtrarCatalogo('nutricion', this)">🥗 Nutrición e ISAK</button>
                <button type="button" class="filter-pill" onclick="filtrarCatalogo('psicologia', this)">🧠 Psicoterapia TCC</button>
            </div>

            <div class="table-wrap">
                <table id="tabla_catalogo">
                    <thead>
                        <tr>
                            <th>Código / SAT</th>
                            <th>Servicio Clínico, Checkup o Membresía</th>
                            <th>Especialidad</th>
                            <th>Beneficios Incluidos</th>
                            <th>Duración</th>
                            <th>Inversión</th>
                            <th>Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($servicios as $s):
                            $badgeMap = [
                                'nutricion' => 'badge-teal',
                                'psicologia' => 'badge-purple',
                                'psiconutricion' => 'badge-rose',
                                'checkup' => 'badge-blue',
                                'paquete' => 'badge-amber'
                            ];
                        ?>
                            <tr data-area="<?= htmlspecialchars($s['area']) ?>">
                                <td>
                                    <code style="font-weight:700; color:var(--text);"><?= htmlspecialchars($s['codigo']) ?></code><br>
                                    <small class="muted">SAT: <?= htmlspecialchars($s['clave_prod_serv_sat']) ?></small>
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars($s['nombre']) ?></strong>
                                    <?php if ($s['codigo'] === 'CHK-360' || $s['codigo'] === 'PAQ-INTEGRAL-4'): ?>
                                        <span class="badge badge-amber" style="margin-left:6px;">⭐ MÁS SOLICITADO</span>
                                    <?php endif; ?>
                                    <br>
                                    <small class="muted"><?= htmlspecialchars($s['descripcion']) ?></small>
                                </td>
                                <td>
                                    <span class="badge <?= $badgeMap[$s['area']] ?? 'badge-slate' ?>"><?= strtoupper(htmlspecialchars($s['area'])) ?></span>
                                </td>
                                <td>
                                    <div style="display:flex; gap:4px; flex-wrap:wrap;">
                                        <?php if ($s['sesiones_nutri_incluidas'] > 0): ?><span class="badge badge-teal">🥗 <?= (int)$s['sesiones_nutri_incluidas'] ?> Nutrición</span><?php endif; ?>
                                        <?php if ($s['sesiones_psico_incluidas'] > 0): ?><span class="badge badge-purple">🧠 <?= (int)$s['sesiones_psico_incluidas'] ?> Psicología</span><?php endif; ?>
                                        <?php if ($s['incluye_checkup']): ?><span class="badge badge-blue">🔬 Checkup 360°</span><?php endif; ?>
                                    </div>
                                </td>
                                <td><?= (int)$s['duracion_minutos'] ?> min</td>
                                <td><strong style="font-size:15px;">$<?= number_format((float)$s['precio'], 2) ?></strong></td>
                                <td>
                                    <button type="button" onclick="seleccionarServicioAgenda(<?= (int)$s['id'] ?>)" class="btn btn-primary btn-sm">
                                        Elegir →
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- SECCIÓN 5: RESERVA EN LÍNEA Y PREGUNTAS FRECUENTES -->
        <div class="grid-sidebar reveal" id="agendar" style="margin-top:32px;">
            <div class="card" style="border-top: 5px solid var(--primary); margin-bottom:0;">
                <span class="badge badge-teal">SISTEMA DE AGENDADO CON CONEXIÓN DIRECTA A SALA DE ESPERA</span>
                <h2 style="margin-top:6px;">📅 Reserva tu Consulta, Sesión Psicológica o Checkup 360°</h2>
                <p class="muted" style="margin-bottom:18px;">Tu solicitud ingresa en tiempo real a la pantalla de recepción y genera tu código de turno al instante.</p>

                <?php if ($mensajeExito): ?>
                    <div class="result-box"><?= htmlspecialchars($mensajeExito) ?></div>
                <?php endif; ?>
                <?php if ($errorAgenda): ?>
                    <div class="alert-warning"><?= htmlspecialchars($errorAgenda) ?></div>
                <?php endif; ?>

                <form method="POST" class="grid-2">
                    <input type="hidden" name="agendar_publico" value="1">
                    <label style="grid-column: span 2;">1. Servicio, Consulta, Paquete o Checkup *
                        <select name="servicio_id" id="form_servicio_id" required>
                            <?php foreach ($servicios as $s): ?>
                                <option value="<?= (int)$s['id'] ?>">
                                    [<?= strtoupper(htmlspecialchars($s['area'])) ?>] <?= htmlspecialchars($s['nombre']) ?> — $<?= number_format((float)$s['precio'], 2) ?> MXN
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>2. Especialista Titular *
                        <select name="nutriologo_id" id="form_nutriologo_id" required>
                            <?php foreach ($especialistas as $doc): ?>
                                <option value="<?= (int)$doc['id'] ?>">
                                    <?= htmlspecialchars($doc['nombre']) ?> (<?= strtoupper($doc['area_clinica']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>3. Nombre Completo del Paciente *
                        <input type="text" name="nombre" placeholder="Ej. Mariana Fernández López" required>
                    </label>
                    <label>4. Teléfono WhatsApp (Para confirmación y recordatorio) *
                        <input type="tel" name="telefono" placeholder="5512345678" required>
                    </label>
                    <label>5. Correo Electrónico (Envío de Plan PDF y Factura CFDI)
                        <input type="email" name="email" placeholder="paciente@correo.com">
                    </label>
                    <label>6. Fecha de tu Visita *
                        <input type="date" name="fecha_cita" value="<?= date('Y-m-d') ?>" required>
                    </label>
                    <label>7. Horario Disponible *
                        <input type="time" name="hora_inicio" value="10:00" required>
                    </label>
                    <label style="grid-column: span 2;">8. Objetivo Clínico, Síntomas o Notas para Recepción
                        <input type="text" name="motivo" id="form_motivo" placeholder="Ej. Checkup metabólico anual, pérdida de grasa, manejo de ansiedad o psiconutrición">
                    </label>
                    <div style="grid-column: span 2;">
                        <button type="submit" class="btn btn-primary btn-lg" style="width:100%;">
                            ✅ Confirmar Cita y Obtener Folio de Turno para Sala de Espera
                        </button>
                    </div>
                </form>
            </div>

            <!-- Testimonios y Preguntas Frecuentes -->
            <div>
                <div class="card">
                    <h3>💬 Lo que dicen nuestros pacientes</h3>
                    <div style="border-left:3px solid var(--primary); padding-left:12px; margin-bottom:14px;">
                        <p style="font-style:italic; font-size:13px;">"El paquete de Equilibrio Total con el Checkup 360° me permitió descubrir mi resistencia a la insulina (HOMA-IR) y al mismo tiempo trabajar la ansiedad nocturna en Psiconutrición. Bajé 4.5 cm de cintura sin pasar hambre."</p>
                        <small class="muted"><strong>— Mariana F.</strong> · Paciente de Psiconutrición y Checkup</small>
                    </div>
                    <div style="border-left:3px solid var(--purple); padding-left:12px;">
                        <p style="font-style:italic; font-size:13px;">"Desde que llegas al mostrador ves tu turno en pantalla, te hacen tu plicometría ISAK II y al salir te timbran tu factura CFDI 4.0 deducible en menos de un minuto."</p>
                        <small class="muted"><strong>— Jorge E. Ramírez</strong> · Paciente de Nutrición Deportiva y TCC</small>
                    </div>
                </div>

                <div class="card" style="margin-bottom:0;">
                    <h3>❓ Preguntas Frecuentes antes de acudir</h3>
                    <details style="margin-bottom:10px; border-bottom:1px solid var(--border); padding-bottom:10px;" open>
                        <summary style="font-weight:700; cursor:pointer;">¿Cómo debo acudir para un Checkup Médico 360°?</summary>
                        <p class="muted" style="margin-top:6px;">Acude con ayuno de 8 a 10 horas (solo agua natural permitida) y ropa cómoda para la bioimpedancia InBody y plicometría ISAK.</p>
                    </details>
                    <details style="margin-bottom:10px; border-bottom:1px solid var(--border); padding-bottom:10px;">
                        <summary style="font-weight:700; cursor:pointer;">¿Las consultas y Checkups son deducibles de impuestos?</summary>
                        <p class="muted" style="margin-top:6px;">Sí. Emitimos factura electrónica CFDI 4.0 con Uso <code>D01</code> (Honorarios médicos, psicológicos y nutricionales) al pagar con tarjeta o transferencia SPEI.</p>
                    </details>
                    <details>
                        <summary style="font-weight:700; cursor:pointer;">¿Puedo compartir o administrar mis sesiones en paquete?</summary>
                        <p class="muted" style="margin-top:6px;">En nuestro módulo de Cartera CRM llevamos el conteo exacto de tus sesiones de Nutrición, Psicología y Checkups disponibles.</p>
                    </details>
                </div>
            </div>
        </div>
    </main>

    <footer class="footer-modern">
        <div class="container flex-between">
            <div>
                <strong style="color:#fff; font-size:16px;"><?= htmlspecialchars(CLINIC_NAME) ?></strong><br>
                <small><?= htmlspecialchars(CLINIC_ADDRESS) ?> · Tel: <?= htmlspecialchars(CLINIC_PHONE) ?> · RFC: <?= htmlspecialchars(CLINIC_RFC) ?></small>
            </div>
            <div style="display:flex; gap:12px;">
                <a href="recepcion.php" style="color:#5eead4; text-decoration:none; font-weight:600;">🛎️ Mostrador POS</a>
                <a href="cartera.php" style="color:#cbd5e1; text-decoration:none;">👥 Cartera CRM</a>
                <a href="facturacion.php" style="color:#cbd5e1; text-decoration:none;">🧾 Facturación CFDI</a>
                <a href="consulta.php" style="color:#cbd5e1; text-decoration:none;">🩺 Estación Clínica</a>
                <a href="administracion.php" style="color:#cbd5e1; text-decoration:none;">📊 Administración</a>
            </div>
        </div>
    </footer>

    <script>
    let servicioRecomendadoId = 6; // Default Checkup 360

    function calcularPreCheckup() {
        const peso = parseFloat(document.getElementById('peso').value) || 74;
        const tallaCm = parseFloat(document.getElementById('talla').value) || 165;
        const cintura = parseFloat(document.getElementById('cintura').value) || 86;
        const estres = parseInt(document.getElementById('estres').value) || 7;

        document.getElementById('lbl_peso').textContent = peso + ' kg';
        document.getElementById('lbl_talla').textContent = tallaCm + ' cm';
        document.getElementById('lbl_cintura').textContent = cintura + ' cm';
        document.getElementById('lbl_estres').textContent = estres + ' / 10';

        const imcNum = peso / Math.pow(tallaCm / 100, 2);
        const imc = imcNum.toFixed(1);
        const ictNum = cintura / tallaCm;
        const ict = ictNum.toFixed(2);
        const agua = ((peso * 35) / 1000).toFixed(2);

        let catImc = 'Peso Saludable';
        if (imcNum < 18.5) catImc = 'Bajo Peso';
        else if (imcNum >= 25 && imcNum < 30) catImc = 'Sobrepeso';
        else if (imcNum >= 30) catImc = 'Obesidad Clínica';

        document.getElementById('val_imc').textContent = imc + ' kg/m²';
        document.getElementById('txt_imc').textContent = catImc;
        document.getElementById('val_ict').textContent = ict;
        document.getElementById('val_agua').textContent = agua + ' L/día';

        const pctBar = Math.min(100, Math.max(15, ((imcNum - 15) / 25) * 100));
        const barEl = document.getElementById('bar_imc');
        barEl.style.width = pctBar + '%';
        barEl.className = 'progress-fill ' + (imcNum >= 30 ? 'rose' : (imcNum >= 25 ? 'amber' : ''));

        const badgeMet = document.getElementById('txt_riesgo_met');
        if (ictNum >= 0.55) {
            badgeMet.textContent = 'Alerta Cardiometabólica (ICT ≥ 0.55)';
            badgeMet.className = 'badge badge-rose';
        } else if (ictNum >= 0.50) {
            badgeMet.textContent = 'Riesgo Metabólico Moderado (ICT ≥ 0.50)';
            badgeMet.className = 'badge badge-amber';
        } else {
            badgeMet.textContent = 'Riesgo Cardiometabólico Óptimo';
            badgeMet.className = 'badge badge-teal';
        }

        // Calcular Score 360 simulado
        let score = 96;
        if (imcNum >= 25) score -= Math.round((imcNum - 24) * 1.8);
        if (ictNum >= 0.50) score -= Math.round((ictNum - 0.48) * 60);
        if (estres >= 6) score -= (estres - 4) * 2;
        score = Math.max(52, Math.min(98, score));
        document.getElementById('sim_score').textContent = score + '/100';

        let recomendacion = 'Consulta Nutricional 1ª Vez + InBody/ISAK';
        servicioRecomendadoId = 1;
        if (ictNum >= 0.50 && estres >= 6) {
            recomendacion = 'Checkup Integral Mente-Cuerpo 360° (Metabólico + Antropometría + Psicoemocional)';
            servicioRecomendadoId = 6;
        } else if (ictNum >= 0.50) {
            recomendacion = 'Checkup Cardiometabólico y Resistencia a la Insulina (HOMA-IR)';
            servicioRecomendadoId = 7;
        } else if (estres >= 7) {
            recomendacion = 'Consulta Interdisciplinaria de Psiconutrición (TCA / Ansiedad)';
            servicioRecomendadoId = 5;
        }

        document.getElementById('resPreCheckup').innerHTML =
            `💡 Ruta Clínica Sugerida: <strong>${recomendacion}</strong>`;
    }

    function seleccionarServicioRecomendado() {
        seleccionarServicioAgenda(servicioRecomendadoId);
    }

    function seleccionarServicioAgenda(id) {
        const sel = document.getElementById('form_servicio_id');
        if (sel) sel.value = String(id);
        document.getElementById('agendar')?.scrollIntoView({ behavior: 'smooth' });
    }

    function seleccionarDoctorAgenda(id) {
        const sel = document.getElementById('form_nutriologo_id');
        if (sel) sel.value = String(id);
        document.getElementById('agendar')?.scrollIntoView({ behavior: 'smooth' });
    }

    function filtrarCatalogo(area, btn) {
        document.querySelectorAll('.filter-pill').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        document.querySelectorAll('#tabla_catalogo tbody tr').forEach(tr => {
            if (area === 'todos' || tr.getAttribute('data-area') === area) {
                tr.style.display = '';
            } else {
                tr.style.display = 'none';
            }
        });
    }

    // Scroll reveal observer
    const obs = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) entry.target.classList.add('visible');
        });
    }, { threshold: 0.1 });
    document.querySelectorAll('.reveal').forEach(el => obs.observe(el));
    </script>
</body>
</html>
