<?php
class NotificationController {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function sseQueue(): void {
        if (!AuthMiddleware::check()) {
            http_response_code(401);
            echo "data: " . json_encode(['error' => 'No autenticado']) . "\n\n";
            flush();
            return;
        }

        $userId  = (int)$_SESSION['user_id'];
        $isAdmin = $_SESSION['user_role'] === 'admin';

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        $lastUpdate = '';
        $keepAliveCount = 0;

        while (true) {
            if (connection_aborted()) break;

            $state = $this->db->query('SELECT current_turn, last_updated FROM queue_state WHERE id = 1')->fetch();
            $currentUpdate = $state['last_updated'] ?? '';

            if ($currentUpdate !== $lastUpdate) {
                $lastUpdate = $currentUpdate;

                $active = $this->db->query("
                    SELECT t.id, t.turn_number, t.service, t.status, t.called_at, u.name as user_name
                    FROM turns t JOIN users u ON u.id = t.user_id
                    WHERE t.status = 'active' ORDER BY t.called_at DESC LIMIT 1
                ")->fetch();

                $waitingCount = (int)$this->db->query("SELECT COUNT(*) FROM turns WHERE status='waiting'")->fetchColumn();
                $currentTurn  = $state ? (int)$state['current_turn'] : 0;

                $payload = [
                    'type'          => 'queue_update',
                    'current_turn'  => $currentTurn,
                    'active_turn'   => $active ?: null,
                    'waiting_count' => $waitingCount,
                    'timestamp'     => time(),
                ];

                if (!$isAdmin) {
                    $myTurn = $this->db->prepare("
                        SELECT id, turn_number, service, status, position, called_at
                        FROM turns WHERE user_id = ? AND status IN ('waiting','active')
                        ORDER BY created_at DESC LIMIT 1
                    ");
                    $myTurn->execute([$userId]);
                    $payload['my_turn'] = $myTurn->fetch() ?: null;
                }

                echo "data: " . json_encode($payload) . "\n\n";
                ob_flush();
                flush();
            }

            $keepAliveCount++;
            if ($keepAliveCount >= 15) {
                echo ": keepalive\n\n";
                ob_flush();
                flush();
                $keepAliveCount = 0;
            }

            sleep(SSE_SLEEP);
        }
    }
}
