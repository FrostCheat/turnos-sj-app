<?php
// ============================================================
// JWT HELPER - Generación y Validación de Tokens
// ============================================================

require_once __DIR__ . '/../config/config.php';

class JWT {

    /**
     * Codifica un payload en un token JWT
     */
    public static function encode(array $payload): string {
        $header = self::base64UrlEncode(json_encode([
            'typ' => 'JWT',
            'alg' => JWT_ALGO
        ]));

        // Agregar tiempos estándar
        $payload['iat'] = time();
        $payload['exp'] = time() + JWT_EXPIRE;

        $payloadEncoded = self::base64UrlEncode(json_encode($payload));
        $firma = self::firmar("$header.$payloadEncoded");

        return "$header.$payloadEncoded.$firma";
    }

    /**
     * Decodifica y valida un token JWT
     * @throws RuntimeException si el token es inválido o expirado
     */
    public static function decode(string $token): array {
        $partes = explode('.', $token);

        if (count($partes) !== 3) {
            throw new RuntimeException('Token con formato inválido');
        }

        [$header, $payload, $firma] = $partes;

        // Verificar firma
        $firmaEsperada = self::firmar("$header.$payload");
        if (!hash_equals($firmaEsperada, $firma)) {
            throw new RuntimeException('Firma del token inválida');
        }

        $datos = json_decode(self::base64UrlDecode($payload), true);

        if (!$datos) {
            throw new RuntimeException('Payload del token inválido');
        }

        // Verificar expiración
        if (isset($datos['exp']) && $datos['exp'] < time()) {
            throw new RuntimeException('Token expirado');
        }

        return $datos;
    }

    /**
     * Extrae el token del header Authorization: Bearer <token>
     */
    public static function extraerDelHeader(): ?string {
        $headers = getallheaders();
        $auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';

        if (preg_match('/Bearer\s+(.+)$/i', $auth, $matches)) {
            return $matches[1];
        }
        return null;
    }

    // ---- Utilidades privadas ----

    private static function firmar(string $dato): string {
        return self::base64UrlEncode(
            hash_hmac('sha256', $dato, JWT_SECRET, true)
        );
    }

    private static function base64UrlEncode(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string {
        return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
    }
}
