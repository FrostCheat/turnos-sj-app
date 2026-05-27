<?php
// ============================================================
// CONTROLADOR DE AUTENTICACIÓN
// ============================================================

class AuthController {

    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    // ---- POST /login ----
    public function login(array $params, array $body): void {
        $email    = trim($body['email'] ?? '');
        $password = $body['password'] ?? '';

        // Validación básica
        if (!$email || !$password) {
            $this->responder(400, ['error' => 'Email y contraseña son requeridos']);
            return;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->responder(400, ['error' => 'Formato de email inválido']);
            return;
        }

        // Buscar usuario
        $stmt = $this->db->prepare(
            'SELECT id, nombre, email, password_hash, rol, activo, avatar_url FROM users WHERE email = ? LIMIT 1'
        );
        $stmt->execute([$email]);
        $usuario = $stmt->fetch();

        if (!$usuario || !password_verify($password, $usuario['password_hash'])) {
            $this->responder(401, ['error' => 'Credenciales inválidas']);
            return;
        }

        if (!$usuario['activo']) {
            $this->responder(403, ['error' => 'Cuenta desactivada. Contacta al administrador.']);
            return;
        }

        // Actualizar último login
        $this->db->prepare('UPDATE users SET ultimo_login = NOW() WHERE id = ?')->execute([$usuario['id']]);

        // Generar JWT
        $token = JWT::encode([
            'sub'  => $usuario['id'],
            'rol'  => $usuario['rol'],
            'nombre' => $usuario['nombre'],
        ]);

        unset($usuario['password_hash']);
        $this->responder(200, [
            'mensaje' => 'Login exitoso',
            'token'   => $token,
            'usuario' => $usuario,
        ]);
    }

    // ---- POST /register ----
    public function register(array $params, array $body): void {
        $nombre   = trim($body['nombre'] ?? '');
        $email    = trim($body['email'] ?? '');
        $password = $body['password'] ?? '';

        // Validaciones
        $errores = [];
        if (strlen($nombre) < 2)  $errores[] = 'El nombre debe tener al menos 2 caracteres';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errores[] = 'Email inválido';
        if (strlen($password) < 8) $errores[] = 'La contraseña debe tener al menos 8 caracteres';
        if (!preg_match('/[A-Z]/', $password)) $errores[] = 'La contraseña debe contener al menos una mayúscula';
        if (!preg_match('/[0-9]/', $password)) $errores[] = 'La contraseña debe contener al menos un número';

        if ($errores) {
            $this->responder(422, ['error' => 'Validación fallida', 'detalles' => $errores]);
            return;
        }

        // Verificar email único
        $stmt = $this->db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $this->responder(409, ['error' => 'El email ya está registrado']);
            return;
        }

        // Crear usuario
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
        $stmt = $this->db->prepare(
            'INSERT INTO users (nombre, email, password_hash, rol) VALUES (?, ?, ?, "user")'
        );
        $stmt->execute([$nombre, $email, $hash]);
        $nuevoId = (int) $this->db->lastInsertId();

        // Notificación de bienvenida
        $this->db->prepare(
            'INSERT INTO notifications (user_id, titulo, mensaje, tipo) VALUES (?, ?, ?, "exito")'
        )->execute([$nuevoId, '¡Bienvenido a NovaSphere!', 'Tu cuenta ha sido creada exitosamente. ¡Explora el contenido!']);

        // Generar JWT
        $token = JWT::encode(['sub' => $nuevoId, 'rol' => 'user', 'nombre' => $nombre]);

        $this->responder(201, [
            'mensaje' => 'Cuenta creada exitosamente',
            'token'   => $token,
            'usuario' => ['id' => $nuevoId, 'nombre' => $nombre, 'email' => $email, 'rol' => 'user'],
        ]);
    }

    // ---- POST /logout ----
    public function logout(array $params, array $body): void {
        // Con JWT stateless, el logout se maneja en el frontend eliminando el token.
        // Aquí podríamos agregar el token a una blacklist en BD si se requiere invalidación inmediata.
        $this->responder(200, ['mensaje' => 'Sesión cerrada exitosamente']);
    }

    private function responder(int $codigo, array $datos): void {
        http_response_code($codigo);
        echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    }
}
