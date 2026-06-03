<?php
class AuthController {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function login(): array {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $email    = trim($body['email'] ?? '');
        $password = $body['password'] ?? '';

        if (!$email || !$password) {
            return ['success' => false, 'message' => 'Correo y contraseña son requeridos.'];
        }

        if (!validateEmail($email)) {
            return ['success' => false, 'message' => 'Formato de correo inválido.'];
        }

        $attemptKey = 'login_attempts_' . md5($email);
        $attempts   = $_SESSION[$attemptKey] ?? 0;
        $lockUntil  = $_SESSION[$attemptKey . '_lock'] ?? 0;

        if ($lockUntil > time()) {
            $remaining = ceil(($lockUntil - time()) / 60);
            return ['success' => false, 'message' => "Cuenta bloqueada. Intenta en $remaining minuto(s)."];
        }

        $stmt = $this->db->prepare('SELECT id, name, email, password, role, is_active FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password'])) {
            $_SESSION[$attemptKey] = $attempts + 1;
            if ($_SESSION[$attemptKey] >= MAX_LOGIN_ATTEMPTS) {
                $_SESSION[$attemptKey . '_lock'] = time() + LOCKOUT_DURATION;
                $_SESSION[$attemptKey] = 0;
                return ['success' => false, 'message' => 'Demasiados intentos fallidos. Cuenta bloqueada temporalmente.'];
            }
            return ['success' => false, 'message' => 'Credenciales incorrectas.'];
        }

        if (!$user['is_active']) {
            return ['success' => false, 'message' => 'Tu cuenta está desactivada. Contacta al administrador.'];
        }

        unset($_SESSION[$attemptKey], $_SESSION[$attemptKey . '_lock']);
        session_regenerate_id(true);

        $_SESSION['user_id']   = $user['id'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['user_email']= $user['email'];

        if (password_needs_rehash($user['password'], PASSWORD_BCRYPT, ['cost' => BCRYPT_COST])) {
            $newHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
            $u = $this->db->prepare('UPDATE users SET password = ? WHERE id = ?');
            $u->execute([$newHash, $user['id']]);
        }

        return [
            'success' => true,
            'message' => 'Sesión iniciada correctamente.',
            'user'    => ['id' => $user['id'], 'name' => $user['name'], 'role' => $user['role'], 'email' => $user['email']],
        ];
    }

    public function register(): array {
        $body  = json_decode(file_get_contents('php://input'), true) ?? [];
        $name  = trim($body['name'] ?? '');
        $email = trim($body['email'] ?? '');
        $pass  = $body['password'] ?? '';
        $phone = trim($body['phone'] ?? '');

        if (!$name || !$email || !$pass) {
            return ['success' => false, 'message' => 'Nombre, correo y contraseña son requeridos.'];
        }
        if (strlen($name) < 2 || strlen($name) > 120) {
            return ['success' => false, 'message' => 'El nombre debe tener entre 2 y 120 caracteres.'];
        }
        if (!validateEmail($email)) {
            return ['success' => false, 'message' => 'Formato de correo inválido.'];
        }
        if (!validatePassword($pass)) {
            return ['success' => false, 'message' => 'La contraseña debe tener mínimo 8 caracteres, una mayúscula, una minúscula, un número y un carácter especial.'];
        }

        $check = $this->db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $check->execute([$email]);
        if ($check->fetch()) {
            return ['success' => false, 'message' => 'Este correo ya está registrado.'];
        }

        $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
        $stmt = $this->db->prepare('INSERT INTO users (name, email, password, phone, role) VALUES (?, ?, ?, ?, \'user\')');
        $stmt->execute([$name, $email, $hash, $phone ?: null]);
        $userId = (int)$this->db->lastInsertId();

        session_regenerate_id(true);
        $_SESSION['user_id']   = $userId;
        $_SESSION['user_role'] = 'user';
        $_SESSION['user_name'] = $name;
        $_SESSION['user_email']= $email;

        return [
            'success' => true,
            'message' => 'Cuenta creada correctamente.',
            'user'    => ['id' => $userId, 'name' => $name, 'role' => 'user', 'email' => $email],
        ];
    }

    public function logout(): array {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        return ['success' => true, 'message' => 'Sesión cerrada correctamente.'];
    }

    public function getSession(): array {
        if (!AuthMiddleware::check()) {
            return ['success' => false, 'authenticated' => false];
        }
        return [
            'success'       => true,
            'authenticated' => true,
            'user'          => [
                'id'    => (int)$_SESSION['user_id'],
                'name'  => $_SESSION['user_name'],
                'role'  => $_SESSION['user_role'],
                'email' => $_SESSION['user_email'] ?? '',
            ],
        ];
    }

    public function getCsrfToken(): array {
        return ['success' => true, 'token' => generateCsrfToken()];
    }
}
