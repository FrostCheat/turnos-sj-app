<?php
class QueueController {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    private function recalcPositions(): void {
        $this->db->exec("SET @pos = 0");
        $this->db->exec("
            UPDATE turns SET position = (@pos := @pos + 1)
            WHERE status = 'waiting'
            ORDER BY COALESCE(position, 9999) ASC, created_at ASC
        ");
    }

    public function getQueue(): array {
        $auth = AuthMiddleware::handle();
        $isAdmin = $auth['role'] === 'admin';

        $active = $this->db->query("
            SELECT t.id, t.turn_number, t.service, t.status, t.position, t.created_at, t.called_at,
                   u.name as user_name
            FROM turns t
            JOIN users u ON u.id = t.user_id
            WHERE t.status = 'active'
            ORDER BY t.called_at DESC
            LIMIT 1
        ")->fetch();

        $waitingCount = (int)$this->db->query("SELECT COUNT(*) FROM turns WHERE status = 'waiting'")->fetchColumn();

        $state = $this->db->query('SELECT current_turn FROM queue_state WHERE id = 1')->fetch();
        $currentTurn = $state ? (int)$state['current_turn'] : 0;

        $result = [
            'success'       => true,
            'current_turn'  => $currentTurn,
            'active_turn'   => $active ?: null,
            'waiting_count' => $waitingCount,
        ];

        if ($isAdmin) {
            $turns = $this->db->query("
                SELECT t.id, t.turn_number, t.service, t.status, t.position, t.notes,
                       t.created_at, t.called_at, t.completed_at,
                       u.id as user_id, u.name as user_name, u.email as user_email
                FROM turns t
                JOIN users u ON u.id = t.user_id
                ORDER BY
                    FIELD(t.status,'active','waiting','completed','cancelled'),
                    t.position ASC,
                    t.created_at ASC
            ")->fetchAll();
            $result['turns'] = $turns;
        } else {
            $myTurn = $this->db->prepare("
                SELECT t.id, t.turn_number, t.service, t.status, t.position, t.notes, t.created_at, t.called_at
                FROM turns t
                WHERE t.user_id = ? AND t.status IN ('waiting','active')
                ORDER BY t.created_at DESC
                LIMIT 1
            ");
            $myTurn->execute([$auth['id']]);
            $result['my_turn'] = $myTurn->fetch() ?: null;
        }

        return $result;
    }

    public function getTurn(): array {
        $auth = AuthMiddleware::handle();
        $id   = (int)($_GET['id'] ?? 0);
        if (!$id) return ['success' => false, 'message' => 'ID requerido.'];

        $stmt = $this->db->prepare("
            SELECT t.id, t.turn_number, t.service, t.status, t.position, t.notes,
                   t.created_at, t.called_at, t.completed_at,
                   u.id as user_id, u.name as user_name, u.email as user_email
            FROM turns t
            JOIN users u ON u.id = t.user_id
            WHERE t.id = ? LIMIT 1
        ");
        $stmt->execute([$id]);
        $turn = $stmt->fetch();
        if (!$turn) return ['success' => false, 'message' => 'Turno no encontrado.'];

        if ((int)$turn['user_id'] !== $auth['id'] && $auth['role'] !== 'admin') {
            return ['success' => false, 'message' => 'Acceso denegado.'];
        }

        return ['success' => true, 'turn' => $turn];
    }

    public function getTurns(): array {
        AdminMiddleware::handle();
        $search  = trim($_GET['search'] ?? '');
        $status  = $_GET['status'] ?? '';
        $page    = max(1, (int)($_GET['page'] ?? 1));
        $perPage = min(100, max(10, (int)($_GET['per_page'] ?? 20)));
        $offset  = ($page - 1) * $perPage;

        $where  = [];
        $params = [];

        if ($search !== '') {
            $where[]  = '(u.name LIKE ? OR CAST(t.turn_number AS CHAR) LIKE ? OR t.service LIKE ?)';
            $like     = "%$search%";
            $params   = array_merge($params, [$like, $like, $like]);
        }
        if (in_array($status, ['waiting','active','completed','cancelled'])) {
            $where[]  = 't.status = ?';
            $params[] = $status;
        }

        $whereStr = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $total = $this->db->prepare("SELECT COUNT(*) FROM turns t JOIN users u ON u.id = t.user_id $whereStr");
        $total->execute($params);
        $count = (int)$total->fetchColumn();

        $stmt = $this->db->prepare("
            SELECT t.id, t.turn_number, t.service, t.status, t.position, t.notes,
                   t.created_at, t.called_at, t.completed_at,
                   u.id as user_id, u.name as user_name, u.email as user_email
            FROM turns t
            JOIN users u ON u.id = t.user_id
            $whereStr
            ORDER BY FIELD(t.status,'active','waiting','completed','cancelled'), t.position ASC, t.created_at ASC
            LIMIT $perPage OFFSET $offset
        ");
        $stmt->execute($params);

        return [
            'success' => true,
            'turns'   => $stmt->fetchAll(),
            'total'   => $count,
            'page'    => $page,
            'pages'   => (int)ceil($count / $perPage),
        ];
    }

    public function createTurn(): array {
        $auth    = AuthMiddleware::handle();
        $body    = json_decode(file_get_contents('php://input'), true) ?? [];
        $service = trim($body['service'] ?? 'Atención General');
        $notes   = trim($body['notes'] ?? '');
        $userId  = $auth['role'] === 'admin' && isset($body['user_id']) ? (int)$body['user_id'] : $auth['id'];

        if (!in_array($service, ALLOWED_SERVICES)) {
            $service = 'Atención General';
        }

        $existing = $this->db->prepare("SELECT id FROM turns WHERE user_id = ? AND status IN ('waiting','active') LIMIT 1");
        $existing->execute([$userId]);
        if ($existing->fetch()) {
            return ['success' => false, 'message' => 'Ya tienes un turno activo o en espera.'];
        }

        $max = (int)$this->db->query('SELECT COALESCE(MAX(turn_number),0) FROM turns WHERE DATE(created_at) = CURDATE()')->fetchColumn();
        $turnNumber = $max + 1;

        $pos = (int)$this->db->query("SELECT COALESCE(MAX(position),0) FROM turns WHERE status = 'waiting'")->fetchColumn() + 1;

        $stmt = $this->db->prepare('INSERT INTO turns (user_id, turn_number, service, notes, position) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$userId, $turnNumber, $service, $notes ?: null, $pos]);
        $id = (int)$this->db->lastInsertId();

        $this->db->prepare('UPDATE queue_state SET last_updated = NOW() WHERE id = 1')->execute();

        return ['success' => true, 'message' => 'Turno creado correctamente.', 'id' => $id, 'turn_number' => $turnNumber, 'position' => $pos];
    }

    public function updateTurn(): array {
        $auth = AuthMiddleware::handle();
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $id   = (int)($body['id'] ?? 0);
        if (!$id) return ['success' => false, 'message' => 'ID requerido.'];

        $stmt = $this->db->prepare("SELECT * FROM turns WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $turn = $stmt->fetch();
        if (!$turn) return ['success' => false, 'message' => 'Turno no encontrado.'];

        if ((int)$turn['user_id'] !== $auth['id'] && $auth['role'] !== 'admin') {
            return ['success' => false, 'message' => 'Acceso denegado.'];
        }

        $fields = [];
        $params = [];

        if (isset($body['service']) && $auth['role'] === 'admin') {
            $svc = trim($body['service']);
            if (in_array($svc, ALLOWED_SERVICES)) {
                $fields[] = 'service = ?';
                $params[] = $svc;
            }
        }
        if (isset($body['notes'])) {
            $fields[] = 'notes = ?';
            $params[] = trim($body['notes']) ?: null;
        }
        if (isset($body['status']) && $auth['role'] === 'admin') {
            if (in_array($body['status'], ['waiting','active','completed','cancelled'])) {
                $fields[] = 'status = ?';
                $params[] = $body['status'];
                if ($body['status'] === 'active') {
                    $fields[] = 'called_at = NOW()';
                    $fields[] = 'position = NULL';
                    $this->db->prepare('UPDATE queue_state SET current_turn = ?, last_updated = NOW() WHERE id = 1')->execute([$turn['turn_number']]);
                }
                if (in_array($body['status'], ['completed','cancelled'])) {
                    $fields[] = 'completed_at = NOW()';
                    $fields[] = 'position = NULL';
                }
            }
        }

        if (!$fields) return ['success' => false, 'message' => 'Sin cambios.'];

        $params[] = $id;
        $this->db->prepare('UPDATE turns SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);

        if (in_array($body['status'] ?? '', ['completed','cancelled','waiting'])) {
            $this->recalcPositions();
        }

        $this->db->prepare('UPDATE queue_state SET last_updated = NOW() WHERE id = 1')->execute();

        return ['success' => true, 'message' => 'Turno actualizado.'];
    }

    public function deleteTurn(): array {
        AdminMiddleware::handle();
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $id   = (int)($body['id'] ?? 0);
        if (!$id) return ['success' => false, 'message' => 'ID requerido.'];

        $stmt = $this->db->prepare('DELETE FROM turns WHERE id = ?');
        $stmt->execute([$id]);
        if (!$stmt->rowCount()) return ['success' => false, 'message' => 'Turno no encontrado.'];

        $this->recalcPositions();
        $this->db->prepare('UPDATE queue_state SET last_updated = NOW() WHERE id = 1')->execute();

        return ['success' => true, 'message' => 'Turno eliminado.'];
    }

    public function activateNext(): array {
        AdminMiddleware::handle();

        $active = $this->db->query("SELECT id FROM turns WHERE status = 'active' LIMIT 1")->fetch();
        if ($active) {
            $this->db->prepare("UPDATE turns SET status = 'completed', completed_at = NOW(), position = NULL WHERE id = ?")->execute([$active['id']]);
        }

        $next = $this->db->query("SELECT id, turn_number FROM turns WHERE status = 'waiting' ORDER BY position ASC, created_at ASC LIMIT 1")->fetch();
        if (!$next) {
            $this->recalcPositions();
            $this->db->prepare('UPDATE queue_state SET last_updated = NOW() WHERE id = 1')->execute();
            return ['success' => false, 'message' => 'No hay turnos en espera.'];
        }

        $this->db->prepare("UPDATE turns SET status = 'active', called_at = NOW(), position = NULL WHERE id = ?")->execute([$next['id']]);
        $this->db->prepare('UPDATE queue_state SET current_turn = ?, last_updated = NOW() WHERE id = 1')->execute([$next['turn_number']]);
        $this->recalcPositions();

        return ['success' => true, 'message' => 'Siguiente turno activado.', 'turn_number' => $next['turn_number']];
    }

    public function completeTurn(): array {
        AdminMiddleware::handle();
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $id   = (int)($body['id'] ?? 0);
        if (!$id) return ['success' => false, 'message' => 'ID requerido.'];

        $this->db->prepare("UPDATE turns SET status = 'completed', completed_at = NOW(), position = NULL WHERE id = ? AND status = 'active'")->execute([$id]);
        $this->recalcPositions();
        $this->db->prepare('UPDATE queue_state SET last_updated = NOW() WHERE id = 1')->execute();

        return ['success' => true, 'message' => 'Turno completado.'];
    }

    public function cancelTurn(): array {
        $auth = AuthMiddleware::handle();
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $id   = (int)($body['id'] ?? 0);
        if (!$id) return ['success' => false, 'message' => 'ID requerido.'];

        $stmt = $this->db->prepare("SELECT user_id FROM turns WHERE id = ? AND status IN ('waiting','active') LIMIT 1");
        $stmt->execute([$id]);
        $turn = $stmt->fetch();
        if (!$turn) return ['success' => false, 'message' => 'Turno no encontrado o no puede cancelarse.'];

        if ((int)$turn['user_id'] !== $auth['id'] && $auth['role'] !== 'admin') {
            return ['success' => false, 'message' => 'Acceso denegado.'];
        }

        $this->db->prepare("UPDATE turns SET status = 'cancelled', completed_at = NOW(), position = NULL WHERE id = ?")->execute([$id]);
        $this->recalcPositions();
        $this->db->prepare('UPDATE queue_state SET last_updated = NOW() WHERE id = 1')->execute();

        return ['success' => true, 'message' => 'Turno cancelado.'];
    }

    public function reorderQueue(): array {
        AdminMiddleware::handle();
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $ids  = $body['ids'] ?? [];

        if (!is_array($ids) || empty($ids)) {
            return ['success' => false, 'message' => 'IDs requeridos.'];
        }

        $stmt = $this->db->prepare("UPDATE turns SET position = ? WHERE id = ? AND status = 'waiting'");
        foreach ($ids as $pos => $id) {
            $stmt->execute([$pos + 1, (int)$id]);
        }

        $this->db->prepare('UPDATE queue_state SET last_updated = NOW() WHERE id = 1')->execute();
        return ['success' => true, 'message' => 'Cola reorganizada.'];
    }

    public function advanceTurn(): array {
        AdminMiddleware::handle();

        $activeRow = $this->db->query("SELECT id, turn_number FROM turns WHERE status = 'active' LIMIT 1")->fetch();
        if ($activeRow) {
            $this->db->prepare("UPDATE turns SET status = 'completed', completed_at = NOW(), position = NULL WHERE id = ?")->execute([$activeRow['id']]);
        }

        $next = $this->db->query("SELECT id, turn_number FROM turns WHERE status = 'waiting' ORDER BY position ASC, created_at ASC LIMIT 1")->fetch();

        if ($next) {
            $this->db->prepare("UPDATE turns SET status = 'active', called_at = NOW(), position = NULL WHERE id = ?")->execute([$next['id']]);
            $this->db->prepare('UPDATE queue_state SET current_turn = ?, last_updated = NOW() WHERE id = 1')->execute([$next['turn_number']]);
            $this->recalcPositions();
            return ['success' => true, 'message' => 'Turno avanzado.', 'current_turn' => (int)$next['turn_number']];
        }

        $state = $this->db->query('SELECT current_turn FROM queue_state WHERE id = 1')->fetch();
        $newVal = (int)$state['current_turn'] + 1;
        $this->db->prepare('UPDATE queue_state SET current_turn = ?, last_updated = NOW() WHERE id = 1')->execute([$newVal]);
        $this->recalcPositions();
        return ['success' => true, 'message' => 'Sin más turnos en espera.', 'current_turn' => $newVal];
    }

    public function regressTurn(): array {
        AdminMiddleware::handle();

        $activeRow = $this->db->query("SELECT id, turn_number FROM turns WHERE status = 'active' LIMIT 1")->fetch();
        if ($activeRow) {
            $maxPos = (int)$this->db->query("SELECT COALESCE(MAX(position),0) FROM turns WHERE status = 'waiting'")->fetchColumn();
            $this->db->prepare("UPDATE turns SET status = 'waiting', called_at = NULL, position = ?, completed_at = NULL WHERE id = ?")->execute([$maxPos + 1, $activeRow['id']]);
        }

        $prev = $this->db->query("SELECT id, turn_number FROM turns WHERE status = 'completed' ORDER BY completed_at DESC LIMIT 1")->fetch();

        if ($prev) {
            $this->db->prepare("UPDATE turns SET status = 'active', called_at = NOW(), completed_at = NULL, position = NULL WHERE id = ?")->execute([$prev['id']]);
            $this->db->prepare('UPDATE queue_state SET current_turn = ?, last_updated = NOW() WHERE id = 1')->execute([$prev['turn_number']]);
            $this->recalcPositions();
            return ['success' => true, 'message' => 'Turno retrocedido.', 'current_turn' => (int)$prev['turn_number']];
        }

        $state = $this->db->query('SELECT current_turn FROM queue_state WHERE id = 1')->fetch();
        $newVal = max(0, (int)$state['current_turn'] - 1);
        $this->db->prepare('UPDATE queue_state SET current_turn = ?, last_updated = NOW() WHERE id = 1')->execute([$newVal]);
        $this->recalcPositions();
        return ['success' => true, 'message' => 'Sin turnos completados para retroceder.', 'current_turn' => $newVal];
    }

    public function getServices(): array {
        return ['success' => true, 'services' => ALLOWED_SERVICES];
    }
}