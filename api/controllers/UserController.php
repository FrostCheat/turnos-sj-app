<?php
class UserController {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function getUser(): array {
        $user  = AuthMiddleware::handle();
        $id    = isset($_GET['id']) ? (int)$_GET['id'] : $user['id'];
        if ($id !== $user['id'] && $user['role'] !== 'admin') {
            return ['success' => false, 'message' => 'Acceso denegado.'];
        }
        $stmt = $this->db->prepare('SELECT id, name, email, phone, role, is_active, created_at FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) return ['success' => false, 'message' => 'Usuario no encontrado.'];
        return ['success' => true, 'user' => $row];
    }

    public function getUsers(): array {
        AdminMiddleware::handle();
        $search  = trim($_GET['search'] ?? '');
        $page    = max(1, (int)($_GET['page'] ?? 1));
        $perPage = min(50, max(10, (int)($_GET['per_page'] ?? 20)));
        $offset  = ($page - 1) * $perPage;

        $where  = '';
        $params = [];
        if ($search !== '') {
            $where    = 'WHERE (name LIKE ? OR email LIKE ? OR phone LIKE ?)';
            $like     = "%$search%";
            $params   = [$like, $like, $like];
        }

        $total = $this->db->prepare("SELECT COUNT(*) FROM users $where");
        $total->execute($params);
        $count = (int)$total->fetchColumn();

        $stmt = $this->db->prepare("SELECT id, name, email, phone, role, is_active, created_at FROM users $where ORDER BY created_at DESC LIMIT $perPage OFFSET $offset");
        $stmt->execute($params);
        $users = $stmt->fetchAll();

        return [
            'success' => true,
            'users'   => $users,
            'total'   => $count,
            'page'    => $page,
            'pages'   => (int)ceil($count / $perPage),
        ];
    }

    public function createUser(): array {
        AdminMiddleware::handle();
        $body  = json_decode(file_get_contents('php://input'), true) ?? [];
        $name  = trim($body['name'] ?? '');
        $email = trim($body['email'] ?? '');
        $pass  = $body['password'] ?? '';
        $phone = trim($body['phone'] ?? '');
        $role  = in_array($body['role'] ?? '', ['admin','user']) ? $body['role'] : 'user';

        if (!$name || !$email || !$pass) {
            return ['success' => false, 'message' => 'Nombre, correo y contraseña son requeridos.'];
        }
        if (!validateEmail($email)) return ['success' => false, 'message' => 'Correo inválido.'];
        if (!validatePassword($pass)) return ['success' => false, 'message' => 'Contraseña no cumple requisitos de seguridad.'];

        $check = $this->db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $check->execute([$email]);
        if ($check->fetch()) return ['success' => false, 'message' => 'Este correo ya está registrado.'];

        $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
        $stmt = $this->db->prepare('INSERT INTO users (name, email, password, phone, role) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$name, $email, $hash, $phone ?: null, $role]);

        return ['success' => true, 'message' => 'Usuario creado correctamente.', 'id' => (int)$this->db->lastInsertId()];
    }

    public function updateUser(): array {
        $auth  = AuthMiddleware::handle();
        $body  = json_decode(file_get_contents('php://input'), true) ?? [];
        $id    = (int)($body['id'] ?? 0);

        if ($id !== $auth['id'] && $auth['role'] !== 'admin') {
            return ['success' => false, 'message' => 'Acceso denegado.'];
        }
        if (!$id) return ['success' => false, 'message' => 'ID requerido.'];

        $stmt = $this->db->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        if (!$stmt->fetch()) return ['success' => false, 'message' => 'Usuario no encontrado.'];

        $fields = [];
        $params = [];

        if (!empty($body['name'])) {
            $name = trim($body['name']);
            if (strlen($name) < 2) return ['success' => false, 'message' => 'Nombre demasiado corto.'];
            $fields[] = 'name = ?';
            $params[] = $name;
        }
        if (!empty($body['email']) && $auth['role'] === 'admin') {
            $email = trim($body['email']);
            if (!validateEmail($email)) return ['success' => false, 'message' => 'Correo inválido.'];
            $dup = $this->db->prepare('SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1');
            $dup->execute([$email, $id]);
            if ($dup->fetch()) return ['success' => false, 'message' => 'Correo ya en uso.'];
            $fields[] = 'email = ?';
            $params[] = $email;
        }
        if (isset($body['phone'])) {
            $fields[] = 'phone = ?';
            $params[] = trim($body['phone']) ?: null;
        }
        if (!empty($body['password'])) {
            if (!validatePassword($body['password'])) return ['success' => false, 'message' => 'Contraseña no cumple requisitos.'];
            $fields[] = 'password = ?';
            $params[] = password_hash($body['password'], PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
        }
        if (isset($body['is_active']) && $auth['role'] === 'admin') {
            $fields[] = 'is_active = ?';
            $params[] = (int)(bool)$body['is_active'];
        }
        if (isset($body['role']) && $auth['role'] === 'admin') {
            if (in_array($body['role'], ['admin','user'])) {
                $fields[] = 'role = ?';
                $params[] = $body['role'];
            }
        }

        if (!$fields) return ['success' => false, 'message' => 'No hay datos para actualizar.'];

        $params[] = $id;
        $sql = 'UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?';
        $this->db->prepare($sql)->execute($params);

        if (isset($body['name']) && (int)$_SESSION['user_id'] === $id) {
            $_SESSION['user_name'] = trim($body['name']);
        }

        return ['success' => true, 'message' => 'Usuario actualizado correctamente.'];
    }

    public function deleteUser(): array {
        AdminMiddleware::handle();
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $id   = (int)($body['id'] ?? 0);
        if (!$id) return ['success' => false, 'message' => 'ID requerido.'];
        if ($id === (int)$_SESSION['user_id']) return ['success' => false, 'message' => 'No puedes eliminar tu propia cuenta.'];

        $stmt = $this->db->prepare('DELETE FROM users WHERE id = ?');
        $stmt->execute([$id]);
        if (!$stmt->rowCount()) return ['success' => false, 'message' => 'Usuario no encontrado.'];

        return ['success' => true, 'message' => 'Usuario eliminado correctamente.'];
    }
}
