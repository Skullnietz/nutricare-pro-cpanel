<?php
session_start();
date_default_timezone_set('America/Mexico_City');

define('DB_HOST', 'localhost');
define('DB_NAME', 'tuusuario_nutricare_db');
define('DB_USER', 'tuusuario_admin');
define('DB_PASS', 'TuPasswordSeguro2026!');
define('CLINIC_NAME', 'Centro de Nutrición Integral NutriCare Pro');

function getDB(): PDO {
      static $pdo = null;
      if ($pdo === null) {
                $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
                $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                                           PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                                           PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                                           PDO::ATTR_EMULATE_PREPARES => false,
                                       ]);
      }
      return $pdo;
}

function csrf_token(): string {
      if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
      return $_SESSION['csrf_token'];
}

function verify_csrf(string $token): bool {
      return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function requireAuth(): array {
      if (empty($_SESSION['usuario'])) {
                $_SESSION['usuario'] = ['id' => 1, 'nombre' => 'Dra. Sofía Mendoza', 'rol' => 'nutriologo'];
      }
      return $_SESSION['usuario'];
}

function calcularEdad(string $fechaNacimiento): int {
      return (new DateTime())->diff(new DateTime($fechaNacimiento))->y;
}

function calcularGastoEnergetico(float $pesoKg, float $tallaCm, int $edad, string $sexo, string $actividad, string $formula = 'Mifflin-St Jeor', ?float $masaMagraKg = null): array {
      if ($formula === 'Katch-McArdle' && $masaMagraKg > 0) {
                $tmb = 370 + (21.6 * $masaMagraKg);
      } elseif ($formula === 'Harris-Benedict') {
                $tmb = ($sexo === 'M') ? 66.47 + (13.75 * $pesoKg) + (5.0 * $tallaCm) - (6.75 * $edad) : 655.1 + (9.56 * $pesoKg) + (1.85 * $tallaCm) - (4.68 * $edad);
      } else {
                $tmb = ($sexo === 'M') ? (10 * $pesoKg) + (6.25 * $tallaCm) - (5 * $edad) + 5 : (10 * $pesoKg) + (6.25 * $tallaCm) - (5 * $edad) - 161;
      }
      $factores = ['sedentario' => 1.2, 'ligero' => 1.375, 'moderado' => 1.55, 'activo' => 1.725, 'muy_activo' => 1.9];
      $faf = $factores[$actividad] ?? 1.375;
      $get = round(($tmb * $faf) + ($tmb * 0.10));
      return ['tmb' => (int)round($tmb), 'get' => (int)$get, 'faf' => $faf];
}

function generarLinkWhatsApp(string $telefono, string $nombrePaciente, string $fecha, string $hora, string $nombreDoctor): string {
      $telLimpio = preg_replace('/[^0-9]/', '', $telefono);
      if (strlen($telLimpio) === 10) $telLimpio = '521' . $telLimpio;
      $msg = "Hola {$nombrePaciente}, confirmamos tu consulta nutricional con {$nombreDoctor} el {$fecha} a las {$hora} hrs en " . CLINIC_NAME . ".";
      return "https://wa.me/{$telLimpio}?text=" . urlencode($msg);
}
?>
