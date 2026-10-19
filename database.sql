-- ============================================================================
-- NUTRIMIND & CARE PRO — SISTEMA INTEGRAL DE NUTRICIÓN, PSICOLOGÍA, PSICONUTRICIÓN Y CHECKUPS
-- Esquema MySQL 8 / MariaDB para cPanel (Mostrador, Sala de Espera, POS, Checkups,
-- Facturación CFDI 4.0, Cartera de Clientes CRM, Estación Clínica y Administración Autónoma)
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '-06:00';

-- 1. USUARIOS Y ESPECIALISTAS (Nutriólogos, Psicólogos, Checkups, Recepción y Administración)
CREATE TABLE IF NOT EXISTS usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(120) NOT NULL,
    titulo VARCHAR(90) NOT NULL,
    especialidad VARCHAR(200) DEFAULT NULL,
    cedula_profesional VARCHAR(45) DEFAULT NULL,
    email VARCHAR(120) NOT NULL UNIQUE,
    telefono VARCHAR(30) DEFAULT NULL,
    password_hash VARCHAR(255) NOT NULL,
    rol ENUM('nutriologo', 'psicologo', 'recepcion', 'admin') NOT NULL DEFAULT 'nutriologo',
    area_clinica ENUM('nutricion', 'psicologia', 'psiconutricion', 'checkup', 'administracion') NOT NULL DEFAULT 'nutricion',
    consultorio_asignado VARCHAR(60) DEFAULT 'Consultorio 1',
    color_agenda VARCHAR(12) DEFAULT '#0d9488',
    costo_primera_vez DECIMAL(10,2) DEFAULT 950.00,
    costo_seguimiento DECIMAL(10,2) DEFAULT 750.00,
    porcentaje_comision DECIMAL(5,2) DEFAULT 70.00,
    activo TINYINT(1) DEFAULT 1,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. CATÁLOGO DE SERVICIOS, CHECKUPS, PAQUETES Y MEMBRESÍAS (con Claves SAT CFDI 4.0)
