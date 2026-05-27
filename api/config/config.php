<?php
// ============================================================
// CONFIGURACIÓN GLOBAL - NovaSphere
// ============================================================

define('APP_NAME', 'NovaSphere');
define('APP_VERSION', '1.0.0');
define('APP_URL', 'http://localhost');
define('FRONTEND_URL', 'http://localhost');

// ---- Base de datos ----
define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'novasphere');
define('DB_USER', 'root');       // Cambiar en producción
define('DB_PASS', '');           // Cambiar en producción
define('DB_CHARSET', 'utf8mb4');

// ---- JWT ----
define('JWT_SECRET', 'cambiar_este_secreto_en_produccion_2024!');
define('JWT_EXPIRE', 86400);     // 24 horas en segundos
define('JWT_ALGO', 'HS256');

// ---- Seguridad ----
define('BCRYPT_COST', 12);
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_TIME', 900);     // 15 minutos

// ---- Paginación ----
define('ITEMS_PER_PAGE', 12);

// ---- Entorno ----
define('APP_ENV', 'development'); // 'production' en producción
define('DEBUG', true);

// ---- Zona horaria ----
date_default_timezone_set('America/Bogota');
