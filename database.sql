SET NAMES utf8mb4;
SET time_zone = '-06:00';

CREATE TABLE IF NOT EXISTS usuarios (
      id INT AUTO_INCREMENT PRIMARY KEY,
      nombre VARCHAR(120) NOT NULL,
      titulo VARCHAR(80) NOT NULL,
      especialidad VARCHAR(180) DEFAULT NULL,
      cedula_profesional VARCHAR(40) DEFAULT NULL,
      email VARCHAR(120) NOT NULL UNIQUE,
      telefono VARCHAR(30) DEFAULT NULL,
      password_hash VARCHAR(255) NOT NULL,
      rol ENUM('nutriologo', 'recepcion', 'admin') NOT NULL DEFAULT 'nutriologo',
      color_agenda VARCHAR(10) DEFAULT '#0d9488',
      costo_primera_vez DECIMAL(10,2) DEFAULT 950.00,
      costo_seguimiento DECIMAL(10,2) DEFAULT 750.00,
      activo TINYINT(1) DEFAULT 1,
      creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pacientes (
      id INT AUTO_INCREMENT PRIMARY KEY,
      folio_expediente VARCHAR(20) NOT NULL UNIQUE,
      nutriologo_id INT NOT NULL,
      nombre_completo VARCHAR(150) NOT NULL,
      fecha_nacimiento DATE NOT NULL,
      sexo ENUM('F', 'M') NOT NULL,
      telefono_whatsapp VARCHAR(25) NOT NULL,
      email VARCHAR(120) DEFAULT NULL,
      objetivo_principal VARCHAR(180) NOT NULL,
      antecedentes_heredofamiliares TEXT DEFAULT NULL,
      antecedentes_patologicos TEXT DEFAULT NULL,
      alergias_intolerancias TEXT DEFAULT NULL,
      medicamentos_suplementos TEXT DEFAULT NULL,
      nivel_actividad_fisica ENUM('sedentario', 'ligero', 'moderado', 'activo', 'muy_activo') DEFAULT 'ligero',
      recordatorio_24h TEXT DEFAULT NULL,
      creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (nutriologo_id) REFERENCES usuarios(id) ON DELETE RESTRICT
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS citas (
      id INT AUTO_INCREMENT PRIMARY KEY,
      paciente_id INT DEFAULT NULL,
      nutriologo_id INT NOT NULL,
      nombre_prospecto VARCHAR(150) DEFAULT NULL,
      telefono_prospecto VARCHAR(25) DEFAULT NULL,
      email_prospecto VARCHAR(120) DEFAULT NULL,
      fecha_cita DATE NOT NULL,
      hora_inicio TIME NOT NULL,
      hora_fin TIME NOT NULL,
      tipo_cita ENUM('primera_vez', 'seguimiento', 'control_antropometrico', 'online') NOT NULL DEFAULT 'seguimiento',
      origen ENUM('recepcion', 'landing_web', 'whatsapp') NOT NULL DEFAULT 'recepcion',
      estado ENUM('pendiente_confirmar', 'confirmada', 'en_sala', 'en_consulta', 'finalizada', 'cancelada', 'no_asistio') NOT NULL DEFAULT 'confirmada',
      hora_llegada_sala TIME DEFAULT NULL,
      motivo_notas VARCHAR(255) DEFAULT NULL,
      creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (paciente_id) REFERENCES pacientes(id) ON DELETE SET NULL,
      FOREIGN KEY (nutriologo_id) REFERENCES usuarios(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS pagos_caja (
      id INT AUTO_INCREMENT PRIMARY KEY,
      folio_recibo VARCHAR(25) NOT NULL UNIQUE,
      cita_id INT DEFAULT NULL,
      paciente_id INT DEFAULT NULL,
      nutriologo_id INT NOT NULL,
      concepto VARCHAR(160) NOT NULL,
      monto DECIMAL(10,2) NOT NULL,
      descuento DECIMAL(10,2) DEFAULT 0.00,
      total_pagado DECIMAL(10,2) NOT NULL,
      metodo_pago ENUM('efectivo', 'tarjeta', 'transferencia') NOT NULL DEFAULT 'efectivo',
      registrado_por INT NOT NULL,
      fecha_pago DATETIME DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (nutriologo_id) REFERENCES usuarios(id) ON DELETE RESTRICT
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO usuarios (id, nombre, titulo, especialidad, cedula_profesional, email, telefono, password_hash, rol, color_agenda, costo_primera_vez, costo_seguimiento) VALUES
(1, 'Dra. Sofía Mendoza', 'L.N. y M.C. en Nutrición Clínica', 'Nutrición Clínica, Síndrome Metabólico, Diabetes y Salud Hormonal Femenina', 'Céd. Prof. 8492014', 'sofia.mendoza@nutricare.mx', '5512345678', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'nutriologo', '#0d9488', 950.00, 750.00),
(2, 'Dr. Alejandro Vega', 'L.N. y Esp. en Nutrición Deportiva (ISAK II)', 'Nutrición Deportiva, Recomposición Corporal, Hipertrofia y Alto Rendimiento', 'Céd. Prof. 9103842', 'alejandro.vega@nutricare.mx', '5587654321', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'nutriologo', '#2563eb', 1050.00, 800.00),
(3, 'Laura Gómez', 'Coordinadora de Recepción y Pacientes', 'Atención a Pacientes, Agenda Clínica y Caja', 'N/A', 'recepcion@nutricare.mx', '5544332211', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'recepcion', '#64748b', 0.00, 0.00);
