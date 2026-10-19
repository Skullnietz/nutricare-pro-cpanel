<?php
declare(strict_types=1);
require_once __DIR__ . '/db_connection.php';

$pdo = getDB();
$mensajeExito = '';
$linkWhatsAppCita = '';
$turnoGenerado = '';
$mensajeError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'agendar_web') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $mensajeError = 'Error de validación de seguridad. Por favor recarga la página.';
    } else {
        $nombre = trim($_POST['nombre_prospecto'] ?? '');
        $telefono = trim($_POST['telefono_prospecto'] ?? '');
        $email = trim($_POST['email_prospecto'] ?? '');
        $nutriologoId = (int)($_POST['nutriologo_id'] ?? 1);
        $servicioId = (int)($_POST['servicio_id'] ?? 1);
        $fechaCita = $_POST['fecha_cita'] ?? date('Y-m-d', strtotime('+1 day'));
        $horaInicio = ($_POST['hora_inicio'] ?? '10:00') . ':00';
        $motivo = trim($_POST['motivo_notas'] ?? '');

        if ($nombre === '' || $telefono === '') {
            $mensajeError = 'Por favor ingresa tu nombre completo y teléfono de contacto.';
        } else {
            $stmtServ = $pdo->prepare("SELECT * FROM catalogo_servicios WHERE id = ?");
            $stmtServ->execute([$servicioId]);
            $servicio = $stmtServ->fetch();

            $stmtDoc = $pdo->prepare("SELECT * FROM usuarios WHERE id = ?");
            $stmtDoc->execute([$nutriologoId]);
            $doctor = $stmtDoc->fetch();

            $areaCita = $servicio['area'] ?? 'nutricion';
            if ($areaCita === 'paquete') {
                $areaCita = ((int)($servicio['incluye_checkup'] ?? 0) === 1) ? 'checkup' : 'psiconutricion';
            }
            $requiereAyuno = ($areaCita === 'checkup' || (int)($servicio['incluye_checkup'] ?? 0) === 1) ? 1 : 0;
            $prefijo = match($areaCita) {
                'psicologia' => 'P-',
                'psiconutricion' => 'PN-',
                'checkup' => 'CK-',
                default => 'N-'
            };
            $turnoGenerado = $prefijo . rand(10, 99);
            $horaFin = date('H:i:s', strtotime($horaInicio . ' +50 minutes'));
            $costo = (float)($servicio['precio'] ?? 950.00);
            $consultorio = $doctor['consultorio_asignado'] ?? 'Consultorio 1';

            $stmtInsert = $pdo->prepare("INSERT INTO citas (turno_sala, paciente_id, nutriologo_id, servicio_id, area_cita, consultorio, nombre_prospecto, telefono_prospecto, email_prospecto, fecha_cita, hora_inicio, hora_fin, tipo_cita, origen, estado, requiere_ayuno, costo_estimado, pagado, motivo_notas) VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'primera_vez', 'landing_web', 'pendiente_confirmar', ?, ?, 0, ?)");
            $stmtInsert->execute([
                $turnoGenerado,
                $nutriologoId,
                $servicioId,
                $areaCita,
                $consultorio,
                $nombre,
                $telefono,
                $email,
                $fechaCita,
                $horaInicio,
                $horaFin,
                $requiereAyuno,
                $costo,
                $motivo
            ]);

            $nombreDoc = $doctor['nombre'] ?? 'Especialista';
            $nombreServicio = $servicio['nombre'] ?? 'Consulta Clínica';
            $mensajeExito = "Tu solicitud de cita para {$nombreServicio} fue registrada con el turno {$turnoGenerado}.";
            $linkWhatsAppCita = generarLinkWhatsApp($telefono, $nombre, $fechaCita, substr($horaInicio, 0, 5), $nombreDoc, $nombreServicio, (bool)$requiereAyuno);
        }
    }
}

