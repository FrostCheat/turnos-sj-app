<?php
class CorsMiddleware {
    private static array $allowed = [
        'https://turnos-sj.great-site.net',
        'http://turnos-sj.great-site.net',
        'http://localhost',
        'http://127.0.0.1',
    ];

    public static function handle(): void {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

        if (in_array($origin, self::$allowed, true)) {
            header("Access-Control-Allow-Origin: $origin");
        } else {
            header('Access-Control-Allow-Origin: https://turnos-sj.great-site.net');
        }

        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Max-Age: 86400');
        header('Vary: Origin');

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit;
        }
    }
}