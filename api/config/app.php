<?php
define('APP_NAME', 'Secretaría Santa Juana');
define('APP_VERSION', '1.0.0');
define('APP_URL', 'http://localhost');
define('APP_ENV', 'production');
define('SESSION_NAME', 'ssj_session');
define('SESSION_LIFETIME', 7200);
define('BCRYPT_COST', 12);
define('SSE_RETRY', 3000);
define('SSE_SLEEP', 2);
define('QUEUE_MAX_TURNS', 999);
define('ALLOWED_SERVICES', [
    'Atención General',
    'Trámites Documentales',
    'Certificados',
    'Consultas',
    'Pagos y Recaudos',
    'Orientación Ciudadana',
]);