CREATE TABLE IF NOT EXISTS catalogo_servicios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    codigo VARCHAR(25) NOT NULL UNIQUE,
    nombre VARCHAR(150) NOT NULL,
    area ENUM('nutricion', 'psicologia', 'psiconutricion', 'checkup', 'paquete') NOT NULL,
    descripcion VARCHAR(255) DEFAULT NULL,
    precio DECIMAL(10,2) NOT NULL,
    sesiones_nutri_incluidas INT DEFAULT 0,
    sesiones_psico_incluidas INT DEFAULT 0,
    incluye_checkup TINYINT(1) DEFAULT 0,
    duracion_minutos INT DEFAULT 50,
    clave_prod_serv_sat VARCHAR(15) DEFAULT '85121800',
    clave_unidad_sat VARCHAR(10) DEFAULT 'E48',
    exento_iva TINYINT(1) DEFAULT 1,
    activo TINYINT(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. CARTERA DE CLIENTES / PACIENTES (CRM Clínico + Saldos + Paquetes + Datos Fiscales CFDI 4.0)
CREATE TABLE IF NOT EXISTS pacientes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    folio_expediente VARCHAR(25) NOT NULL UNIQUE,
    nutriologo_id INT DEFAULT NULL,
    psicologo_id INT DEFAULT NULL,
    modalidad_atencion ENUM('nutricion', 'psicologia', 'psiconutricion_integral', 'checkup_preventivo') NOT NULL DEFAULT 'psiconutricion_integral',
    nombre_completo VARCHAR(150) NOT NULL,
    fecha_nacimiento DATE NOT NULL,
    sexo ENUM('F', 'M', 'Otro') NOT NULL DEFAULT 'F',
    telefono_whatsapp VARCHAR(25) NOT NULL,
    email VARCHAR(120) DEFAULT NULL,
    ocupacion VARCHAR(100) DEFAULT NULL,
    contacto_emergencia VARCHAR(150) DEFAULT NULL,
    estatus_cartera ENUM('activo', 'en_riesgo', 'inactivo', 'alta_clinica') NOT NULL DEFAULT 'activo',
    nombre_paquete_activo VARCHAR(120) DEFAULT NULL,
    sesiones_disponibles_nutri INT DEFAULT 0,
    sesiones_disponibles_psico INT DEFAULT 0,
    checkups_disponibles INT DEFAULT 0,
    saldo_pendiente DECIMAL(10,2) DEFAULT 0.00,
    total_historico_pagado DECIMAL(10,2) DEFAULT 0.00,
    ultima_visita DATE DEFAULT NULL,
    proxima_cita_sugerida DATE DEFAULT NULL,
    rfc VARCHAR(15) DEFAULT 'XAXX010101000',
    razon_social VARCHAR(180) DEFAULT NULL,
    regimen_fiscal VARCHAR(10) DEFAULT '612',
    codigo_postal_fiscal VARCHAR(10) DEFAULT NULL,
    uso_cfdi VARCHAR(10) DEFAULT 'D01',
    email_facturacion VARCHAR(120) DEFAULT NULL,
    objetivo_principal VARCHAR(200) NOT NULL,
    motivo_psicologico VARCHAR(220) DEFAULT NULL,
    antecedentes_heredofamiliares TEXT DEFAULT NULL,
    antecedentes_patologicos TEXT DEFAULT NULL,
    alergias_intolerancias TEXT DEFAULT NULL,
    medicamentos_suplementos TEXT DEFAULT NULL,
    nivel_actividad_fisica ENUM('sedentario', 'ligero', 'moderado', 'activo', 'muy_activo') DEFAULT 'ligero',
    recordatorio_24h TEXT DEFAULT NULL,
    notas_crm_seguimiento TEXT DEFAULT NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (nutriologo_id) REFERENCES usuarios(id) ON DELETE SET NULL,
    FOREIGN KEY (psicologo_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. AGENDA DE CITAS, MOSTRADOR Y SALA DE ESPERA EN TIEMPO REAL
CREATE TABLE IF NOT EXISTS citas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    turno_sala VARCHAR(12) NOT NULL DEFAULT 'T-01',
    paciente_id INT DEFAULT NULL,
    nutriologo_id INT NOT NULL,
    servicio_id INT DEFAULT NULL,
    area_cita ENUM('nutricion', 'psicologia', 'psiconutricion', 'checkup') NOT NULL DEFAULT 'nutricion',
    consultorio VARCHAR(50) DEFAULT 'Consultorio 1',
    nombre_prospecto VARCHAR(150) DEFAULT NULL,
    telefono_prospecto VARCHAR(25) DEFAULT NULL,
    email_prospecto VARCHAR(120) DEFAULT NULL,
    fecha_cita DATE NOT NULL,
    hora_inicio TIME NOT NULL,
    hora_fin TIME NOT NULL,
    tipo_cita ENUM('primera_vez', 'seguimiento', 'psicoterapia', 'psiconutricion_integral', 'checkup_integral', 'control_antropometrico', 'online') NOT NULL DEFAULT 'seguimiento',
    origen ENUM('recepcion', 'landing_web', 'whatsapp', 'reagendado') NOT NULL DEFAULT 'recepcion',
    estado ENUM('pendiente_confirmar', 'confirmada', 'en_sala', 'en_consulta', 'por_cobrar', 'finalizada', 'cancelada', 'no_asistio') NOT NULL DEFAULT 'confirmada',
    requiere_ayuno TINYINT(1) DEFAULT 0,
    hora_llegada_sala TIME DEFAULT NULL,
    hora_inicio_consulta TIME DEFAULT NULL,
    hora_fin_consulta TIME DEFAULT NULL,
    costo_estimado DECIMAL(10,2) DEFAULT 850.00,
    pagado TINYINT(1) DEFAULT 0,
    motivo_notas VARCHAR(255) DEFAULT NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (paciente_id) REFERENCES pacientes(id) ON DELETE SET NULL,
    FOREIGN KEY (nutriologo_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. EVALUACIONES CLÍNICAS DE NUTRICIÓN Y ANTROPOMETRÍA ISAK
CREATE TABLE IF NOT EXISTS consultas_antropometria (
    id INT AUTO_INCREMENT PRIMARY KEY,
    paciente_id INT NOT NULL,
    nutriologo_id INT NOT NULL,
    fecha_consulta DATE NOT NULL,
    peso_kg DECIMAL(5,2) NOT NULL,
    talla_cm DECIMAL(5,2) NOT NULL,
    imc DECIMAL(5,2) NOT NULL,
    porcentaje_grasa DECIMAL(5,2) DEFAULT NULL,
    porcentaje_musculo DECIMAL(5,2) DEFAULT NULL,
    masa_grasa_kg DECIMAL(5,2) DEFAULT NULL,
    masa_magra_kg DECIMAL(5,2) DEFAULT NULL,
    grasa_visceral INT DEFAULT NULL,
    cintura_cm DECIMAL(5,2) DEFAULT NULL,
    cadera_cm DECIMAL(5,2) DEFAULT NULL,
    icc DECIMAL(4,2) DEFAULT NULL,
    ict DECIMAL(4,2) DEFAULT NULL,
    pliegue_tricipital_mm DECIMAL(5,2) DEFAULT NULL,
    pliegue_bicipital_mm DECIMAL(5,2) DEFAULT NULL,
    pliegue_subescapular_mm DECIMAL(5,2) DEFAULT NULL,
    pliegue_suprailiaco_mm DECIMAL(5,2) DEFAULT NULL,
    suma_4_pliegues_mm DECIMAL(6,2) DEFAULT NULL,
    formula_usada VARCHAR(40) DEFAULT 'Mifflin-St Jeor',
    tmb_kcal INT NOT NULL,
    get_kcal INT NOT NULL,
    meta_kcal INT NOT NULL,
    pct_proteina INT DEFAULT 25,
    pct_carbohidratos INT DEFAULT 45,
    pct_lipidos INT DEFAULT 30,
    diagnostico_nutricional TEXT DEFAULT NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (paciente_id) REFERENCES pacientes(id) ON DELETE CASCADE,
    FOREIGN KEY (nutriologo_id) REFERENCES usuarios(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. PLANES DE ALIMENTACIÓN SMAE
CREATE TABLE IF NOT EXISTS planes_alimentacion (
    id INT AUTO_INCREMENT PRIMARY KEY,
    paciente_id INT NOT NULL,
    nutriologo_id INT NOT NULL,
    nombre_plan VARCHAR(150) NOT NULL,
    kcal_objetivo INT NOT NULL,
    proteina_g INT NOT NULL,
    carbohidratos_g INT NOT NULL,
    lipidos_g INT NOT NULL,
    agua_ml INT DEFAULT 2500,
    eq_verduras DECIMAL(4,1) DEFAULT 4.0,
    eq_frutas DECIMAL(4,1) DEFAULT 3.0,
    eq_cereales_sg DECIMAL(4,1) DEFAULT 5.0,
    eq_leguminosas DECIMAL(4,1) DEFAULT 1.0,
    eq_aoa_bajo DECIMAL(4,1) DEFAULT 6.0,
    eq_leche_semi DECIMAL(4,1) DEFAULT 1.0,
    eq_grasas_sp DECIMAL(4,1) DEFAULT 4.0,
    eq_grasas_cp DECIMAL(4,1) DEFAULT 2.0,
    menu_desayuno TEXT NOT NULL,
    menu_colacion_1 TEXT DEFAULT NULL,
    menu_comida TEXT NOT NULL,
    menu_colacion_2 TEXT DEFAULT NULL,
    menu_cena TEXT NOT NULL,
    recomendaciones_generales TEXT DEFAULT NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (paciente_id) REFERENCES pacientes(id) ON DELETE CASCADE,
    FOREIGN KEY (nutriologo_id) REFERENCES usuarios(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. SESIONES CLÍNICAS DE PSICOLOGÍA Y PSICONUTRICIÓN (SOAP + Escalas PHQ-9, GAD-7, BES)
CREATE TABLE IF NOT EXISTS consultas_psicologia (
    id INT AUTO_INCREMENT PRIMARY KEY,
    paciente_id INT NOT NULL,
    psicologo_id INT NOT NULL,
    fecha_sesion DATE NOT NULL,
    numero_sesion INT DEFAULT 1,
    modalidad_sesion ENUM('psicoterapia_individual', 'psiconutricion_tca', 'evaluacion_inicial', 'intervencion_crisis') DEFAULT 'psicoterapia_individual',
    enfoque_terapeutico VARCHAR(80) DEFAULT 'Cognitivo-Conductual (TCC)',
    phq9_depresion INT DEFAULT 0,
    gad7_ansiedad INT DEFAULT 0,
    bes_atracon INT DEFAULT 0,
    nivel_estres INT DEFAULT 5,
    calidad_sueno_hrs DECIMAL(3,1) DEFAULT 7.0,
    riesgo_clinico ENUM('bajo', 'moderado', 'alto') DEFAULT 'bajo',
    estado_mental_examen VARCHAR(255) DEFAULT NULL,
    notas_soap TEXT NOT NULL,
    conducta_alimentaria_emocional TEXT DEFAULT NULL,
    tareas_terapeuticas TEXT DEFAULT NULL,
    acuerdos_interdisciplinarios TEXT DEFAULT NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (paciente_id) REFERENCES pacientes(id) ON DELETE CASCADE,
    FOREIGN KEY (psicologo_id) REFERENCES usuarios(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. CHECKUPS CLÍNICOS INTEGRALES (Signos Vitales, Biomarcadores de Laboratorio, HOMA-IR y Score 360)
CREATE TABLE IF NOT EXISTS checkups_clinicos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    folio_checkup VARCHAR(25) NOT NULL UNIQUE,
    paciente_id INT NOT NULL,
    especialista_id INT NOT NULL,
    fecha_checkup DATE NOT NULL,
    tipo_checkup VARCHAR(120) NOT NULL DEFAULT 'Checkup Integral Mente-Cuerpo 360°',
    -- Signos Vitales en Consultorio
    presion_sistolica INT DEFAULT 118,
    presion_diastolica INT DEFAULT 76,
    frecuencia_cardiaca INT DEFAULT 68,
    temperatura_c DECIMAL(4,1) DEFAULT 36.5,
    spo2_oxigenacion INT DEFAULT 98,
    -- Panel Metabólico, Lipídico, Hormonal y Estrés
    glucosa_ayuno_mg DECIMAL(6,2) DEFAULT NULL,
    insulina_basal_uui DECIMAL(6,2) DEFAULT NULL,
    indice_homa_ir DECIMAL(5,2) DEFAULT NULL,
    hba1c_pct DECIMAL(4,2) DEFAULT NULL,
    colesterol_total_mg DECIMAL(6,2) DEFAULT NULL,
    trigliceridos_mg DECIMAL(6,2) DEFAULT NULL,
    hdl_mg DECIMAL(5,2) DEFAULT NULL,
    ldl_mg DECIMAL(5,2) DEFAULT NULL,
    tsh_tiroides DECIMAL(5,2) DEFAULT NULL,
    cortisol_matutino DECIMAL(5,2) DEFAULT NULL,
    vitamina_d_ng DECIMAL(5,2) DEFAULT NULL,
    -- Semáforo Clínico y Conclusiones
    semaforo_metabolico ENUM('optimo', 'precaucion', 'alerta') DEFAULT 'optimo',
    semaforo_psicoemocional ENUM('optimo', 'precaucion', 'alerta') DEFAULT 'optimo',
    score_salud_integral INT DEFAULT 85,
    interpretacion_biomarcadores TEXT DEFAULT NULL,
    plan_accion_checkup TEXT DEFAULT NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (paciente_id) REFERENCES pacientes(id) ON DELETE CASCADE,
    FOREIGN KEY (especialista_id) REFERENCES usuarios(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. PUNTO DE VENTA (POS), COBROS, ABONOS Y COMISIONES EN CAJA
CREATE TABLE IF NOT EXISTS pagos_caja (
    id INT AUTO_INCREMENT PRIMARY KEY,
    folio_recibo VARCHAR(30) NOT NULL UNIQUE,
    cita_id INT DEFAULT NULL,
    paciente_id INT DEFAULT NULL,
    nutriologo_id INT NOT NULL,
    servicio_id INT DEFAULT NULL,
    categoria_ingreso ENUM('nutricion', 'psicologia', 'psiconutricion', 'checkup', 'paquete', 'abono_saldo') NOT NULL DEFAULT 'nutricion',
    concepto VARCHAR(180) NOT NULL,
    monto DECIMAL(10,2) NOT NULL,
    descuento DECIMAL(10,2) DEFAULT 0.00,
    impuestos DECIMAL(10,2) DEFAULT 0.00,
    total_pagado DECIMAL(10,2) NOT NULL,
    saldo_pendiente_generado DECIMAL(10,2) DEFAULT 0.00,
    metodo_pago ENUM('efectivo', 'tarjeta_credito', 'tarjeta_debito', 'transferencia', 'redencion_paquete') NOT NULL DEFAULT 'efectivo',
    referencia_pago VARCHAR(80) DEFAULT NULL,
    comision_especialista DECIMAL(10,2) DEFAULT 0.00,
    requiere_factura TINYINT(1) DEFAULT 0,
    estado_factura ENUM('no_solicitada', 'pendiente', 'timbrada', 'cancelada') DEFAULT 'no_solicitada',
    registrado_por INT NOT NULL,
    fecha_pago DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (nutriologo_id) REFERENCES usuarios(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. FACTURACIÓN ELECTRÓNICA CFDI 4.0
CREATE TABLE IF NOT EXISTS facturas_cfdi (
    id INT AUTO_INCREMENT PRIMARY KEY,
    folio_factura VARCHAR(25) NOT NULL UNIQUE,
    uuid_fiscal VARCHAR(45) NOT NULL UNIQUE,
    pago_id INT DEFAULT NULL,
    paciente_id INT NOT NULL,
    rfc_receptor VARCHAR(15) NOT NULL,
    razon_social_receptor VARCHAR(180) NOT NULL,
    regimen_fiscal_receptor VARCHAR(10) NOT NULL DEFAULT '612',
    cp_receptor VARCHAR(10) NOT NULL,
    uso_cfdi VARCHAR(10) NOT NULL DEFAULT 'D01',
    clave_prod_serv VARCHAR(15) NOT NULL DEFAULT '85121800',
    clave_unidad VARCHAR(10) NOT NULL DEFAULT 'E48',
    concepto VARCHAR(200) NOT NULL,
    forma_pago_sat VARCHAR(10) NOT NULL DEFAULT '04',
    metodo_pago_sat VARCHAR(5) NOT NULL DEFAULT 'PUE',
    subtotal DECIMAL(10,2) NOT NULL,
    iva DECIMAL(10,2) DEFAULT 0.00,
    total DECIMAL(10,2) NOT NULL,
    estado ENUM('timbrada', 'pendiente', 'cancelada') NOT NULL DEFAULT 'timbrada',
    fecha_emision DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (paciente_id) REFERENCES pacientes(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. GASTOS OPERATIVOS Y EGRESOS (Administración Autónoma)
CREATE TABLE IF NOT EXISTS gastos_operativos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    folio_gasto VARCHAR(25) NOT NULL UNIQUE,
    categoria ENUM('renta_consultorio', 'comisiones_especialistas', 'insumos_clinicos', 'laboratorio_checkups', 'marketing_ads', 'servicios_basicos', 'software_sistemas', 'mantenimiento') NOT NULL,
    concepto VARCHAR(180) NOT NULL,
    proveedor VARCHAR(120) DEFAULT NULL,
    monto DECIMAL(10,2) NOT NULL,
    metodo_pago ENUM('transferencia', 'efectivo_caja', 'tarjeta_corporativa') NOT NULL DEFAULT 'transferencia',
    deducible_impuestos TINYINT(1) DEFAULT 1,
    fecha_gasto DATE NOT NULL,
    registrado_por INT NOT NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. CORTES DE CAJA DIARIOS Y ARQUEO CIEGO
CREATE TABLE IF NOT EXISTS cortes_caja (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fecha_corte DATE NOT NULL,
    turno VARCHAR(30) DEFAULT 'Día Completo',
    fondo_inicial_efectivo DECIMAL(10,2) DEFAULT 1000.00,
    ingresos_efectivo DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    ingresos_tarjeta DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    ingresos_transferencia DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    gastos_efectivo DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    efectivo_esperado DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    efectivo_contado_fisico DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    diferencia_arqueo DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total_neto_dia DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    observaciones VARCHAR(255) DEFAULT NULL,
    cerrado_por INT NOT NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- SEMILLAS INICIALES PARA DEMOSTRACIÓN Y OPERACIÓN INMEDIATA
-- ============================================================================

INSERT INTO usuarios (id, nombre, titulo, especialidad, cedula_profesional, email, telefono, password_hash, rol, area_clinica, consultorio_asignado, color_agenda, costo_primera_vez, costo_seguimiento, porcentaje_comision) VALUES
(1, 'Dra. Sofía Mendoza', 'L.N. y M.C. en Nutrición Clínica y Checkup Metabólico', 'Nutrición Clínica, Checkups Cardiometabólicos, Diabetes y Salud Hormonal', 'Céd. Prof. 8492014', 'sofia.mendoza@nutrimind.mx', '5512345678', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'nutriologo', 'nutricion', 'Consultorio 1 (Nutrición y Checkup)', '#0d9488', 950.00, 750.00, 70.00),
(2, 'Dr. Alejandro Vega', 'L.N. y Esp. en Nutrición Deportiva (ISAK II)', 'Nutrición Deportiva, Checkup de Rendimiento, Recomposición e Hipertrofia', 'Céd. Prof. 9103842', 'alejandro.vega@nutrimind.mx', '5587654321', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'nutriologo', 'nutricion', 'Consultorio 1 (Nutrición y Checkup)', '#2563eb', 1050.00, 800.00, 70.00),
(3, 'Mtra. Valeria Rojas', 'Lic. y Mtra. en Psicología Clínica y TCC', 'Terapia Cognitivo-Conductual, Ansiedad, Depresión, Regulación Emocional y Estrés', 'Céd. Prof. 7741920', 'valeria.rojas@nutrimind.mx', '5533445566', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'psicologo', 'psicologia', 'Consultorio 2 (Psicología)', '#7c3aed', 1000.00, 850.00, 70.00),
(4, 'Dr. Daniel Herrera', 'Dr. en Psicología de la Salud y Psiconutrición', 'Trastornos de la Conducta Alimentaria (TCA), Hambre Emocional, Atracón y Psicología Bariátrica', 'Céd. Prof. 6839201', 'daniel.herrera@nutrimind.mx', '5566778899', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'psicologo', 'psiconutricion', 'Consultorio 3 (Psiconutrición)', '#db2777', 1150.00, 900.00, 75.00),
(5, 'Laura Gómez', 'Coordinadora de Mostrador, Sala de Espera y Caja', 'Recepción, Triage de Checkups, Cobranza POS y Facturación CFDI 4.0', 'N/A', 'recepcion@nutrimind.mx', '5544332211', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'recepcion', 'administracion', 'Mostrador Central', '#475569', 0.00, 0.00, 0.00),
(6, 'Lic. Carlos Guizar', 'Director General y Administrador Clínico', 'Administración Autónoma, Finanzas, Nómina de Comisiones y Cartera', 'N/A', 'admin@nutrimind.mx', '5599887766', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 'administracion', 'Dirección', '#0f172a', 0.00, 0.00, 0.00);

INSERT INTO catalogo_servicios (id, codigo, nombre, area, descripcion, precio, sesiones_nutri_incluidas, sesiones_psico_incluidas, incluye_checkup, duracion_minutos, clave_prod_serv_sat, clave_unidad_sat, exento_iva) VALUES
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
(11, 'PAQ-PSICO-4', 'Paquete Mensual Psicoterapia (4 Sesiones TCC)', 'paquete', 'Paquete de 4 sesiones de psicoterapia individual', 3000.00, 0, 4, 0, 50, '85121600', 'E48', 0);

INSERT INTO pacientes (id, folio_expediente, nutriologo_id, psicologo_id, modalidad_atencion, nombre_completo, fecha_nacimiento, sexo, telefono_whatsapp, email, ocupacion, contacto_emergencia, estatus_cartera, nombre_paquete_activo, sesiones_disponibles_nutri, sesiones_disponibles_psico, checkups_disponibles, saldo_pendiente, total_historico_pagado, ultima_visita, proxima_cita_sugerida, rfc, razon_social, regimen_fiscal, codigo_postal_fiscal, uso_cfdi, email_facturacion, objetivo_principal, motivo_psicologico, antecedentes_heredofamiliares, antecedentes_patologicos, alergias_intolerancias, medicamentos_suplementos, nivel_actividad_fisica) VALUES
(1, 'EXP-2026-001', 1, 4, 'psiconutricion_integral', 'Mariana Fernández López', '1992-05-14', 'F', '5511223344', 'mariana.fdez@email.com', 'Arquitecta Senior', 'Roberto Fernández (Esposo) - 5599001122', 'activo', 'Paquete Mensual Equilibrio Total', 3, 2, 1, 0.00, 7150.00, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 7 DAY), 'FELM920514AB1', 'MARIANA FERNANDEZ LOPEZ', '612', '03100', 'D01', 'facturas.mariana@email.com', 'Control de resistencia a la insulina y recomposición corporal sin dieta restrictiva', 'Episodios de hambre emocional nocturna y ansiedad laboral alta', 'Madre con DM2, Padre con HTA', 'Resistencia a la insulina diagnosticada hace 8 meses', 'Intolerancia a la lactosa moderada', 'Metformina 500mg c/24h, Inositol 2g, Magnesio', 'moderado'),
(2, 'EXP-2026-002', 2, 3, 'psiconutricion_integral', 'Jorge Eduardo Ramírez Soto', '1988-11-22', 'M', '5522334455', 'jorge.ramirez@empresa.mx', 'Director Comercial', 'Elena Soto (Madre) - 5588112233', 'activo', 'Checkup Deportivo + Seguimiento', 0, 0, 0, 450.00, 4900.00, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 14 DAY), 'RASJ8811229K4', 'JORGE EDUARDO RAMIREZ SOTO', '605', '06600', 'D01', 'jorge.ramirez@empresa.mx', 'Pérdida de grasa visceral, hipertrofia muscular y preparación de medio maratón', 'Manejo de estrés ejecutivo, insomnio de conciliación y perfeccionismo', 'Padre con dislipidemia', 'Hipercolesterolemia leve', 'Ninguna conocida', 'Omega 3 2g/día, Creatina monohidratada 5g/día', 'activo'),
(3, 'EXP-2026-003', 1, 4, 'psiconutricion_integral', 'Camila Torres Villaseñor', '1999-08-03', 'F', '5533445577', 'camila.tv@email.com', 'Diseñadora UX', 'Patricia Villaseñor (Madre) - 5577665544', 'en_riesgo', 'Paquete Mensual Psicoterapia (4 Sesiones TCC)', 0, 1, 0, 850.00, 4100.00, DATE_SUB(CURDATE(), INTERVAL 24 DAY), CURDATE(), 'TOVC990803H29', 'CAMILA TORRES VILLASENOR', '626', '01000', 'D01', 'camila.tv@email.com', 'Recuperación nutricional, relación saludable con los alimentos y energía diaria', 'Prevención de recaídas en conducta restrictiva/atracón y distorsión de imagen corporal', 'Sin antecedentes relevantes', 'Amenorrea hipotalámica funcional en remisión', 'Alergia al cacahuate', 'Vitamina D3 4000 UI, Hierro aminoquelado', 'ligero'),
(4, 'EXP-2026-004', 2, NULL, 'checkup_preventivo', 'Andrés Castellanos Mora', '1995-02-19', 'M', '5544556688', 'andres.castellanos@email.com', 'Ingeniero de Software', 'Lucía Mora - 5566554433', 'activo', 'Paquete Trimestral Nutrición (4 Consultas + ISAK)', 2, 0, 1, 0.00, 4450.00, DATE_SUB(CURDATE(), INTERVAL 5 DAY), DATE_ADD(CURDATE(), INTERVAL 10 DAY), 'CAMA950219L10', 'ANDRES CASTELLANOS MORA', '626', '04100', 'D01', 'andres.castellanos@email.com', 'Checkup deportivo anual y aumento de masa muscular limpia (+4 kg)', NULL, 'Abuelo paterno con cardiopatía', 'Ninguno', 'Ninguna', 'Proteína aislada de suero, Cafeína pre-entreno', 'muy_activo'),
(5, 'EXP-2026-005', NULL, 3, 'psicologia', 'Patricia Domínguez Ríos', '1983-07-11', 'F', '5566778800', 'patricia.dominguez@email.com', 'Contadora Pública', 'Miguel Ríos - 5511998877', 'activo', 'Sin paquete (Pago por sesión)', 0, 0, 0, 0.00, 2850.00, DATE_SUB(CURDATE(), INTERVAL 3 DAY), DATE_ADD(CURDATE(), INTERVAL 4 DAY), 'DORP830711P82', 'PATRICIA DOMINGUEZ RIOS', '612', '11560', 'D01', 'patricia.dominguez@email.com', 'Bienestar emocional y manejo de duelo y ansiedad generalizada', 'Trastorno de ansiedad generalizada y agotamiento laboral (Burnout)', 'Madre con trastorno depresivo', 'Hipotiroidismo subclínico controlado', 'Penicilina', 'Levotiroxina 50mcg, Escitalopram 10mg', 'sedentario');

INSERT INTO citas (id, turno_sala, paciente_id, nutriologo_id, servicio_id, area_cita, consultorio, nombre_prospecto, telefono_prospecto, email_prospecto, fecha_cita, hora_inicio, hora_fin, tipo_cita, origen, estado, requiere_ayuno, hora_llegada_sala, hora_inicio_consulta, costo_estimado, pagado, motivo_notas) VALUES
(1, 'CK-01', 1, 1, 6, 'checkup', 'Consultorio 1 (Nutrición y Checkup)', NULL, NULL, NULL, CURDATE(), '08:30:00', '09:30:00', 'checkup_integral', 'recepcion', 'finalizada', 1, '08:22:00', '08:30:00', 2450.00, 1, 'Checkup Integral 360° (Ayuno 10h verificado + Bioimpedancia + Panel HOMA-IR)'),
(2, 'PN-01', 1, 4, 5, 'psiconutricion', 'Consultorio 3 (Psiconutrición)', NULL, NULL, NULL, CURDATE(), '10:00:00', '11:00:00', 'psiconutricion_integral', 'recepcion', 'en_consulta', 0, '09:50:00', '10:02:00', 1450.00, 1, 'Sesión psiconutrición: revisión emocional del Checkup y hambre emocional'),
(3, 'P-01', 2, 3, 4, 'psicologia', 'Consultorio 2 (Psicología)', NULL, NULL, NULL, CURDATE(), '10:30:00', '11:20:00', 'psicoterapia', 'whatsapp', 'en_sala', 0, '10:18:00', NULL, 850.00, 0, 'Sesión TCC manejo de estrés ejecutivo e higiene del sueño'),
(4, 'CK-02', 2, 2, 8, 'checkup', 'Consultorio 1 (Nutrición y Checkup)', NULL, NULL, NULL, CURDATE(), '11:30:00', '12:30:00', 'checkup_integral', 'recepcion', 'confirmada', 1, NULL, NULL, 1800.00, 0, 'Checkup Deportivo de Alto Rendimiento + Plicometría ISAK II pre-21K'),
(5, 'PN-02', 3, 4, 4, 'psiconutricion', 'Consultorio 3 (Psiconutrición)', NULL, NULL, NULL, CURDATE(), '12:30:00', '13:20:00', 'psicoterapia', 'recepcion', 'por_cobrar', 0, '12:20:00', '12:30:00', 900.00, 0, 'Seguimiento TCA y flexibilidad cognitiva alimentaria'),
(6, 'W-01', NULL, 3, 3, 'psicologia', 'Consultorio 2 (Psicología)', 'Fernanda Olivares Luna', '5577889900', 'fer.olivares@email.com', CURDATE(), '13:30:00', '14:20:00', 'primera_vez', 'landing_web', 'pendiente_confirmar', 0, NULL, NULL, 1000.00, 0, 'Solicitud web: Ansiedad por atracón y evaluación psicológica inicial');

INSERT INTO consultas_antropometria (paciente_id, nutriologo_id, fecha_consulta, peso_kg, talla_cm, imc, porcentaje_grasa, porcentaje_musculo, masa_grasa_kg, masa_magra_kg, grasa_visceral, cintura_cm, cadera_cm, icc, ict, pliegue_tricipital_mm, pliegue_bicipital_mm, pliegue_subescapular_mm, pliegue_suprailiaco_mm, suma_4_pliegues_mm, formula_usada, tmb_kcal, get_kcal, meta_kcal, pct_proteina, pct_carbohidratos, pct_lipidos, diagnostico_nutricional) VALUES
(1, 1, DATE_SUB(CURDATE(), INTERVAL 30 DAY), 72.4, 163.0, 27.25, 33.8, 26.4, 24.47, 47.93, 8, 86.5, 102.0, 0.85, 0.53, 19.5, 11.0, 18.0, 21.0, 69.5, 'Mifflin-St Jeor', 1412, 2330, 1850, 25, 45, 30, 'Sobrepeso grado I (IMC 27.25 kg/m2) relacionado con desbalance energético y resistencia a la insulina evidenciado por ICT 0.53 y plicometría 69.5 mm.'),
(1, 1, CURDATE(), 69.8, 163.0, 26.27, 31.2, 27.9, 21.78, 48.02, 7, 82.0, 100.5, 0.82, 0.50, 17.0, 9.5, 16.0, 18.5, 61.0, 'Mifflin-St Jeor', 1386, 2287, 1800, 28, 42, 30, 'Evolución favorable: reducción de -2.6 kg de masa grasa con preservación e incremento de masa magra (+0.09 kg) y disminución de 4.5 cm de cintura.'),
(2, 2, CURDATE(), 78.5, 176.0, 25.34, 15.4, 43.2, 12.09, 66.41, 5, 81.0, 96.0, 0.84, 0.46, 8.5, 4.5, 11.0, 10.5, 34.5, 'Katch-McArdle', 1804, 3292, 2950, 25, 50, 25, 'Composición corporal atlética con adecuado porcentaje de masa muscular esquelética. Se ajusta carga de carbohidratos complejos para volumen de carrera.');

INSERT INTO planes_alimentacion (paciente_id, nutriologo_id, nombre_plan, kcal_objetivo, proteina_g, carbohidratos_g, lipidos_g, agua_ml, eq_verduras, eq_frutas, eq_cereales_sg, eq_leguminosas, eq_aoa_bajo, eq_leche_semi, eq_grasas_sp, eq_grasas_cp, menu_desayuno, menu_colacion_1, menu_comida, menu_colacion_2, menu_cena, recomendaciones_generales) VALUES
(1, 1, 'Plan Antiinflamatorio de Bajo Índice Glucémico + Saciedad Consciente', 1800, 126, 189, 60, 2600, 5.0, 3.0, 5.0, 1.0, 6.0, 1.0, 4.0, 2.0,
'Omelette de 2 huevos enteros + 2 claras con espinacas, champiñones y 40g de queso panela. Acompañar con 1 rebanada de pan masa madre tostado con 1/3 de pieza de aguacate Hass y 1 taza de frutos rojos.',
'1 taza de yogur griego sin azúcar añadido con 10 almendras fileteadas, 1 cdita de semillas de chía y canela en polvo.',
'150g de salmón o pechuga de pollo a la plancha con hierbas finas + 2/3 taza de quinoa o arroz basmati cocido + 1/2 taza de lentejas + ensalada fresca de hojas verdes con 1 cda de aceite de oliva extra virgen.',
'1 manzana mediana en gajos con 1 cucharada de crema de almendras o cacahuate natural 100% (pausa de respiración consciente de 2 min antes de comer).',
'2 tostadas horneadas de maíz con 120g de atún fresco o fajitas de pollo, pico de gallo, nopales asados y 1/3 de pieza de aguacate. Infusión de manzanilla o toronjil 45 min antes de dormir.',
'1. Practicar escala de hambre-saciedad (1 al 10) antes y después de cada tiempo de comida en conjunto con lo trabajado en Psiconutrición.\n2. Priorizar el orden de ingesta: primero fibra/verduras, luego proteína y grasas saludables, y al final los carbohidratos complejos.\n3. Hidratación fraccionada: 2.6 litros de agua natural a lo largo del día.');

INSERT INTO consultas_psicologia (paciente_id, psicologo_id, fecha_sesion, numero_sesion, modalidad_sesion, enfoque_terapeutico, phq9_depresion, gad7_ansiedad, bes_atracon, nivel_estres, calidad_sueno_hrs, riesgo_clinico, estado_mental_examen, notas_soap, conducta_alimentaria_emocional, tareas_terapeuticas, acuerdos_interdisciplinarios) VALUES
(1, 4, DATE_SUB(CURDATE(), INTERVAL 14 DAY), 1, 'evaluacion_inicial', 'Psiconutrición / TCC + ACT', 8, 13, 21, 8, 5.5, 'bajo', 'Orientada en las 3 esferas, discurso fluido, afecto ansioso reactivo a exigencias laborales, insight conservado.', 'S: La paciente refiere episodios de urgencia por comer alimentos dulces entre las 20:00 y 22:30 hrs tras jornadas laborales intensas.\nO: Puntaje GAD-7 de 13 (ansiedad moderada) y BES de 21 (ingesta emocional moderada).\nA: Se identifica ciclo de restricción cognitiva diurna seguido de desinhibición nocturna como mecanismo de regulación emocional ante estrés laboral.\nP: Reestructuración cognitiva de pensamientos dicotómicos (todo o nada) respecto a la comida y entrenamiento en Urge Surfing.', 'Identifica detonantes emocionales: cansancio extremo al llegar a casa y postergación de la colación vespertina.', '1. Autorregistro de Hambre Física vs. Hambre Emocional sin juicio crítico.\n2. Pausa de 5 minutos con respiración diafragmática 4-7-8 antes de abrir la alacena por la noche.', 'Coordinación con Dra. Sofía Mendoza para incluir colación vespertina de alta saciedad (proteína + grasa saludable) a las 17:30 hrs.'),
(1, 4, CURDATE(), 2, 'psiconutricion_tca', 'Psiconutrición / TCC + ACT', 5, 8, 12, 5, 7.0, 'bajo', 'Eutímica, motivada, menor tensión somática, reporta descanso reparador.', 'S: Reporta disminución notable de episodios de ansiedad nocturna (de 5 por semana a solo 1 episodio leve que logró gestionar sin culpa).\nO: Descenso clínico significativo en GAD-7 (de 13 a 8) y escala BES (de 21 a 12).\nA: Alta adherencia a la colación estratégica de las 17:30 hrs y desactivación de culpa post-ingesta.\nP: Fortalecer autocompasión, flexibilidad alimentaria en eventos sociales y límites laborales.', 'Reducción del 70% en urgencia por carbohidratos simples nocturnos; mayor conexión con señales interoceptivas de saciedad.', 'Practicar alimentación consciente (Mindful Eating) en al menos 1 comida principal al día sin pantallas.', 'Continuar con esquema flexible SMAE de 1800 kcal sin pesaje obsesivo de alimentos.'),
(2, 3, CURDATE(), 3, 'psicoterapia_individual', 'Cognitivo-Conductual (TCC)', 4, 11, 4, 7, 6.0, 'bajo', 'Alerta, cooperador, lenguaje estructurado, tendencia a la hiperexigencia.', 'S: Refiere dificultad para desconectarse de pendientes directivos después de las 21:00 hrs, afectando recuperación muscular y sueño.\nO: GAD-7 en 11 puntos; sueño promedio de 6.0 hrs.\nA: Hiperactivación fisiológica vespertina por rumiación anticipatoria.\nP: Protocolo de higiene del sueño, ventana de preocupación programada a las 18:30 hrs y relajación muscular progresiva de Jacobson.', 'Sin alteraciones de la conducta alimentaria; adecuada relación con el plan deportivo.', 'Apagado digital 60 minutos antes de dormir y registro de pensamientos automáticos perfeccionistas.', 'Compartir métricas de calidad de sueño con Dr. Alejandro Vega para ajustar horario de suplementación pre-entreno.');

INSERT INTO checkups_clinicos (id, folio_checkup, paciente_id, especialista_id, fecha_checkup, tipo_checkup, presion_sistolica, presion_diastolica, frecuencia_cardiaca, temperatura_c, spo2_oxigenacion, glucosa_ayuno_mg, insulina_basal_uui, indice_homa_ir, hba1c_pct, colesterol_total_mg, trigliceridos_mg, hdl_mg, ldl_mg, tsh_tiroides, cortisol_matutino, vitamina_d_ng, semaforo_metabolico, semaforo_psicoemocional, score_salud_integral, interpretacion_biomarcadores, plan_accion_checkup) VALUES
(1, 'CHK-2026-001', 1, 1, CURDATE(), 'Checkup Integral Mente-Cuerpo 360°', 116, 74, 66, 36.4, 98, 94.0, 10.2, 2.37, 5.3, 184.0, 128.0, 56.0, 102.0, 2.10, 14.5, 34.0, 'precaucion', 'optimo', 86, 'Mejoría notable en sensibilidad a la insulina: HOMA-IR descendió de 3.45 a 2.37 (meta < 2.0). Perfil lipídico dentro de rangos cardioprotectores (Triglicéridos 128 mg/dL, HDL 56 mg/dL) y cortisol matutino normalizado tras intervención psiconutricional.', 'Mantener suplementación con Inositol 2g + Magnesio, continuar dieta antiinflamatoria SMAE 1800 kcal y repetir Checkup metabólico de control en 12 semanas.'),
(2, 'CHK-2026-002', 2, 2, CURDATE(), 'Checkup Deportivo de Alto Rendimiento + Plicometría ISAK II', 112, 70, 54, 36.3, 99, 86.0, 5.4, 1.15, 5.0, 198.0, 92.0, 64.0, 115.0, 1.85, 19.8, 42.0, 'optimo', 'precaucion', 89, 'Bradicardia fisiológica del deportista (FC 54 lpm), excelente sensibilidad insulínica (HOMA-IR 1.15). Se observa cortisol matutino en límite superior (19.8 ug/dL) correlacionado con sobrecarga de trabajo y sueño de 6h.', 'Optimizar recuperación post-entreno, priorizar protocolo de higiene del sueño TCC y aumentar ingesta de antioxidantes y omega-3.');

INSERT INTO pagos_caja (id, folio_recibo, cita_id, paciente_id, nutriologo_id, servicio_id, categoria_ingreso, concepto, monto, descuento, impuestos, total_pagado, saldo_pendiente_generado, metodo_pago, referencia_pago, comision_especialista, requiere_factura, estado_factura, registrado_por, fecha_pago) VALUES
(1, 'REC-20261019-101', 1, 1, 1, 9, 'paquete', 'Paquete Mensual Equilibrio Total (Checkup 360° + 4 Nutrición + 4 Psicología)', 6200.00, 0.00, 0.00, 6200.00, 0.00, 'tarjeta_credito', 'AUT-884920', 4340.00, 1, 'timbrada', 5, CONCAT(CURDATE(), ' 08:55:00')),
(2, 'REC-20261019-102', 2, 1, 4, 5, 'psiconutricion', 'Consulta Interdisciplinaria de Psiconutrición (Redención Sesión Paquete)', 1450.00, 500.00, 0.00, 950.00, 0.00, 'transferencia', 'SPEI-992014', 712.50, 1, 'timbrada', 5, CONCAT(CURDATE(), ' 10:00:00')),
(3, 'REC-20261019-103', NULL, 2, 2, 8, 'checkup', 'Checkup Deportivo de Alto Rendimiento + ISAK II (Abono parcial)', 1800.00, 0.00, 0.00, 1350.00, 450.00, 'efectivo', 'EFEC-CAJA', 945.00, 0, 'no_solicitada', 5, CONCAT(CURDATE(), ' 10:15:00')),
(4, 'REC-20261019-104', NULL, 5, 3, 4, 'psicologia', 'Sesión de Psicoterapia Individual TCC', 850.00, 0.00, 0.00, 850.00, 0.00, 'tarjeta_debito', 'AUT-441029', 595.00, 1, 'pendiente', 5, CONCAT(CURDATE(), ' 11:10:00'));

INSERT INTO facturas_cfdi (id, folio_factura, uuid_fiscal, pago_id, paciente_id, rfc_receptor, razon_social_receptor, regimen_fiscal_receptor, cp_receptor, uso_cfdi, clave_prod_serv, clave_unidad, concepto, forma_pago_sat, metodo_pago_sat, subtotal, iva, total, estado, fecha_emision) VALUES
(1, 'FAC-A001', '550E8400-E29B-41D4-A716-446655440001', 1, 1, 'FELM920514AB1', 'MARIANA FERNANDEZ LOPEZ', '612', '03100', 'D01', '85121800', 'E48', 'Honorarios Médicos, Checkup Integral 360 y Psiconutrición', '04', 'PUE', 6200.00, 0.00, 6200.00, 'timbrada', CONCAT(CURDATE(), ' 09:05:00')),
(2, 'FAC-A002', '661F9511-F30C-52E5-B827-557766550002', 2, 1, 'FELM920514AB1', 'MARIANA FERNANDEZ LOPEZ', '612', '03100', 'D01', '85121600', 'E48', 'Honorarios de Psicología Clínica y Psiconutrición', '03', 'PUE', 950.00, 0.00, 950.00, 'timbrada', CONCAT(CURDATE(), ' 10:08:00'));

INSERT INTO gastos_operativos (id, folio_gasto, categoria, concepto, proveedor, monto, metodo_pago, deducible_impuestos, fecha_gasto, registrado_por) VALUES
(1, 'GAS-2026-001', 'renta_consultorio', 'Renta Mensual Suite Clínica (3 Consultorios + Sala de Espera + Área Checkup)', 'Inmobiliaria Médica del Valle S.A. de C.V.', 14500.00, 'transferencia', 1, DATE_SUB(CURDATE(), INTERVAL 5 DAY), 6),
(2, 'GAS-2026-002', 'insumos_clinicos', 'Tiras reactivas checkup, hojas térmicas InBody, plicómetro y cafetería sala de espera', 'Distribuidora Médica Integral', 1850.00, 'efectivo_caja', 1, CURDATE(), 5),
(3, 'GAS-2026-003', 'marketing_ads', 'Campaña Meta Ads + Google Ads (Captación Nutrición, Psicología y Checkups)', 'Meta Platforms / Google México', 3200.00, 'tarjeta_corporativa', 1, DATE_SUB(CURDATE(), INTERVAL 2 DAY), 6),
(4, 'GAS-2026-004', 'laboratorio_checkups', 'Convenio procesamiento de muestras sanguíneas para Checkup 360°', 'Laboratorios Clínicos Asociados', 2400.00, 'transferencia', 1, DATE_SUB(CURDATE(), INTERVAL 3 DAY), 6);

INSERT INTO cortes_caja (id, fecha_corte, turno, fondo_inicial_efectivo, ingresos_efectivo, ingresos_tarjeta, ingresos_transferencia, gastos_efectivo, efectivo_esperado, efectivo_contado_fisico, diferencia_arqueo, total_neto_dia, observaciones, cerrado_por) VALUES
(1, DATE_SUB(CURDATE(), INTERVAL 1 DAY), 'Turno Completo', 1000.00, 2400.00, 4650.00, 1900.00, 350.00, 3050.00, 3050.00, 0.00, 8600.00, 'Corte cuadrado sin diferencias. Se dejó fondo fijo de $1,000 en caja chica.', 5);
