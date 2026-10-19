<?php
/**
 * NutriMind & Care Pro — Núcleo de Conexión Dual (MySQL cPanel + Auto-SQLite Local),
 * Seguridad CSRF, Fórmulas Clínicas (Nutrición ISAK + Psicometría PHQ-9/GAD-7/BES + Checkup HOMA-IR)
 * y Catálogos Fiscales SAT CFDI 4.0.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
date_default_timezone_set('America/Mexico_City');

define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'tuusuario_nutricare_db');
define('DB_USER', getenv('DB_USER') ?: 'tuusuario_admin');
define('DB_PASS', getenv('DB_PASS') ?: 'TuPasswordSeguro2026!');
define('CLINIC_NAME', 'NutriMind & Care Pro — Nutrición, Psicología y Checkups Integrales');
define('CLINIC_RFC', 'NMC2601158A9');
define('CLINIC_REGIMEN', '601 - General de Ley Personas Morales');
define('CLINIC_CP', '03100');
define('CLINIC_ADDRESS', 'Av. Insurgentes Sur 1450, Suite 402, Col. Del Valle, CDMX');
define('CLINIC_PHONE', '55 4433 2211');

function getDB(): PDO {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 2,
        ]);
        return $pdo;
    } catch (Throwable $e) {
        $sqlitePath = __DIR__ . '/nutrimind_local.sqlite';
        $needsSeed = !file_exists($sqlitePath) || filesize($sqlitePath) < 1024;
        $pdo = new PDO('sqlite:' . $sqlitePath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON;');
        if ($needsSeed) {
            inicializarSQLiteLocal($pdo);
        }
        return $pdo;
    }
}

function inicializarSQLiteLocal(PDO $pdo): void {
    $hoy = date('Y-m-d');
    $ayer = date('Y-m-d', strtotime('-1 day'));
    $hace2 = date('Y-m-d', strtotime('-2 days'));
    $hace3 = date('Y-m-d', strtotime('-3 days'));
    $hace5 = date('Y-m-d', strtotime('-5 days'));
    $hace14 = date('Y-m-d', strtotime('-14 days'));
    $hace24 = date('Y-m-d', strtotime('-24 days'));
    $hace30 = date('Y-m-d', strtotime('-30 days'));
    $mas4 = date('Y-m-d', strtotime('+4 days'));
    $mas7 = date('Y-m-d', strtotime('+7 days'));
    $mas10 = date('Y-m-d', strtotime('+10 days'));
    $mas14 = date('Y-m-d', strtotime('+14 days'));

    $schema = <<<SQL
CREATE TABLE IF NOT EXISTS usuarios (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nombre TEXT NOT NULL,
    titulo TEXT NOT NULL,
    especialidad TEXT DEFAULT NULL,
    cedula_profesional TEXT DEFAULT NULL,
    email TEXT NOT NULL UNIQUE,
    telefono TEXT DEFAULT NULL,
    password_hash TEXT NOT NULL,
    rol TEXT NOT NULL DEFAULT 'nutriologo',
    area_clinica TEXT NOT NULL DEFAULT 'nutricion',
    consultorio_asignado TEXT DEFAULT 'Consultorio 1',
    color_agenda TEXT DEFAULT '#0d9488',
    costo_primera_vez REAL DEFAULT 950.00,
    costo_seguimiento REAL DEFAULT 750.00,
    porcentaje_comision REAL DEFAULT 70.00,
    activo INTEGER DEFAULT 1,
    creado_en TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS catalogo_servicios (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    codigo TEXT NOT NULL UNIQUE,
    nombre TEXT NOT NULL,
    area TEXT NOT NULL,
    descripcion TEXT DEFAULT NULL,
    precio REAL NOT NULL,
    sesiones_nutri_incluidas INTEGER DEFAULT 0,
    sesiones_psico_incluidas INTEGER DEFAULT 0,
    incluye_checkup INTEGER DEFAULT 0,
    duracion_minutos INTEGER DEFAULT 50,
    clave_prod_serv_sat TEXT DEFAULT '85121800',
    clave_unidad_sat TEXT DEFAULT 'E48',
    exento_iva INTEGER DEFAULT 1,
    activo INTEGER DEFAULT 1
);

CREATE TABLE IF NOT EXISTS pacientes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    folio_expediente TEXT NOT NULL UNIQUE,
    nutriologo_id INTEGER DEFAULT NULL,
    psicologo_id INTEGER DEFAULT NULL,
    modalidad_atencion TEXT NOT NULL DEFAULT 'psiconutricion_integral',
    nombre_completo TEXT NOT NULL,
    fecha_nacimiento TEXT NOT NULL,
    sexo TEXT NOT NULL DEFAULT 'F',
    telefono_whatsapp TEXT NOT NULL,
    email TEXT DEFAULT NULL,
    ocupacion TEXT DEFAULT NULL,
    contacto_emergencia TEXT DEFAULT NULL,
    estatus_cartera TEXT NOT NULL DEFAULT 'activo',
    nombre_paquete_activo TEXT DEFAULT NULL,
    sesiones_disponibles_nutri INTEGER DEFAULT 0,
    sesiones_disponibles_psico INTEGER DEFAULT 0,
    checkups_disponibles INTEGER DEFAULT 0,
    saldo_pendiente REAL DEFAULT 0.00,
    total_historico_pagado REAL DEFAULT 0.00,
    ultima_visita TEXT DEFAULT NULL,
    proxima_cita_sugerida TEXT DEFAULT NULL,
    rfc TEXT DEFAULT 'XAXX010101000',
    razon_social TEXT DEFAULT NULL,
    regimen_fiscal TEXT DEFAULT '612',
    codigo_postal_fiscal TEXT DEFAULT NULL,
    uso_cfdi TEXT DEFAULT 'D01',
    email_facturacion TEXT DEFAULT NULL,
    objetivo_principal TEXT NOT NULL,
    motivo_psicologico TEXT DEFAULT NULL,
    antecedentes_heredofamiliares TEXT DEFAULT NULL,
    antecedentes_patologicos TEXT DEFAULT NULL,
    alergias_intolerancias TEXT DEFAULT NULL,
    medicamentos_suplementos TEXT DEFAULT NULL,
    nivel_actividad_fisica TEXT DEFAULT 'ligero',
    recordatorio_24h TEXT DEFAULT NULL,
    notas_crm_seguimiento TEXT DEFAULT NULL,
    creado_en TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS citas (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    turno_sala TEXT NOT NULL DEFAULT 'T-01',
    paciente_id INTEGER DEFAULT NULL,
    nutriologo_id INTEGER NOT NULL,
    servicio_id INTEGER DEFAULT NULL,
    area_cita TEXT NOT NULL DEFAULT 'nutricion',
    consultorio TEXT DEFAULT 'Consultorio 1',
    nombre_prospecto TEXT DEFAULT NULL,
    telefono_prospecto TEXT DEFAULT NULL,
    email_prospecto TEXT DEFAULT NULL,
    fecha_cita TEXT NOT NULL,
    hora_inicio TEXT NOT NULL,
    hora_fin TEXT NOT NULL,
    tipo_cita TEXT NOT NULL DEFAULT 'seguimiento',
    origen TEXT NOT NULL DEFAULT 'recepcion',
    estado TEXT NOT NULL DEFAULT 'confirmada',
    requiere_ayuno INTEGER DEFAULT 0,
    hora_llegada_sala TEXT DEFAULT NULL,
    hora_inicio_consulta TEXT DEFAULT NULL,
    hora_fin_consulta TEXT DEFAULT NULL,
    costo_estimado REAL DEFAULT 850.00,
    pagado INTEGER DEFAULT 0,
    motivo_notas TEXT DEFAULT NULL,
    creado_en TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS consultas_antropometria (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    paciente_id INTEGER NOT NULL,
    nutriologo_id INTEGER NOT NULL,
    fecha_consulta TEXT NOT NULL,
    peso_kg REAL NOT NULL,
    talla_cm REAL NOT NULL,
    imc REAL NOT NULL,
    porcentaje_grasa REAL DEFAULT NULL,
    porcentaje_musculo REAL DEFAULT NULL,
    masa_grasa_kg REAL DEFAULT NULL,
    masa_magra_kg REAL DEFAULT NULL,
    grasa_visceral INTEGER DEFAULT NULL,
    cintura_cm REAL DEFAULT NULL,
    cadera_cm REAL DEFAULT NULL,
    icc REAL DEFAULT NULL,
    ict REAL DEFAULT NULL,
    pliegue_tricipital_mm REAL DEFAULT NULL,
    pliegue_bicipital_mm REAL DEFAULT NULL,
    pliegue_subescapular_mm REAL DEFAULT NULL,
    pliegue_suprailiaco_mm REAL DEFAULT NULL,
    suma_4_pliegues_mm REAL DEFAULT NULL,
    formula_usada TEXT DEFAULT 'Mifflin-St Jeor',
    tmb_kcal INTEGER NOT NULL,
    get_kcal INTEGER NOT NULL,
    meta_kcal INTEGER NOT NULL,
    pct_proteina INTEGER DEFAULT 25,
    pct_carbohidratos INTEGER DEFAULT 45,
    pct_lipidos INTEGER DEFAULT 30,
    diagnostico_nutricional TEXT DEFAULT NULL,
    creado_en TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS planes_alimentacion (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    paciente_id INTEGER NOT NULL,
    nutriologo_id INTEGER NOT NULL,
    nombre_plan TEXT NOT NULL,
    kcal_objetivo INTEGER NOT NULL,
    proteina_g INTEGER NOT NULL,
    carbohidratos_g INTEGER NOT NULL,
    lipidos_g INTEGER NOT NULL,
    agua_ml INTEGER DEFAULT 2500,
    eq_verduras REAL DEFAULT 4.0,
    eq_frutas REAL DEFAULT 3.0,
    eq_cereales_sg REAL DEFAULT 5.0,
    eq_leguminosas REAL DEFAULT 1.0,
    eq_aoa_bajo REAL DEFAULT 6.0,
    eq_leche_semi REAL DEFAULT 1.0,
    eq_grasas_sp REAL DEFAULT 4.0,
    eq_grasas_cp REAL DEFAULT 2.0,
    menu_desayuno TEXT NOT NULL,
    menu_colacion_1 TEXT DEFAULT NULL,
    menu_comida TEXT NOT NULL,
    menu_colacion_2 TEXT DEFAULT NULL,
    menu_cena TEXT NOT NULL,
    recomendaciones_generales TEXT DEFAULT NULL,
    creado_en TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS consultas_psicologia (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    paciente_id INTEGER NOT NULL,
    psicologo_id INTEGER NOT NULL,
    fecha_sesion TEXT NOT NULL,
    numero_sesion INTEGER DEFAULT 1,
    modalidad_sesion TEXT DEFAULT 'psicoterapia_individual',
    enfoque_terapeutico TEXT DEFAULT 'Cognitivo-Conductual (TCC)',
    phq9_depresion INTEGER DEFAULT 0,
    gad7_ansiedad INTEGER DEFAULT 0,
    bes_atracon INTEGER DEFAULT 0,
    nivel_estres INTEGER DEFAULT 5,
    calidad_sueno_hrs REAL DEFAULT 7.0,
    riesgo_clinico TEXT DEFAULT 'bajo',
    estado_mental_examen TEXT DEFAULT NULL,
    notas_soap TEXT NOT NULL,
    conducta_alimentaria_emocional TEXT DEFAULT NULL,
    tareas_terapeuticas TEXT DEFAULT NULL,
    acuerdos_interdisciplinarios TEXT DEFAULT NULL,
    creado_en TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS checkups_clinicos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    folio_checkup TEXT NOT NULL UNIQUE,
    paciente_id INTEGER NOT NULL,
    especialista_id INTEGER NOT NULL,
    fecha_checkup TEXT NOT NULL,
    tipo_checkup TEXT NOT NULL DEFAULT 'Checkup Integral Mente-Cuerpo 360°',
    presion_sistolica INTEGER DEFAULT 118,
    presion_diastolica INTEGER DEFAULT 76,
    frecuencia_cardiaca INTEGER DEFAULT 68,
    temperatura_c REAL DEFAULT 36.5,
    spo2_oxigenacion INTEGER DEFAULT 98,
    glucosa_ayuno_mg REAL DEFAULT NULL,
    insulina_basal_uui REAL DEFAULT NULL,
    indice_homa_ir REAL DEFAULT NULL,
    hba1c_pct REAL DEFAULT NULL,
    colesterol_total_mg REAL DEFAULT NULL,
    trigliceridos_mg REAL DEFAULT NULL,
    hdl_mg REAL DEFAULT NULL,
    ldl_mg REAL DEFAULT NULL,
    tsh_tiroides REAL DEFAULT NULL,
    cortisol_matutino REAL DEFAULT NULL,
    vitamina_d_ng REAL DEFAULT NULL,
    semaforo_metabolico TEXT DEFAULT 'optimo',
    semaforo_psicoemocional TEXT DEFAULT 'optimo',
    score_salud_integral INTEGER DEFAULT 85,
    interpretacion_biomarcadores TEXT DEFAULT NULL,
    plan_accion_checkup TEXT DEFAULT NULL,
    creado_en TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS pagos_caja (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    folio_recibo TEXT NOT NULL UNIQUE,
    cita_id INTEGER DEFAULT NULL,
    paciente_id INTEGER DEFAULT NULL,
    nutriologo_id INTEGER NOT NULL,
    servicio_id INTEGER DEFAULT NULL,
    categoria_ingreso TEXT NOT NULL DEFAULT 'nutricion',
    concepto TEXT NOT NULL,
    monto REAL NOT NULL,
    descuento REAL DEFAULT 0.00,
    impuestos REAL DEFAULT 0.00,
    total_pagado REAL NOT NULL,
    saldo_pendiente_generado REAL DEFAULT 0.00,
    metodo_pago TEXT NOT NULL DEFAULT 'efectivo',
    referencia_pago TEXT DEFAULT NULL,
    comision_especialista REAL DEFAULT 0.00,
    requiere_factura INTEGER DEFAULT 0,
    estado_factura TEXT DEFAULT 'no_solicitada',
    registrado_por INTEGER NOT NULL,
    fecha_pago TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS facturas_cfdi (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    folio_factura TEXT NOT NULL UNIQUE,
    uuid_fiscal TEXT NOT NULL UNIQUE,
    pago_id INTEGER DEFAULT NULL,
    paciente_id INTEGER NOT NULL,
    rfc_receptor TEXT NOT NULL,
    razon_social_receptor TEXT NOT NULL,
    regimen_fiscal_receptor TEXT NOT NULL DEFAULT '612',
    cp_receptor TEXT NOT NULL,
    uso_cfdi TEXT NOT NULL DEFAULT 'D01',
    clave_prod_serv TEXT NOT NULL DEFAULT '85121800',
    clave_unidad TEXT NOT NULL DEFAULT 'E48',
    concepto TEXT NOT NULL,
    forma_pago_sat TEXT NOT NULL DEFAULT '04',
    metodo_pago_sat TEXT NOT NULL DEFAULT 'PUE',
    subtotal REAL NOT NULL,
    iva REAL DEFAULT 0.00,
    total REAL NOT NULL,
    estado TEXT NOT NULL DEFAULT 'timbrada',
    fecha_emision TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS gastos_operativos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    folio_gasto TEXT NOT NULL UNIQUE,
    categoria TEXT NOT NULL,
    concepto TEXT NOT NULL,
    proveedor TEXT DEFAULT NULL,
    monto REAL NOT NULL,
    metodo_pago TEXT NOT NULL DEFAULT 'transferencia',
    deducible_impuestos INTEGER DEFAULT 1,
    fecha_gasto TEXT NOT NULL,
    registrado_por INTEGER NOT NULL,
    creado_en TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS cortes_caja (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    fecha_corte TEXT NOT NULL,
    turno TEXT DEFAULT 'Día Completo',
    fondo_inicial_efectivo REAL DEFAULT 1000.00,
    ingresos_efectivo REAL NOT NULL DEFAULT 0.00,
    ingresos_tarjeta REAL NOT NULL DEFAULT 0.00,
    ingresos_transferencia REAL NOT NULL DEFAULT 0.00,
    gastos_efectivo REAL NOT NULL DEFAULT 0.00,
    efectivo_esperado REAL NOT NULL DEFAULT 0.00,
    efectivo_contado_fisico REAL NOT NULL DEFAULT 0.00,
    diferencia_arqueo REAL NOT NULL DEFAULT 0.00,
    total_neto_dia REAL NOT NULL DEFAULT 0.00,
    observaciones TEXT DEFAULT NULL,
    cerrado_por INTEGER NOT NULL,
    creado_en TEXT DEFAULT CURRENT_TIMESTAMP
);
SQL;
    $pdo->exec($schema);

    // Seed Usuarios
    $pdo->exec("INSERT INTO usuarios (id, nombre, titulo, especialidad, cedula_profesional, email, telefono, password_hash, rol, area_clinica, consultorio_asignado, color_agenda, costo_primera_vez, costo_seguimiento, porcentaje_comision) VALUES
    (1, 'Dra. Sofía Mendoza', 'L.N. y M.C. en Nutrición Clínica y Checkup Metabólico', 'Nutrición Clínica, Checkups Cardiometabólicos, Diabetes y Salud Hormonal', 'Céd. Prof. 8492014', 'sofia.mendoza@nutrimind.mx', '5512345678', 'hash', 'nutriologo', 'nutricion', 'Consultorio 1 (Nutrición y Checkup)', '#0d9488', 950.00, 750.00, 70.00),
    (2, 'Dr. Alejandro Vega', 'L.N. y Esp. en Nutrición Deportiva (ISAK II)', 'Nutrición Deportiva, Checkup de Rendimiento, Recomposición e Hipertrofia', 'Céd. Prof. 9103842', 'alejandro.vega@nutrimind.mx', '5587654321', 'hash', 'nutriologo', 'nutricion', 'Consultorio 1 (Nutrición y Checkup)', '#2563eb', 1050.00, 800.00, 70.00),
    (3, 'Mtra. Valeria Rojas', 'Lic. y Mtra. en Psicología Clínica y TCC', 'Terapia Cognitivo-Conductual, Ansiedad, Depresión, Regulación Emocional y Estrés', 'Céd. Prof. 7741920', 'valeria.rojas@nutrimind.mx', '5533445566', 'hash', 'psicologo', 'psicologia', 'Consultorio 2 (Psicología)', '#7c3aed', 1000.00, 850.00, 70.00),
    (4, 'Dr. Daniel Herrera', 'Dr. en Psicología de la Salud y Psiconutrición', 'Trastornos de la Conducta Alimentaria (TCA), Hambre Emocional, Atracón y Psicología Bariátrica', 'Céd. Prof. 6839201', 'daniel.herrera@nutrimind.mx', '5566778899', 'hash', 'psicologo', 'psiconutricion', 'Consultorio 3 (Psiconutrición)', '#db2777', 1150.00, 900.00, 75.00),
    (5, 'Laura Gómez', 'Coordinadora de Mostrador, Sala de Espera y Caja', 'Recepción, Triage de Checkups, Cobranza POS y Facturación CFDI 4.0', 'N/A', 'recepcion@nutrimind.mx', '5544332211', 'hash', 'recepcion', 'administracion', 'Mostrador Central', '#475569', 0.00, 0.00, 0.00),
    (6, 'Lic. Carlos Guizar', 'Director General y Administrador Clínico', 'Administración Autónoma, Finanzas, Nómina de Comisiones y Cartera', 'N/A', 'admin@nutrimind.mx', '5599887766', 'hash', 'admin', 'administracion', 'Dirección', '#0f172a', 0.00, 0.00, 0.00);");

    // Seed Catalogo Servicios
    $pdo->exec("INSERT INTO catalogo_servicios (id, codigo, nombre, area, descripcion, precio, sesiones_nutri_incluidas, sesiones_psico_incluidas, incluye_checkup, duracion_minutos, clave_prod_serv_sat, clave_unidad_sat, exento_iva) VALUES
    (1, 'NUT-1A', 'Consulta Nutricional 1ª Vez + InBody/ISAK', 'nutricion', 'Historia clínica, composición corporal, plicometría y plan SMAE personalizado', 950.00, 1, 0, 0, 50, '85121800', 'E48', 1),
    (2, 'NUT-SEG', 'Consulta de Seguimiento Nutricional', 'nutricion', 'Control antropométrico, ajuste calórico y renovación de menú SMAE', 750.00, 1, 0, 0, 40, '85121800', 'E48', 1),
    (3, 'PSI-1A', 'Evaluación Psicológica Inicial (TCC)', 'psicologia', 'Entrevista clínica inicial, tamizaje PHQ-9 / GAD-7 y plan psicoterapéutico', 1000.00, 0, 1, 0, 55, '85121600', 'E48', 1),
    (4, 'PSI-SEG', 'Sesión de Psicoterapia Individual', 'psicologia', 'Sesión psicoterapéutica de seguimiento (Cognitivo-Conductual / Regulación Emocional)', 850.00, 0, 1, 0, 50, '85121600', 'E48', 1),
    (5, 'PSINUT-DUO', 'Consulta Interdisciplinaria de Psiconutrición (TCA / Ansiedad)', 'psiconutricion', 'Abordaje conjunto de relación con la comida, hambre emocional y plan nutricional flexible', 1450.00, 1, 1, 0, 60, '85121600', 'E48', 1),
    (6, 'CHK-360', 'Checkup Integral Mente-Cuerpo 360° (Metabólico + Antropometría + Psicoemocional)', 'checkup', 'Signos vitales, panel metabólico HOMA-IR/Lípidos/Tiroides, InBody ISAK y tamizaje de estrés/ansiedad', 2450.00, 1, 1, 1, 75, '85121800', 'E48', 1),
    (7, 'CHK-MET', 'Checkup Cardiometabólico y Resistencia a la Insulina', 'checkup', 'Evaluación de glucosa, insulina basal, HOMA-IR, HbA1c, perfil de lípidos, presión arterial y grasa visceral', 1650.00, 1, 0, 1, 50, '85121800', 'E48', 1),
    (8, 'CHK-DEP', 'Checkup Deportivo de Alto Rendimiento + Plicometría ISAK II', 'checkup', 'Somatotipo ISAK 4 pliegues, somatocarta, signos vitales en reposo, ferritina, vitamina D y plan energético', 1800.00, 1, 0, 1, 60, '85121800', 'E48', 1),
    (9, 'PAQ-INTEGRAL-4', 'Paquete Mensual Equilibrio Total (Checkup 360° + 4 Nutrición + 4 Psicología)', 'paquete', 'Incluye Checkup inicial + 4 sesiones nutricionales + 4 sesiones psicológicas', 6200.00, 4, 4, 1, 50, '85121600', 'E48', 0),
    (10, 'PAQ-NUTRI-4', 'Paquete Trimestral Nutrición (4 Consultas + ISAK)', 'paquete', 'Paquete de 4 consultas nutricionales prepagadas', 2650.00, 4, 0, 0, 45, '85121800', 'E48', 0),
    (11, 'PAQ-PSICO-4', 'Paquete Mensual Psicoterapia (4 Sesiones TCC)', 'paquete', 'Paquete de 4 sesiones de psicoterapia individual', 3000.00, 0, 4, 0, 50, '85121600', 'E48', 0);");

    // Seed Pacientes
    $pdo->exec("INSERT INTO pacientes (id, folio_expediente, nutriologo_id, psicologo_id, modalidad_atencion, nombre_completo, fecha_nacimiento, sexo, telefono_whatsapp, email, ocupacion, contacto_emergencia, estatus_cartera, nombre_paquete_activo, sesiones_disponibles_nutri, sesiones_disponibles_psico, checkups_disponibles, saldo_pendiente, total_historico_pagado, ultima_visita, proxima_cita_sugerida, rfc, razon_social, regimen_fiscal, codigo_postal_fiscal, uso_cfdi, email_facturacion, objetivo_principal, motivo_psicologico, antecedentes_heredofamiliares, antecedentes_patologicos, alergias_intolerancias, medicamentos_suplementos, nivel_actividad_fisica) VALUES
    (1, 'EXP-2026-001', 1, 4, 'psiconutricion_integral', 'Mariana Fernández López', '1992-05-14', 'F', '5511223344', 'mariana.fdez@email.com', 'Arquitecta Senior', 'Roberto Fernández (Esposo) - 5599001122', 'activo', 'Paquete Mensual Equilibrio Total', 3, 2, 1, 0.00, 7150.00, '$hoy', '$mas7', 'FELM920514AB1', 'MARIANA FERNANDEZ LOPEZ', '612', '03100', 'D01', 'facturas.mariana@email.com', 'Control de resistencia a la insulina y recomposición corporal sin dieta restrictiva', 'Episodios de hambre emocional nocturna y ansiedad laboral alta', 'Madre con DM2, Padre con HTA', 'Resistencia a la insulina diagnosticada hace 8 meses', 'Intolerancia a la lactosa moderada', 'Metformina 500mg c/24h, Inositol 2g, Magnesio', 'moderado'),
    (2, 'EXP-2026-002', 2, 3, 'psiconutricion_integral', 'Jorge Eduardo Ramírez Soto', '1988-11-22', 'M', '5522334455', 'jorge.ramirez@empresa.mx', 'Director Comercial', 'Elena Soto (Madre) - 5588112233', 'activo', 'Checkup Deportivo + Seguimiento', 0, 0, 0, 450.00, 4900.00, '$hoy', '$mas14', 'RASJ8811229K4', 'JORGE EDUARDO RAMIREZ SOTO', '605', '06600', 'D01', 'jorge.ramirez@empresa.mx', 'Pérdida de grasa visceral, hipertrofia muscular y preparación de medio maratón', 'Manejo de estrés ejecutivo, insomnio de conciliación y perfeccionismo', 'Padre con dislipidemia', 'Hipercolesterolemia leve', 'Ninguna conocida', 'Omega 3 2g/día, Creatina monohidratada 5g/día', 'activo'),
    (3, 'EXP-2026-003', 1, 4, 'psiconutricion_integral', 'Camila Torres Villaseñor', '1999-08-03', 'F', '5533445577', 'camila.tv@email.com', 'Diseñadora UX', 'Patricia Villaseñor (Madre) - 5577665544', 'en_riesgo', 'Paquete Mensual Psicoterapia (4 Sesiones TCC)', 0, 1, 0, 850.00, 4100.00, '$hace24', '$hoy', 'TOVC990803H29', 'CAMILA TORRES VILLASENOR', '626', '01000', 'D01', 'camila.tv@email.com', 'Recuperación nutricional, relación saludable con los alimentos y energía diaria', 'Prevención de recaídas en conducta restrictiva/atracón y distorsión de imagen corporal', 'Sin antecedentes relevantes', 'Amenorrea hipotalámica funcional en remisión', 'Alergia al cacahuate', 'Vitamina D3 4000 UI, Hierro aminoquelado', 'ligero'),
    (4, 'EXP-2026-004', 2, NULL, 'checkup_preventivo', 'Andrés Castellanos Mora', '1995-02-19', 'M', '5544556688', 'andres.castellanos@email.com', 'Ingeniero de Software', 'Lucía Mora - 5566554433', 'activo', 'Paquete Trimestral Nutrición (4 Consultas + ISAK)', 2, 0, 1, 0.00, 4450.00, '$hace5', '$mas10', 'CAMA950219L10', 'ANDRES CASTELLANOS MORA', '626', '04100', 'D01', 'andres.castellanos@email.com', 'Checkup deportivo anual y aumento de masa muscular limpia (+4 kg)', NULL, 'Abuelo paterno con cardiopatía', 'Ninguno', 'Ninguna', 'Proteína aislada de suero, Cafeína pre-entreno', 'muy_activo'),
    (5, 'EXP-2026-005', NULL, 3, 'psicologia', 'Patricia Domínguez Ríos', '1983-07-11', 'F', '5566778800', 'patricia.dominguez@email.com', 'Contadora Pública', 'Miguel Ríos - 5511998877', 'activo', 'Sin paquete (Pago por sesión)', 0, 0, 0, 0.00, 2850.00, '$hace3', '$mas4', 'DORP830711P82', 'PATRICIA DOMINGUEZ RIOS', '612', '11560', 'D01', 'patricia.dominguez@email.com', 'Bienestar emocional y manejo de duelo y ansiedad generalizada', 'Trastorno de ansiedad generalizada y agotamiento laboral (Burnout)', 'Madre con trastorno depresivo', 'Hipotiroidismo subclínico controlado', 'Penicilina', 'Levotiroxina 50mcg, Escitalopram 10mg', 'sedentario');");

    // Seed Citas
    $pdo->exec("INSERT INTO citas (id, turno_sala, paciente_id, nutriologo_id, servicio_id, area_cita, consultorio, nombre_prospecto, telefono_prospecto, email_prospecto, fecha_cita, hora_inicio, hora_fin, tipo_cita, origen, estado, requiere_ayuno, hora_llegada_sala, hora_inicio_consulta, costo_estimado, pagado, motivo_notas) VALUES
    (1, 'CK-01', 1, 1, 6, 'checkup', 'Consultorio 1 (Nutrición y Checkup)', NULL, NULL, NULL, '$hoy', '08:30:00', '09:30:00', 'checkup_integral', 'recepcion', 'finalizada', 1, '08:22:00', '08:30:00', 2450.00, 1, 'Checkup Integral 360° (Ayuno 10h verificado + Bioimpedancia + Panel HOMA-IR)'),
    (2, 'PN-01', 1, 4, 5, 'psiconutricion', 'Consultorio 3 (Psiconutrición)', NULL, NULL, NULL, '$hoy', '10:00:00', '11:00:00', 'psiconutricion_integral', 'recepcion', 'en_consulta', 0, '09:50:00', '10:02:00', 1450.00, 1, 'Sesión psiconutrición: revisión emocional del Checkup y hambre emocional'),
    (3, 'P-01', 2, 3, 4, 'psicologia', 'Consultorio 2 (Psicología)', NULL, NULL, NULL, '$hoy', '10:30:00', '11:20:00', 'psicoterapia', 'whatsapp', 'en_sala', 0, '10:18:00', NULL, 850.00, 0, 'Sesión TCC manejo de estrés ejecutivo e higiene del sueño'),
    (4, 'CK-02', 2, 2, 8, 'checkup', 'Consultorio 1 (Nutrición y Checkup)', NULL, NULL, NULL, '$hoy', '11:30:00', '12:30:00', 'checkup_integral', 'recepcion', 'confirmada', 1, NULL, NULL, 1800.00, 0, 'Checkup Deportivo de Alto Rendimiento + Plicometría ISAK II pre-21K'),
    (5, 'PN-02', 3, 4, 4, 'psiconutricion', 'Consultorio 3 (Psiconutrición)', NULL, NULL, NULL, '$hoy', '12:30:00', '13:20:00', 'psicoterapia', 'recepcion', 'por_cobrar', 0, '12:20:00', '12:30:00', 900.00, 0, 'Seguimiento TCA y flexibilidad cognitiva alimentaria'),
    (6, 'W-01', NULL, 3, 3, 'psicologia', 'Consultorio 2 (Psicología)', 'Fernanda Olivares Luna', '5577889900', 'fer.olivares@email.com', '$hoy', '13:30:00', '14:20:00', 'primera_vez', 'landing_web', 'pendiente_confirmar', 0, NULL, NULL, 1000.00, 0, 'Solicitud web: Ansiedad por atracón y evaluación psicológica inicial');");

    // Seed Consultas Antropometria
    $pdo->exec("INSERT INTO consultas_antropometria (paciente_id, nutriologo_id, fecha_consulta, peso_kg, talla_cm, imc, porcentaje_grasa, porcentaje_musculo, masa_grasa_kg, masa_magra_kg, grasa_visceral, cintura_cm, cadera_cm, icc, ict, pliegue_tricipital_mm, pliegue_bicipital_mm, pliegue_subescapular_mm, pliegue_suprailiaco_mm, suma_4_pliegues_mm, formula_usada, tmb_kcal, get_kcal, meta_kcal, pct_proteina, pct_carbohidratos, pct_lipidos, diagnostico_nutricional) VALUES
    (1, 1, '$hace30', 72.4, 163.0, 27.25, 33.8, 26.4, 24.47, 47.93, 8, 86.5, 102.0, 0.85, 0.53, 19.5, 11.0, 18.0, 21.0, 69.5, 'Mifflin-St Jeor', 1412, 2330, 1850, 25, 45, 30, 'Sobrepeso grado I (IMC 27.25 kg/m2) relacionado con desbalance energético y resistencia a la insulina evidenciado por ICT 0.53 y plicometría 69.5 mm.'),
    (1, 1, '$hoy', 69.8, 163.0, 26.27, 31.2, 27.9, 21.78, 48.02, 7, 82.0, 100.5, 0.82, 0.50, 17.0, 9.5, 16.0, 18.5, 61.0, 'Mifflin-St Jeor', 1386, 2287, 1800, 28, 42, 30, 'Evolución favorable: reducción de -2.6 kg de masa grasa con preservación e incremento de masa magra (+0.09 kg) y disminución de 4.5 cm de cintura.'),
    (2, 2, '$hoy', 78.5, 176.0, 25.34, 15.4, 43.2, 12.09, 66.41, 5, 81.0, 96.0, 0.84, 0.46, 8.5, 4.5, 11.0, 10.5, 34.5, 'Katch-McArdle', 1804, 3292, 2950, 25, 50, 25, 'Composición corporal atlética con adecuado porcentaje de masa muscular esquelética. Se ajusta carga de carbohidratos complejos para volumen de carrera.');");

    // Seed Planes Alimentacion
    $pdo->exec("INSERT INTO planes_alimentacion (paciente_id, nutriologo_id, nombre_plan, kcal_objetivo, proteina_g, carbohidratos_g, lipidos_g, agua_ml, eq_verduras, eq_frutas, eq_cereales_sg, eq_leguminosas, eq_aoa_bajo, eq_leche_semi, eq_grasas_sp, eq_grasas_cp, menu_desayuno, menu_colacion_1, menu_comida, menu_colacion_2, menu_cena, recomendaciones_generales) VALUES
    (1, 1, 'Plan Antiinflamatorio de Bajo Índice Glucémico + Saciedad Consciente', 1800, 126, 189, 60, 2600, 5.0, 3.0, 5.0, 1.0, 6.0, 1.0, 4.0, 2.0,
    'Omelette de 2 huevos enteros + 2 claras con espinacas, champiñones y 40g de queso panela. Acompañar con 1 rebanada de pan masa madre tostado con 1/3 de pieza de aguacate Hass y 1 taza de frutos rojos.',
    '1 taza de yogur griego sin azúcar añadido con 10 almendras fileteadas, 1 cdita de semillas de chía y canela en polvo.',
    '150g de salmón o pechuga de pollo a la plancha con hierbas finas + 2/3 taza de quinoa o arroz basmati cocido + 1/2 taza de lentejas + ensalada fresca de hojas verdes con 1 cda de aceite de oliva extra virgen.',
    '1 manzana mediana en gajos con 1 cucharada de crema de almendras o cacahuate natural 100% (pausa de respiración consciente de 2 min antes de comer).',
    '2 tostadas horneadas de maíz con 120g de atún fresco o fajitas de pollo, pico de gallo, nopales asados y 1/3 de pieza de aguacate. Infusión de manzanilla o toronjil 45 min antes de dormir.',
    '1. Practicar escala de hambre-saciedad (1 al 10) antes y después de cada tiempo de comida en conjunto con lo trabajado en Psiconutrición.\n2. Priorizar el orden de ingesta: primero fibra/verduras, luego proteína y grasas saludables, y al final los carbohidratos complejos.\n3. Hidratación fraccionada: 2.6 litros de agua natural a lo largo del día.');");

    // Seed Consultas Psicologia
    $pdo->exec("INSERT INTO consultas_psicologia (paciente_id, psicologo_id, fecha_sesion, numero_sesion, modalidad_sesion, enfoque_terapeutico, phq9_depresion, gad7_ansiedad, bes_atracon, nivel_estres, calidad_sueno_hrs, riesgo_clinico, estado_mental_examen, notas_soap, conducta_alimentaria_emocional, tareas_terapeuticas, acuerdos_interdisciplinarios) VALUES
    (1, 4, '$hace14', 1, 'evaluacion_inicial', 'Psiconutrición / TCC + ACT', 8, 13, 21, 8, 5.5, 'bajo', 'Orientada en las 3 esferas, discurso fluido, afecto ansioso reactivo a exigencias laborales, insight conservado.', 'S: La paciente refiere episodios de urgencia por comer alimentos dulces entre las 20:00 y 22:30 hrs tras jornadas laborales intensas.\nO: Puntaje GAD-7 de 13 (ansiedad moderada) y BES de 21 (ingesta emocional moderada).\nA: Se identifica ciclo de restricción cognitiva diurna seguido de desinhibición nocturna como mecanismo de regulación emocional ante estrés laboral.\nP: Reestructuración cognitiva de pensamientos dicotómicos (todo o nada) respecto a la comida y entrenamiento en Urge Surfing.', 'Identifica detonantes emocionales: cansancio extremo al llegar a casa y postergación de la colación vespertina.', '1. Autorregistro de Hambre Física vs. Hambre Emocional sin juicio crítico.\n2. Pausa de 5 minutos con respiración diafragmática 4-7-8 antes de abrir la alacena por la noche.', 'Coordinación con Dra. Sofía Mendoza para incluir colación vespertina de alta saciedad (proteína + grasa saludable) a las 17:30 hrs.'),
    (1, 4, '$hoy', 2, 'psiconutricion_tca', 'Psiconutrición / TCC + ACT', 5, 8, 12, 5, 7.0, 'bajo', 'Eutímica, motivada, menor tensión somática, reporta descanso reparador.', 'S: Reporta disminución notable de episodios de ansiedad nocturna (de 5 por semana a solo 1 episodio leve que logró gestionar sin culpa).\nO: Descenso clínico significativo en GAD-7 (de 13 a 8) y escala BES (de 21 a 12).\nA: Alta adherencia a la colación estratégica de las 17:30 hrs y desactivación de culpa post-ingesta.\nP: Fortalecer autocompasión, flexibilidad alimentaria en eventos sociales y límites laborales.', 'Reducción del 70% en urgencia por carbohidratos simples nocturnos; mayor conexión con señales interoceptivas de saciedad.', 'Practicar alimentación consciente (Mindful Eating) en al menos 1 comida principal al día sin pantallas.', 'Continuar con esquema flexible SMAE de 1800 kcal sin pesaje obsesivo de alimentos.'),
    (2, 3, '$hoy', 3, 'psicoterapia_individual', 'Cognitivo-Conductual (TCC)', 4, 11, 4, 7, 6.0, 'bajo', 'Alerta, cooperador, lenguaje estructurado, tendencia a la hiperexigencia.', 'S: Refiere dificultad para desconectarse de pendientes directivos después de las 21:00 hrs, afectando recuperación muscular y sueño.\nO: GAD-7 en 11 puntos; sueño promedio de 6.0 hrs.\nA: Hiperactivación fisiológica vespertina por rumiación anticipatoria.\nP: Protocolo de higiene del sueño, ventana de preocupación programada a las 18:30 hrs y relajación muscular progresiva de Jacobson.', 'Sin alteraciones de la conducta alimentaria; adecuada relación con el plan deportivo.', 'Apagado digital 60 minutos antes de dormir y registro de pensamientos automáticos perfeccionistas.', 'Compartir métricas de calidad de sueño con Dr. Alejandro Vega para ajustar horario de suplementación pre-entreno.');");

    // Seed Checkups Clinicos
    $pdo->exec("INSERT INTO checkups_clinicos (id, folio_checkup, paciente_id, especialista_id, fecha_checkup, tipo_checkup, presion_sistolica, presion_diastolica, frecuencia_cardiaca, temperatura_c, spo2_oxigenacion, glucosa_ayuno_mg, insulina_basal_uui, indice_homa_ir, hba1c_pct, colesterol_total_mg, trigliceridos_mg, hdl_mg, ldl_mg, tsh_tiroides, cortisol_matutino, vitamina_d_ng, semaforo_metabolico, semaforo_psicoemocional, score_salud_integral, interpretacion_biomarcadores, plan_accion_checkup) VALUES
    (1, 'CHK-2026-001', 1, 1, '$hoy', 'Checkup Integral Mente-Cuerpo 360°', 116, 74, 66, 36.4, 98, 94.0, 10.2, 2.37, 5.3, 184.0, 128.0, 56.0, 102.0, 2.10, 14.5, 34.0, 'precaucion', 'optimo', 86, 'Mejoría notable en sensibilidad a la insulina: HOMA-IR descendió de 3.45 a 2.37 (meta < 2.0). Perfil lipídico dentro de rangos cardioprotectores (Triglicéridos 128 mg/dL, HDL 56 mg/dL) y cortisol matutino normalizado tras intervención psiconutricional.', 'Mantener suplementación con Inositol 2g + Magnesio, continuar dieta antiinflamatoria SMAE 1800 kcal y repetir Checkup metabólico de control en 12 semanas.'),
    (2, 'CHK-2026-002', 2, 2, '$hoy', 'Checkup Deportivo de Alto Rendimiento + Plicometría ISAK II', 112, 70, 54, 36.3, 99, 86.0, 5.4, 1.15, 5.0, 198.0, 92.0, 64.0, 115.0, 1.85, 19.8, 42.0, 'optimo', 'precaucion', 89, 'Bradicardia fisiológica del deportista (FC 54 lpm), excelente sensibilidad insulínica (HOMA-IR 1.15). Se observa cortisol matutino en límite superior (19.8 ug/dL) correlacionado con sobrecarga de trabajo y sueño de 6h.', 'Optimizar recuperación post-entreno, priorizar protocolo de higiene del sueño TCC y aumentar ingesta de antioxidantes y omega-3.');");

    // Seed Pagos Caja
    $pdo->exec("INSERT INTO pagos_caja (id, folio_recibo, cita_id, paciente_id, nutriologo_id, servicio_id, categoria_ingreso, concepto, monto, descuento, impuestos, total_pagado, saldo_pendiente_generado, metodo_pago, referencia_pago, comision_especialista, requiere_factura, estado_factura, registrado_por, fecha_pago) VALUES
    (1, 'REC-20261019-101', 1, 1, 1, 9, 'paquete', 'Paquete Mensual Equilibrio Total (Checkup 360° + 4 Nutrición + 4 Psicología)', 6200.00, 0.00, 0.00, 6200.00, 0.00, 'tarjeta_credito', 'AUT-884920', 4340.00, 1, 'timbrada', 5, '$hoy 08:55:00'),
    (2, 'REC-20261019-102', 2, 1, 4, 5, 'psiconutricion', 'Consulta Interdisciplinaria de Psiconutrición (Redención Sesión Paquete)', 1450.00, 500.00, 0.00, 950.00, 0.00, 'transferencia', 'SPEI-992014', 712.50, 1, 'timbrada', 5, '$hoy 10:00:00'),
    (3, 'REC-20261019-103', NULL, 2, 2, 8, 'checkup', 'Checkup Deportivo de Alto Rendimiento + ISAK II (Abono parcial)', 1800.00, 0.00, 0.00, 1350.00, 450.00, 'efectivo', 'EFEC-CAJA', 945.00, 0, 'no_solicitada', 5, '$hoy 10:15:00'),
    (4, 'REC-20261019-104', NULL, 5, 3, 4, 'psicologia', 'Sesión de Psicoterapia Individual TCC', 850.00, 0.00, 0.00, 850.00, 0.00, 'tarjeta_debito', 'AUT-441029', 595.00, 1, 'pendiente', 5, '$hoy 11:10:00');");

    // Seed Facturas CFDI
    $pdo->exec("INSERT INTO facturas_cfdi (id, folio_factura, uuid_fiscal, pago_id, paciente_id, rfc_receptor, razon_social_receptor, regimen_fiscal_receptor, cp_receptor, uso_cfdi, clave_prod_serv, clave_unidad, concepto, forma_pago_sat, metodo_pago_sat, subtotal, iva, total, estado, fecha_emision) VALUES
    (1, 'FAC-A001', '550E8400-E29B-41D4-A716-446655440001', 1, 1, 'FELM920514AB1', 'MARIANA FERNANDEZ LOPEZ', '612', '03100', 'D01', '85121800', 'E48', 'Honorarios Médicos, Checkup Integral 360 y Psiconutrición', '04', 'PUE', 6200.00, 0.00, 6200.00, 'timbrada', '$hoy 09:05:00'),
    (2, 'FAC-A002', '661F9511-F30C-52E5-B827-557766550002', 2, 1, 'FELM920514AB1', 'MARIANA FERNANDEZ LOPEZ', '612', '03100', 'D01', '85121600', 'E48', 'Honorarios de Psicología Clínica y Psiconutrición', '03', 'PUE', 950.00, 0.00, 950.00, 'timbrada', '$hoy 10:08:00');");

    // Seed Gastos Operativos
    $pdo->exec("INSERT INTO gastos_operativos (id, folio_gasto, categoria, concepto, proveedor, monto, metodo_pago, deducible_impuestos, fecha_gasto, registrado_por) VALUES
    (1, 'GAS-2026-001', 'renta_consultorio', 'Renta Mensual Suite Clínica (3 Consultorios + Sala de Espera + Área Checkup)', 'Inmobiliaria Médica del Valle S.A. de C.V.', 14500.00, 'transferencia', 1, '$hace5', 6),
    (2, 'GAS-2026-002', 'insumos_clinicos', 'Tiras reactivas checkup, hojas térmicas InBody, plicómetro y cafetería sala de espera', 'Distribuidora Médica Integral', 1850.00, 'efectivo_caja', 1, '$hoy', 5),
    (3, 'GAS-2026-003', 'marketing_ads', 'Campaña Meta Ads + Google Ads (Captación Nutrición, Psicología y Checkups)', 'Meta Platforms / Google México', 3200.00, 'tarjeta_corporativa', 1, '$hace2', 6),
    (4, 'GAS-2026-004', 'laboratorio_checkups', 'Convenio procesamiento de muestras sanguíneas para Checkup 360°', 'Laboratorios Clínicos Asociados', 2400.00, 'transferencia', 1, '$hace3', 6);");

    // Seed Cortes Caja
    $pdo->exec("INSERT INTO cortes_caja (id, fecha_corte, turno, fondo_inicial_efectivo, ingresos_efectivo, ingresos_tarjeta, ingresos_transferencia, gastos_efectivo, efectivo_esperado, efectivo_contado_fisico, diferencia_arqueo, total_neto_dia, observaciones, cerrado_por) VALUES
    (1, '$ayer', 'Turno Completo', 1000.00, 2400.00, 4650.00, 1900.00, 350.00, 3050.00, 3050.00, 0.00, 8600.00, 'Corte cuadrado sin diferencias. Se dejó fondo fijo de \$1,000 en caja chica.', 5);");
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(string $token): bool {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function requireAuth(): array {
    $pdo = getDB();
    if (isset($_GET['switch_user'])) {
        $uid = (int)$_GET['switch_user'];
        $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE id = ? AND activo = 1");
        $stmt->execute([$uid]);
        if ($u = $stmt->fetch()) {
            $_SESSION['usuario'] = $u;
        }
    }
    if (empty($_SESSION['usuario'])) {
        $stmt = $pdo->query("SELECT * FROM usuarios WHERE id = 5 LIMIT 1");
        $_SESSION['usuario'] = $stmt->fetch() ?: [
            'id' => 5,
            'nombre' => 'Laura Gómez',
            'rol' => 'recepcion',
            'area_clinica' => 'administracion'
        ];
    }
    return $_SESSION['usuario'];
}

function calcularEdad(?string $fechaNacimiento): int {
    if (!$fechaNacimiento) return 30;
    try {
        return (new DateTime())->diff(new DateTime($fechaNacimiento))->y;
    } catch (Throwable $e) {
        return 30;
    }
}

function calcularGastoEnergetico(float $pesoKg, float $tallaCm, int $edad, string $sexo, string $actividad, string $formula = 'Mifflin-St Jeor', ?float $masaMagraKg = null): array {
    if ($formula === 'Katch-McArdle' && $masaMagraKg > 0) {
        $tmb = 370 + (21.6 * $masaMagraKg);
    } elseif ($formula === 'Harris-Benedict') {
        $tmb = ($sexo === 'M')
            ? 66.47 + (13.75 * $pesoKg) + (5.0 * $tallaCm) - (6.75 * $edad)
            : 655.1 + (9.56 * $pesoKg) + (1.85 * $tallaCm) - (4.68 * $edad);
    } else {
        $tmb = ($sexo === 'M')
            ? (10 * $pesoKg) + (6.25 * $tallaCm) - (5 * $edad) + 5
            : (10 * $pesoKg) + (6.25 * $tallaCm) - (5 * $edad) - 161;
    }
    $factores = [
        'sedentario' => 1.2,
        'ligero' => 1.375,
        'moderado' => 1.55,
        'activo' => 1.725,
        'muy_activo' => 1.9
    ];
    $faf = $factores[$actividad] ?? 1.375;
    $get = round(($tmb * $faf) + ($tmb * 0.10));
    return ['tmb' => (int)round($tmb), 'get' => (int)$get, 'faf' => $faf];
}

function calcularHomaIR(?float $glucosaMg, ?float $insulinaUui): ?float {
    if (!$glucosaMg || !$insulinaUui || $glucosaMg <= 0 || $insulinaUui <= 0) {
        return null;
    }
    return round(($glucosaMg * $insulinaUui) / 405, 2);
}

function interpretarHomaIR(?float $homa): array {
    if ($homa === null) return ['texto' => 'Pendiente', 'badge' => 'badge-slate'];
    if ($homa < 2.0) return ['texto' => 'Sensibilidad Óptima (<2.0)', 'badge' => 'badge-teal'];
    if ($homa < 2.9) return ['texto' => 'Resistencia Temprana (2.0-2.9)', 'badge' => 'badge-amber'];
    return ['texto' => 'Resistencia a Insulina Significativa (≥2.9)', 'badge' => 'badge-rose'];
}

function interpretarPHQ9(int $puntaje): array {
    if ($puntaje <= 4) return ['nivel' => 'Mínima / Ausente', 'badge' => 'badge-teal'];
    if ($puntaje <= 9) return ['nivel' => 'Depresión Leve', 'badge' => 'badge-blue'];
    if ($puntaje <= 14) return ['nivel' => 'Depresión Moderada', 'badge' => 'badge-amber'];
    if ($puntaje <= 19) return ['nivel' => 'Moderadamente Severa', 'badge' => 'badge-rose'];
    return ['nivel' => 'Depresión Severa', 'badge' => 'badge-rose'];
}

function interpretarGAD7(int $puntaje): array {
    if ($puntaje <= 4) return ['nivel' => 'Ansiedad Mínima', 'badge' => 'badge-teal'];
    if ($puntaje <= 9) return ['nivel' => 'Ansiedad Leve', 'badge' => 'badge-blue'];
    if ($puntaje <= 14) return ['nivel' => 'Ansiedad Moderada', 'badge' => 'badge-amber'];
    return ['nivel' => 'Ansiedad Severa', 'badge' => 'badge-rose'];
}

function interpretarBES(int $puntaje): array {
    if ($puntaje <= 17) return ['nivel' => 'Sin Atracón Clínico', 'badge' => 'badge-teal'];
    if ($puntaje <= 26) return ['nivel' => 'Ingesta Emocional / Atracón Moderado', 'badge' => 'badge-amber'];
    return ['nivel' => 'Conducta de Atracón Severa', 'badge' => 'badge-rose'];
}

function generarLinkWhatsApp(string $telefono, string $nombrePaciente, string $fecha, string $hora, string $nombreDoctor, string $area = 'Nutrición / Psicología', bool $ayuno = false): string {
    $telLimpio = preg_replace('/[^0-9]/', '', $telefono);
    if (strlen($telLimpio) === 10) {
        $telLimpio = '521' . $telLimpio;
    }
    $extraAyuno = $ayuno ? " Recuerda acudir con 8 a 10 horas de ayuno para tu Checkup y toma de biomarcadores." : "";
    $msg = "Hola {$nombrePaciente}, te saludamos de " . CLINIC_NAME . ". Confirmamos tu cita de {$area} con {$nombreDoctor} el día {$fecha} a las {$hora} hrs.{$extraAyuno} Responde CONFIRMAR para tu ingreso en sala de espera.";
    return "https://wa.me/{$telLimpio}?text=" . urlencode($msg);
}

function generarLinkCobranzaWhatsApp(string $telefono, string $nombrePaciente, float $saldo, string $paquete = ''): string {
    $telLimpio = preg_replace('/[^0-9]/', '', $telefono);
    if (strlen($telLimpio) === 10) {
        $telLimpio = '521' . $telLimpio;
    }
    if ($saldo > 0) {
        $msg = "Hola {$nombrePaciente}, te recordamos de " . CLINIC_NAME . " que tu estado de cuenta presenta un saldo pendiente por liquidar de $" . number_format($saldo, 2) . " MXN. Puedes liquidarlo en mostrador o vía transferencia SPEI.";
    } else {
        $msg = "Hola {$nombrePaciente}, te escribimos de " . CLINIC_NAME . " para dar seguimiento a tu proceso clínico ({$paquete}) y agendar tu próxima sesión o Checkup de control.";
    }
    return "https://wa.me/{$telLimpio}?text=" . urlencode($msg);
}

function getRegimenesSAT(): array {
    return [
        '601' => '601 - General de Ley Personas Morales',
        '605' => '605 - Sueldos y Salarios e Ingresos Asimilados',
        '606' => '606 - Arrendamiento',
        '612' => '612 - Personas Físicas con Actividades Empresariales y Profesionales',
        '616' => '616 - Sin obligaciones fiscales',
        '626' => '626 - Régimen Simplificado de Confianza (RESICO)'
    ];
}

function getUsosCFDI(): array {
    return [
        'D01' => 'D01 - Honorarios médicos, dentales, psicológicos, nutricionales y checkups',
        'G03' => 'G03 - Gastos en general',
        'S01' => 'S01 - Sin efectos fiscales',
        'CP01' => 'CP01 - Pagos'
    ];
}

function getFormasPagoSAT(): array {
    return [
        '01' => '01 - Efectivo',
        '03' => '03 - Transferencia electrónica de fondos (SPEI)',
        '04' => '04 - Tarjeta de crédito',
        '28' => '28 - Tarjeta de débito',
        '99' => '99 - Por definir'
    ];
}

function getFotoEspecialista(int $id): string {
    $fotos = [
        1 => 'https://images.unsplash.com/photo-1559839734-2b71ea197ec2?auto=format&fit=crop&w=600&q=80',
        2 => 'https://images.unsplash.com/photo-1622253692010-333f2da6031d?auto=format&fit=crop&w=600&q=80',
        3 => 'https://images.unsplash.com/photo-1573496359142-b8d87734a5a2?auto=format&fit=crop&w=600&q=80',
        4 => 'https://images.unsplash.com/photo-1537368910025-700350fe46c7?auto=format&fit=crop&w=600&q=80',
        5 => 'https://images.unsplash.com/photo-1580489944761-15a19d654956?auto=format&fit=crop&w=400&q=80',
        6 => 'https://images.unsplash.com/photo-1560250097-0b93528c311a?auto=format&fit=crop&w=400&q=80',
    ];
    return $fotos[$id] ?? $fotos[1];
}

function getFotoPaciente(int $id): string {
    $fotos = [
        1 => 'https://images.unsplash.com/photo-1544005313-94ddf0286df2?auto=format&fit=crop&w=300&q=80',
        2 => 'https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?auto=format&fit=crop&w=300&q=80',
        3 => 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?auto=format&fit=crop&w=300&q=80',
        4 => 'https://images.unsplash.com/photo-1500648767791-00dcc994a43e?auto=format&fit=crop&w=300&q=80',
        5 => 'https://images.unsplash.com/photo-1573497019940-1c28c88b4f3e?auto=format&fit=crop&w=300&q=80',
    ];
    return $fotos[(($id - 1) % 5) + 1];
}

function renderPublicNav(): void {
    ?>
    <header class="public-header no-print">
        <div class="container flex-between">
            <a href="index.php" class="public-brand">
                <div class="public-brand-mark">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
                </div>
                <div class="public-brand-text">
                    <strong>Clínica NutriMind</strong>
                    <span>Nutrición · Psicología · Checkups</span>
                </div>
            </a>
            <nav class="public-nav">
                <a href="#especialidades">Especialidades</a>
                <a href="#checkups">Checkups</a>
                <a href="#equipo">Especialistas</a>
                <a href="#precios">Aranceles</a>
                <a href="#agendar" class="btn btn-primary btn-sm" style="color:#fff;">Agendar cita</a>
                <a href="recepcion.php" class="staff-access-link" title="Entrar al sistema de recepción y consultorios">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    Acceso Clínico
                </a>
            </nav>
        </div>
    </header>
    <?php
}

function renderAppStart(string $titulo, string $subtitulo, string $menuActivo = 'recepcion', array $subPills = [], string $actionHtml = ''): void {
    $pdo = getDB();
    $usuarioActual = requireAuth();
    $usuarios = $pdo->query("SELECT id, nombre, rol FROM usuarios WHERE activo = 1 ORDER BY id ASC")->fetchAll();
    $citasSalaCount = (int)$pdo->query("SELECT COUNT(*) FROM citas WHERE fecha_cita = '" . date('Y-m-d') . "' AND estado IN ('en_sala','por_cobrar')")->fetchColumn();
    $facturasPendCount = (int)$pdo->query("SELECT COUNT(*) FROM pagos_caja WHERE requiere_factura = 1 AND estado_factura = 'pendiente'")->fetchColumn();
    ?>
    <div class="app-shell">
        <aside class="app-sidebar no-print">
            <a href="recepcion.php" class="sidebar-brand">
                <div class="sidebar-brand-mark">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
                </div>
                <div class="sidebar-brand-text">
                    <strong>NutriMind</strong>
                    <span>Sistema Clínico</span>
                </div>
            </a>

            <div class="sidebar-section">
                <div class="sidebar-section-label">Operación Diaria</div>
                <nav class="sidebar-nav">
                    <a href="recepcion.php" class="sidebar-link <?= $menuActivo === 'recepcion' ? 'active' : '' ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        <span>Mostrador y Caja</span>
                        <?php if ($citasSalaCount > 0): ?>
                            <span class="sidebar-badge"><?= $citasSalaCount ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="cartera.php" class="sidebar-link <?= $menuActivo === 'cartera' ? 'active' : '' ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        <span>Pacientes</span>
                    </a>
                    <a href="consulta.php" class="sidebar-link <?= $menuActivo === 'consulta' ? 'active' : '' ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                        <span>Consulta Clínica</span>
                    </a>
                </nav>
            </div>

            <div class="sidebar-section">
                <div class="sidebar-section-label">Finanzas y Dirección</div>
                <nav class="sidebar-nav">
                    <a href="facturacion.php" class="sidebar-link <?= $menuActivo === 'facturacion' ? 'active' : '' ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                        <span>Facturación CFDI</span>
                        <?php if ($facturasPendCount > 0): ?>
                            <span class="sidebar-badge"><?= $facturasPendCount ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="administracion.php" class="sidebar-link <?= $menuActivo === 'administracion' ? 'active' : '' ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                        <span>Administración</span>
                    </a>
                </nav>
            </div>

            <div class="sidebar-footer">
                <span class="sidebar-user-label">Operador en turno</span>
                <form method="GET">
                    <?php foreach ($_GET as $k => $v): if ($k !== 'switch_user' && is_scalar($v)): ?>
                        <input type="hidden" name="<?= htmlspecialchars((string)$k) ?>" value="<?= htmlspecialchars((string)$v) ?>">
                    <?php endif; endforeach; ?>
                    <select name="switch_user" onchange="this.form.submit()" class="select-operator" title="Cambiar especialista o recepcionista">
                        <?php foreach ($usuarios as $u): ?>
                            <option value="<?= (int)$u['id'] ?>" <?= ((int)$usuarioActual['id'] === (int)$u['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($u['nombre']) ?> (<?= ucfirst($u['rol']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <a href="index.php" class="sidebar-public-link">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                    Ver sitio web público
                </a>
            </div>
        </aside>

        <div class="app-main">
            <header class="app-topbar no-print">
                <div class="topbar-title">
                    <h1><?= htmlspecialchars($titulo) ?></h1>
                    <?php if ($subtitulo): ?>
                        <p><?= htmlspecialchars($subtitulo) ?></p>
                    <?php endif; ?>
                </div>
                <div class="flex-gap">
                    <?php if (!empty($subPills)): ?>
                        <nav class="subnav-pills">
                            <?php foreach ($subPills as $pill): ?>
                                <a href="<?= htmlspecialchars($pill['href']) ?>" class="subnav-pill <?= !empty($pill['active']) ? 'active' : '' ?>">
                                    <?= htmlspecialchars($pill['label']) ?>
                                    <?php if (isset($pill['count']) && $pill['count'] !== null): ?>
                                        <span class="pill-count"><?= (int)$pill['count'] ?></span>
                                    <?php endif; ?>
                                </a>
                            <?php endforeach; ?>
                        </nav>
                    <?php endif; ?>
                    <?= $actionHtml ?>
                </div>
            </header>
            <main class="app-content">
    <?php
}

function renderAppEnd(): void {
    ?>
            </main>
        </div>
    </div>
    <script>
    function openModal(id) {
        var el = document.getElementById(id);
        if (el) el.classList.add('open');
    }
    function closeModal(id) {
        var el = document.getElementById(id);
        if (el) el.classList.remove('open');
    }
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-backdrop.open').forEach(function(m) {
                m.classList.remove('open');
            });
        }
    });
    </script>
    <?php
}

function renderNavbar(string $paginaActiva = 'recepcion'): void {
    renderPublicNav();
}
?>
