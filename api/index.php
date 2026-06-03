<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/middleware/CorsMiddleware.php';
require_once __DIR__ . '/middleware/CsrfMiddleware.php';
require_once __DIR__ . '/middleware/AuthMiddleware.php';
require_once __DIR__ . '/middleware/AdminMiddleware.php';
require_once __DIR__ . '/controllers/AuthController.php';
require_once __DIR__ . '/controllers/UserController.php';
require_once __DIR__ . '/controllers/QueueController.php';
require_once __DIR__ . '/controllers/DashboardController.php';
require_once __DIR__ . '/controllers/NotificationController.php';

ini_set('session.cookie_httponly', '1');
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_samesite', 'Lax');

session_name(SESSION_NAME);
session_start();

setSecureHeaders();
CorsMiddleware::handle();

$action = trim($_GET['action'] ?? '');

if ($action !== 'sse_queue') {
    header('Content-Type: application/json; charset=utf-8');
}

CsrfMiddleware::handle($action);

if (!$action) {
    echo json_encode(['success' => false, 'message' => 'Acción requerida.']);
    exit;
}

try {
    $db      = getDbConnection();
    $auth    = new AuthController($db);
    $user    = new UserController($db);
    $queue   = new QueueController($db);
    $dash    = new DashboardController($db);
    $notify  = new NotificationController($db);

    $result = match($action) {
        'login'               => $auth->login(),
        'register'            => $auth->register(),
        'logout'              => $auth->logout(),
        'get_session'         => $auth->getSession(),
        'csrf_token'          => $auth->getCsrfToken(),
        'get_user'            => $user->getUser(),
        'get_users'           => $user->getUsers(),
        'create_user'         => $user->createUser(),
        'update_user'         => $user->updateUser(),
        'delete_user'         => $user->deleteUser(),
        'get_queue'           => $queue->getQueue(),
        'get_turn'            => $queue->getTurn(),
        'get_turns'           => $queue->getTurns(),
        'create_turn'         => $queue->createTurn(),
        'update_turn'         => $queue->updateTurn(),
        'delete_turn'         => $queue->deleteTurn(),
        'activate_next'       => $queue->activateNext(),
        'complete_turn'       => $queue->completeTurn(),
        'cancel_turn'         => $queue->cancelTurn(),
        'reorder_queue'       => $queue->reorderQueue(),
        'increase_turn'       => $queue->adjustCurrentTurn(1),
        'decrease_turn'       => $queue->adjustCurrentTurn(-1),
        'get_services'        => $queue->getServices(),
        'get_dashboard'       => $dash->getDashboard(),
        'sse_queue'           => (function() use ($notify): never { $notify->sseQueue(); exit; })(),
        default               => ['success' => false, 'message' => "Acción '$action' no reconocida."],
    };

    echo json_encode($result, JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error de base de datos. Por favor intenta de nuevo.']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error interno del servidor.']);
}
