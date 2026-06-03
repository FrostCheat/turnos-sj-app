<?php
class CorsMiddleware {
    public static function handle(): void {
        $allowedOrigins = [APP_URL, 'http://127.0.0.1', 'http://localhost'];
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if (in_array($origin, $allowedOrigins, true)) {
            header("Access-Control-Allow-Origin: $origin");
        } else {
            header("Access-Control-Allow-Origin: " . APP_URL);
        }
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token, Authorization');
        header('Access-Control-Allow-Credentials: true');
        header('Vary: Origin');
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }
}
