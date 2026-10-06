<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';
// O painel trabalha apenas com sessões autenticadas e contas administrativas.
start_app_session();

try {
    $pdo = database();
} catch (Throwable $error) {
    error_log('Admin database connection failed: ' . $error->getMessage());
    json_response(500, ['error' => 'O painel está indisponível no momento.']);
}

$admin = current_user($pdo);
require_admin($admin);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Retorna sessões recentes e membros moderados que ainda precisam de ação.
    try {
        cleanup_expired_data($pdo);
        $statement = $pdo->query(
            'SELECT u.id, u.username, u.email, u.is_blocked, u.is_muted,
                    s.id AS session_id, s.last_seen
             FROM users u
             LEFT JOIN user_sessions s
               ON s.user_id = u.id
              AND s.last_seen >= UTC_TIMESTAMP() - INTERVAL 90 SECOND
             WHERE u.role = "member"
               AND (
                   s.id IS NOT NULL
                   OR u.is_blocked = 1
                   OR u.is_muted = 1
               )
             ORDER BY s.last_seen DESC, u.username ASC'
        );
        $participants = array_map(static function (array $row): array {
            return [
                'userId' => (int) $row['id'],
                'name' => $row['username'],
                'email' => $row['email'],
                'blocked' => (bool) $row['is_blocked'],
                'muted' => (bool) $row['is_muted'],
                'sessionId' => $row['session_id'] === null ? null : (int) $row['session_id'],
                'lastSeen' => $row['last_seen']
            ];
        }, $statement->fetchAll());
        json_response(200, ['participants' => $participants]);
    } catch (Throwable $error) {
        error_log('Admin participant lookup failed: ' . $error->getMessage());
        json_response(500, ['error' => 'Não foi possível carregar os participantes.']);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: GET, POST');
    json_response(405, ['error' => 'Método não permitido.']);
}

require_csrf();
// O cliente só envia a intenção; alvo, papel e propriedade da sessão são revistos aqui.
$payload = request_json();
$action = $payload['action'] ?? '';
$userId = filter_var($payload['userId'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1]
]);

if ($userId === false || $userId === null || $userId === (int) $admin['id']) {
    json_response(400, ['error' => 'Membro inválido para esta ação.']);
}

try {
    $targetStatement = $pdo->prepare('SELECT id, role FROM users WHERE id = :id LIMIT 1');
    $targetStatement->execute([':id' => $userId]);
    $target = $targetStatement->fetch();
} catch (Throwable $error) {
    error_log('Admin target lookup failed: ' . $error->getMessage());
    json_response(500, ['error' => 'Não foi possível verificar o membro selecionado.']);
}
if (!$target) {
    json_response(404, ['error' => 'Membro não encontrado.']);
}
if ($target['role'] === 'admin') {
    json_response(403, ['error' => 'Ações de moderação não podem ser aplicadas a administradores.']);
}

try {
    // Silêncio mantém a conta ativa; bloqueio revoga todas as sessões em transação.
    if ($action === 'mute' || $action === 'unmute') {
        $statement = $pdo->prepare('UPDATE users SET is_muted = :muted WHERE id = :id');
        $statement->execute([':muted' => $action === 'mute' ? 1 : 0, ':id' => $userId]);
    } elseif ($action === 'block') {
        $pdo->beginTransaction();
        $statement = $pdo->prepare('UPDATE users SET is_blocked = 1 WHERE id = :id');
        $statement->execute([':id' => $userId]);
        $statement = $pdo->prepare('DELETE FROM user_sessions WHERE user_id = :id');
        $statement->execute([':id' => $userId]);
        $pdo->commit();
    } elseif ($action === 'unblock') {
        $statement = $pdo->prepare('UPDATE users SET is_blocked = 0 WHERE id = :id');
        $statement->execute([':id' => $userId]);
    } elseif ($action === 'terminate_session') {
        $sessionId = filter_var($payload['sessionId'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1]
        ]);
        if ($sessionId === false || $sessionId === null) {
            json_response(400, ['error' => 'Sessão inválida.']);
        }
        $statement = $pdo->prepare('DELETE FROM user_sessions WHERE id = :session_id AND user_id = :user_id');
        $statement->execute([':session_id' => $sessionId, ':user_id' => $userId]);
        if ($statement->rowCount() === 0) {
            json_response(404, ['error' => 'A sessão já foi encerrada.']);
        }
    } else {
        json_response(400, ['error' => 'Ação de moderação inválida.']);
    }

    json_response(200, ['ok' => true]);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Admin moderation action failed: ' . $error->getMessage());
    json_response(500, ['error' => 'Não foi possível concluir a ação de moderação.']);
}
