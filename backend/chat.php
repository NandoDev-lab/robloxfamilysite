<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';
// Toda leitura e escrita do chat exige uma sessão de membro ativa.
start_app_session();

try {
    $pdo = database();
    $user = current_user($pdo);
} catch (Throwable $error) {
    error_log('Chat request setup failed: ' . $error->getMessage());
    json_response(500, ['error' => 'O bate-papo está indisponível no momento.']);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // O cursor incremental limita a resposta às mensagens novas ainda válidas.
    $after = filter_input(INPUT_GET, 'after', FILTER_VALIDATE_INT, [
        'options' => ['default' => 0, 'min_range' => 0]
    ]);
    if ($after === false || $after === null) {
        json_response(400, ['error' => 'Identificador de mensagem inválido.']);
    }

    try {
        cleanup_expired_data($pdo);

        $statement = $pdo->prepare(
            'SELECT m.id, m.user_id, u.username, u.chat_color, m.content
             FROM chat_messages m
             INNER JOIN users u ON u.id = m.user_id
             WHERE m.id > :after
               AND m.created_at >= UTC_TIMESTAMP() - INTERVAL 10 MINUTE
             ORDER BY m.id ASC
             LIMIT 100'
        );
        $statement->bindValue(':after', $after, PDO::PARAM_INT);
        $statement->execute();

        $messages = array_map(static function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'userId' => (int) $row['user_id'],
                'userName' => $row['username'],
                'userColor' => $row['chat_color'],
                'content' => $row['content']
            ];
        }, $statement->fetchAll());

        json_response(200, [
            'messages' => $messages,
            'muted' => (bool) $user['is_muted']
        ]);
    } catch (Throwable $error) {
        error_log('Chat message polling failed: ' . $error->getMessage());
        json_response(500, ['error' => 'Não foi possível carregar as mensagens.']);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: GET, POST');
    json_response(405, ['error' => 'Método não permitido.']);
}

require_csrf();
// A identidade e as permissões vêm da sessão validada, nunca do JSON do navegador.
if ((int) $user['is_muted'] === 1) {
    json_response(403, ['error' => 'Sua conta está silenciada e não pode enviar mensagens.']);
}

$payload = request_json();
$content = isset($payload['content']) && is_string($payload['content'])
    ? trim($payload['content'])
    : '';
if ($content === '' || strlen($content) > 500) {
    json_response(400, ['error' => 'A mensagem deve ter entre 1 e 500 caracteres.']);
}

try {
    $statement = $pdo->prepare(
        'INSERT INTO chat_messages (user_id, user_name, user_color, content)
         VALUES (:user_id, :user_name, :user_color, :content)'
    );
    $statement->execute([
        ':user_id' => (string) $user['id'],
        ':user_name' => $user['username'],
        ':user_color' => $user['chat_color'],
        ':content' => $content
    ]);

    json_response(201, ['id' => (int) $pdo->lastInsertId()]);
} catch (Throwable $error) {
    error_log('Chat message save failed: ' . $error->getMessage());
    json_response(500, ['error' => 'Não foi possível enviar a mensagem.']);
}