$especialistas = $pdo->query("SELECT * FROM usuarios WHERE rol IN ('nutriologo','psicologo') AND activo = 1 ORDER BY id ASC")->fetchAll();
$servicios = $pdo->query("SELECT * FROM catalogo_servicios WHERE activo = 1 ORDER BY id ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(CLINIC_NAME) ?> | Nutrición, Psicología y Checkups</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<?php renderPublicNav(); ?>

<!-- HERO NATURAL Y EDITORIAL -->
<section class="hero-editorial">
    <div class="container">
        <div class="hero-grid">
            <div>
                <span class="hero-eyebrow">Clínica Interdisciplinaria en Ciudad de México</span>
                <h1 class="hero-title">Salud metabólica y bienestar emocional en un mismo consultorio.</h1>
                <p class="hero-lead">
                    Integramos nutrición clínica, psicoterapia cognitivo-conductual y checkups metabólicos para tratar tu salud desde la raíz, con atención cercana y basada en evidencia.
                </p>
                <div class="hero-actions">
                    <a href="#agendar" class="btn btn-primary btn-lg">Agendar una cita</a>
                    <a href="#especialidades" class="btn btn-outline btn-lg">Conocer áreas clínicas</a>
                </div>
                <div class="hero-trust-row">
                    <div class="trust-metric">
                        <strong>4 Especialidades</strong>
                        <span>Nutrición, Psicología, Psiconutrición y Checkups</span>
                    </div>
                    <div class="trust-metric">
                        <strong>InBody + ISAK II</strong>
                        <span>Composición corporal de precisión</span>
                    </div>
                    <div class="trust-metric">
                        <strong>CFDI 4.0</strong>
                        <span>Facturación médica deducible al instante</span>
                    </div>
                </div>
            </div>

            <div class="hero-visual">
                <div class="hero-photo-frame">
                    <img src="https://images.unsplash.com/photo-1576091160550-2173dba999ef?auto=format&fit=crop&w=1000&q=80" alt="Consulta médica y nutricional en Clínica NutriMind">
                </div>
                <div class="hero-floating-card">
                    <img src="<?= htmlspecialchars(getFotoEspecialista(1)) ?>" alt="Dra. Sofía Mendoza">
                    <div>
                        <span class="badge badge-teal" style="margin-bottom:4px;">Atención Presencial</span>
                        <strong style="display:block;font-size:0.92rem;color:var(--text);">Consultorios equipados</strong>
                        <span style="font-size:0.8rem;color:var(--text-muted);">Bioimpedancia, toma de biomarcadores y psicoterapia privada.</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ESPECIALIDADES CON FOTOGRAFÍA REAL -->
<section id="especialidades" class="section-block">
    <div class="container">
        <div class="section-heading">
            <span class="eyebrow">Nuestras Áreas Clínicas</span>
            <h2>Atención especializada para cada etapa de tu salud</h2>
            <p>Abordamos desde objetivos de composición corporal y rendimiento deportivo hasta ansiedad alimentaria y prevención metabólica.</p>
        </div>

        <div class="grid-4">
            <article class="specialty-card">
                <img class="specialty-img" src="https://images.unsplash.com/photo-1490645935967-10de6ba17061?auto=format&fit=crop&w=600&q=80" alt="Nutrición Clínica y Deportiva">
                <div class="specialty-body">
                    <span class="badge badge-teal" style="width:fit-content;margin-bottom:10px;">Nutrición</span>
                    <h3>Nutrición Clínica y Deportiva</h3>
                    <p>Evaluación InBody, plicometría ISAK II y planes de alimentación personalizados adaptados a tu estilo de vida.</p>
                    <div class="specialty-meta">
                        <strong>Desde $750 MXN</strong>
                        <a href="#agendar" class="btn btn-outline btn-xs">Agendar</a>
                    </div>
                </div>
            </article>

            <article class="specialty-card">
                <img class="specialty-img" src="https://images.unsplash.com/photo-1573497620053-ea5300f94f21?auto=format&fit=crop&w=600&q=80" alt="Psicología Clínica TCC">
                <div class="specialty-body">
                    <span class="badge badge-purple" style="width:fit-content;margin-bottom:10px;">Psicología</span>
                    <h3>Psicoterapia Clínica (TCC)</h3>
                    <p>Espacio confidencial para el manejo de ansiedad, estrés laboral, regulación emocional, duelo e higiene del sueño.</p>
                    <div class="specialty-meta">
                        <strong>Desde $850 MXN</strong>
                        <a href="#agendar" class="btn btn-outline btn-xs">Agendar</a>
                    </div>
                </div>
            </article>

            <article class="specialty-card">
                <img class="specialty-img" src="https://images.unsplash.com/photo-1544367567-0f2fcb009e0b?auto=format&fit=crop&w=600&q=80" alt="Psiconutrición Interdisciplinaria">
                <div class="specialty-body">
                    <span class="badge badge-amber" style="width:fit-content;margin-bottom:10px;">Interdisciplinario</span>
                    <h3>Psiconutrición y Conducta Alimentaria</h3>
                    <p>Tratamiento conjunto entre nutriólogo y psicólogo para hambre emocional, atracones y reconciliación con la comida.</p>
                    <div class="specialty-meta">
                        <strong>$1,450 MXN</strong>
                        <a href="#agendar" class="btn btn-outline btn-xs">Agendar</a>
                    </div>
                </div>
            </article>

            <article class="specialty-card">
                <img class="specialty-img" src="https://images.unsplash.com/photo-1579684385127-1ef15d508118?auto=format&fit=crop&w=600&q=80" alt="Checkups Médicos y Metabólicos">
                <div class="specialty-body">
                    <span class="badge badge-blue" style="width:fit-content;margin-bottom:10px;">Diagnóstico</span>
                    <h3>Checkups Preventivos 360°</h3>
                    <p>Valoración de signos vitales, resistencia a la insulina (HOMA-IR), perfil lipídico, tiroides y composición corporal.</p>
                    <div class="specialty-meta">
                        <strong>Desde $1,650 MXN</strong>
                        <a href="#agendar" class="btn btn-outline btn-xs">Agendar</a>
                    </div>
                </div>
            </article>
        </div>
    </div>
</section>

<!-- CHECKUPS PREVENTIVOS (VISUAL Y CONCISO) -->
<section id="checkups" class="section-block alt">
    <div class="container">
        <div class="grid-2" style="align-items:center;gap:40px;">
            <div>
                <span class="eyebrow" style="color:var(--primary);font-weight:700;font-size:0.78rem;text-transform:uppercase;letter-spacing:0.08em;">Diagnóstico Preventivo</span>
                <h2 style="font-size:2rem;margin:8px 0 14px;">Checkups clínicos para conocer cómo está funcionando tu cuerpo</h2>
                <p style="color:var(--text-muted);margin-bottom:22px;">
                    Antes de iniciar un tratamiento a ciegas, medimos tus indicadores metabólicos, cardiovasculares y de descanso para diseñar un plan verdaderamente preciso.
                </p>

                <div style="display:flex;flex-direction:column;gap:14px;">
                    <div class="card card-compact" style="border-left:4px solid var(--primary);">
                        <div class="flex-between">
                            <strong>Checkup Integral Mente-Cuerpo 360°</strong>
                            <span class="badge badge-teal">$2,450 MXN</span>
                        </div>
                        <p class="text-sm text-muted" style="margin-top:4px;">Signos vitales, glucosa, insulina (HOMA-IR), perfil de lípidos, tiroides, InBody y evaluación de estrés.</p>
                    </div>

                    <div class="card card-compact" style="border-left:4px solid var(--accent);">
                        <div class="flex-between">
                            <strong>Checkup Cardiometabólico e Insulina</strong>
                            <span class="badge badge-amber">$1,650 MXN</span>
                        </div>
                        <p class="text-sm text-muted" style="margin-top:4px;">Enfocado en resistencia a la insulina, HbA1c, colesterol, triglicéridos y grasa visceral.</p>
                    </div>

                    <div class="card card-compact" style="border-left:4px solid var(--info);">
                        <div class="flex-between">
                            <strong>Checkup Deportivo + Plicometría ISAK II</strong>
                            <span class="badge badge-blue">$1,800 MXN</span>
                        </div>
                        <p class="text-sm text-muted" style="margin-top:4px;">Evaluación de 4 pliegues cutáneos, masa muscular esquelética, ferritina, vitamina D y gasto energético.</p>
                    </div>
                </div>
            </div>

            <div>
                <div class="card" style="padding:14px;">
                    <img src="https://images.unsplash.com/photo-1581595220892-b0739db3ba8c?auto=format&fit=crop&w=900&q=80" alt="Evaluación diagnóstica y checkup metabólico" style="width:100%;height:380px;object-fit:cover;border-radius:10px;">
                    <div class="flex-between" style="padding:14px 8px 4px;">
                        <div>
                            <strong style="font-size:0.95rem;">Entrega de resultados el mismo día</strong>
                            <div class="text-xs text-muted">Recibe tu reporte impreso con semáforo metabólico y plan de acción.</div>
                        </div>
                        <a href="#agendar" class="btn btn-primary btn-sm">Reservar Checkup</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- EQUIPO MÉDICO CON RETRATOS REALES -->
<section id="equipo" class="section-block">
    <div class="container">
        <div class="section-heading">
            <span class="eyebrow">Cuerpo Clínico</span>
            <h2>Especialistas certificados con cédula profesional</h2>
            <p>Un equipo cálido y cercano que trabaja en comunicación constante para cuidar tu salud física y mental.</p>
        </div>

        <div class="grid-4">
            <?php foreach ($especialistas as $esp): ?>
                <div class="doctor-card">
                    <img class="doctor-photo" src="<?= htmlspecialchars(getFotoEspecialista((int)$esp['id'])) ?>" alt="<?= htmlspecialchars($esp['nombre']) ?>">
                    <div class="doctor-info">
                        <span class="badge <?= $esp['rol'] === 'psicologo' ? 'badge-purple' : 'badge-teal' ?>"><?= htmlspecialchars(ucfirst($esp['area_clinica'])) ?></span>
                        <h3><?= htmlspecialchars($esp['nombre']) ?></h3>
                        <div class="doctor-role"><?= htmlspecialchars($esp['titulo']) ?></div>
                        <p class="text-xs text-muted" style="margin-bottom:14px;"><?= htmlspecialchars($esp['especialidad']) ?></p>
                        <div style="border-top:1px solid var(--border);padding-top:10px;display:flex;justify-content:space-between;align-items:center;font-size:0.8rem;">
                            <span class="text-muted"><?= htmlspecialchars($esp['cedula_profesional']) ?></span>
                            <strong>1ª vez: $<?= number_format((float)$esp['costo_primera_vez'], 0) ?></strong>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ARANCELES Y PAQUETES EN TABLA LIMPIA -->
<section id="precios" class="section-block alt">
    <div class="container">
        <div class="section-heading">
            <span class="eyebrow">Aranceles Transparentes</span>
            <h2>Consultas individuales y paquetes clínicos</h2>
            <p>Todos nuestros servicios médicos permiten emisión de factura CFDI 4.0 deducible de impuestos.</p>
        </div>

        <div class="card" style="max-width:920px;margin:0 auto;">
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Servicio o Paquete</th>
                            <th>Área</th>
                            <th>Duración</th>
                            <th>Inversión</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($servicios as $s): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($s['nombre']) ?></strong>
                                    <div class="text-xs text-muted"><?= htmlspecialchars($s['descripcion']) ?></div>
                                </td>
                                <td><span class="badge badge-slate"><?= strtoupper(htmlspecialchars($s['area'])) ?></span></td>
                                <td class="text-sm text-muted"><?= (int)$s['duracion_minutos'] ?> min</td>
                                <td><strong style="font-size:1rem;color:var(--primary);">$<?= number_format((float)$s['precio'], 2) ?></strong></td>
                                <td class="text-right">
                                    <a href="#agendar" onclick="document.getElementById('select-servicio').value='<?= (int)$s['id'] ?>'" class="btn btn-outline btn-xs">Seleccionar</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>

<!-- AGENDAR CITA -->
<section id="agendar" class="section-block">
    <div class="container">
        <?php if ($mensajeExito): ?>
            <div class="alert alert-success" style="max-width:880px;margin:0 auto 24px;">
                <div>
                    <strong><?= htmlspecialchars($mensajeExito) ?></strong>
                    <div class="text-xs">Nuestro equipo de recepción ya tiene tu registro en sala de espera.</div>
                </div>
                <?php if ($linkWhatsAppCita): ?>
                    <a href="<?= htmlspecialchars($linkWhatsAppCita) ?>" target="_blank" class="btn btn-primary btn-sm">Confirmar por WhatsApp</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($mensajeError): ?>
            <div class="alert alert-danger" style="max-width:880px;margin:0 auto 24px;"><?= htmlspecialchars($mensajeError) ?></div>
        <?php endif; ?>

        <div class="grid-2" style="max-width:980px;margin:0 auto;align-items:stretch;">
            <div class="card" style="background:var(--bg-sidebar);color:#fff;border:none;display:flex;flex-direction:column;justify-content:space-between;">
                <div>
                    <span class="badge" style="background:rgba(255,255,255,0.14);color:#fff;margin-bottom:14px;">Recepción y Citas</span>
                    <h2 style="color:#fff;font-size:1.75rem;margin-bottom:12px;">Reserva tu espacio en consultorio</h2>
                    <p style="color:rgba(255,255,255,0.78);font-size:0.92rem;margin-bottom:24px;">
                        Al registrar tu cita recibirás tu código de turno y las indicaciones previas (como horas de ayuno en caso de realizar un Checkup).
                    </p>
                </div>

                <img src="https://images.unsplash.com/photo-1519494026892-80bbd2d6fd0d?auto=format&fit=crop&w=700&q=80" alt="Recepción y sala de espera de la clínica" style="width:100%;height:200px;object-fit:cover;border-radius:10px;margin-bottom:20px;">

                <div style="font-size:0.84rem;color:rgba(255,255,255,0.8);display:flex;flex-direction:column;gap:6px;">
                    <div><strong>Dirección:</strong> <?= htmlspecialchars(CLINIC_ADDRESS) ?></div>
                    <div><strong>Teléfono directo:</strong> <?= htmlspecialchars(CLINIC_PHONE) ?></div>
                    <div><strong>Horario:</strong> Lunes a Sábado de 8:00 a 20:00 hrs</div>
                </div>
            </div>

            <div class="card">
                <h3 style="margin-bottom:16px;">Datos para tu cita</h3>
                <form method="POST" action="#agendar">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                    <input type="hidden" name="accion" value="agendar_web">

                    <div class="form-group">
                        <label>Nombre completo *</label>
                        <input type="text" name="nombre_prospecto" class="form-control" placeholder="Ej. Mariana Fernández López" required>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Teléfono / WhatsApp *</label>
                            <input type="tel" name="telefono_prospecto" class="form-control" placeholder="10 dígitos" required>
                        </div>
                        <div class="form-group">
                            <label>Correo electrónico</label>
                            <input type="email" name="email_prospecto" class="form-control" placeholder="correo@ejemplo.com">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Servicio o Checkup *</label>
                        <select name="servicio_id" id="select-servicio" class="form-control" required>
                            <?php foreach ($servicios as $s): ?>
                                <option value="<?= (int)$s['id'] ?>">
                                    <?= htmlspecialchars($s['nombre']) ?> — $<?= number_format((float)$s['precio'], 0) ?> MXN
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Especialista *</label>
                        <select name="nutriologo_id" class="form-control" required>
                            <?php foreach ($especialistas as $esp): ?>
                                <option value="<?= (int)$esp['id'] ?>">
                                    <?= htmlspecialchars($esp['nombre']) ?> (<?= htmlspecialchars(ucfirst($esp['area_clinica'])) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Fecha deseada *</label>
                            <input type="date" name="fecha_cita" class="form-control" value="<?= date('Y-m-d') ?>" min="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Horario *</label>
                            <select name="hora_inicio" class="form-control" required>
                                <option value="08:30">08:30 hrs (Ideal Checkup)</option>
                                <option value="09:30">09:30 hrs</option>
                                <option value="10:30">10:30 hrs</option>
                                <option value="11:30">11:30 hrs</option>
                                <option value="12:30">12:30 hrs</option>
                                <option value="16:00">16:00 hrs</option>
                                <option value="17:00">17:00 hrs</option>
                                <option value="18:00">18:00 hrs</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Motivo de consulta (opcional)</label>
                        <textarea name="motivo_notas" class="form-control" rows="2" placeholder="Cuéntanos brevemente tu objetivo o motivo de consulta..."></textarea>
                    </div>

                    <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;padding:12px;">
                        Confirmar solicitud de cita
                    </button>
                </form>
            </div>
        </div>
    </div>
</section>

<footer class="public-footer no-print">
    <div class="container flex-between" style="flex-wrap:wrap;gap:14px;">
        <div>
            <strong><?= htmlspecialchars(CLINIC_NAME) ?></strong> · <?= htmlspecialchars(CLINIC_LEGAL_NAME) ?> (RFC: <?= htmlspecialchars(CLINIC_RFC) ?>)
        </div>
        <div class="flex-gap">
            <a href="recepcion.php">Mostrador</a>
            <span>·</span>
            <a href="consulta.php">Consultorios</a>
            <span>·</span>
            <a href="facturacion.php">Facturación</a>
            <span>·</span>
            <a href="administracion.php">Administración</a>
        </div>
    </div>
</footer>

</body>
</html>
