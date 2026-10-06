<?php
declare(strict_types=1);

// Encerra a resposta sempre no mesmo formato e impede cache de dados privados.
function json_response(int $status, array $body): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Configura cookies de sessão seguros e evita reutilizar identificadores aceitos pelo cliente.
function start_app_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('ROBOLOXFAMILYSESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

// Cria uma conexão PDO reutilizável com configurações seguras para MySQL.
function database(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $configPath = __DIR__ . '/config.php';
    if (!is_file($configPath)) {
        throw new RuntimeException('Missing backend/config.php');
    }

    $config = require $configPath;
    if (!is_array($config) || !isset($config['host'], $config['database'], $config['username'], $config['password'])) {
        throw new RuntimeException('Invalid database configuration');
    }

    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=utf8mb4',
        $config['host'],
        $config['database']
    );
    $pdo = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
    $pdo->exec("SET time_zone = '+00:00'");

    return $pdo;
}

// Guarda no banco somente o hash do identificador PHP, nunca o cookie de sessão.
function session_hash(): string
{
    return hash('sha256', session_id());
}

// Mantém um token por sessão para proteger operações que alteram dados.
function csrf_token(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

// Rejeita requisições mutáveis que não apresentem o token CSRF da sessão.
function require_csrf(): void
{
    $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $expected = $_SESSION['csrf_token'] ?? '';
    if (!is_string($provided) || !is_string($expected) || $expected === '' || !hash_equals($expected, $provided)) {
        json_response(403, ['error' => 'A solicitação expirou. Atualize a página e tente novamente.']);
    }
}

// Lê JSON com limite de tamanho e retorna erro explícito para conteúdo inválido.
function request_json(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || strlen($raw) > 8192) {
        json_response(413, ['error' => 'Solicitação muito grande.']);
    }

    try {
        $payload = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        json_response(400, ['error' => 'JSON inválido.']);
    }

    if (!is_array($payload)) {
        json_response(400, ['error' => 'Formato de solicitação inválido.']);
    }

    return $payload;
}

// Expõe apenas os campos públicos necessários à interface.
function public_user(array $user): array
{
    return [
        'id' => (int) $user['id'],
        'name' => $user['username'],
        'role' => $user['role']
    ];
}

// Valida e atualiza a atividade da sessão persistida; sessões revogadas viram anônimas.
function current_user_or_null(PDO $pdo): ?array
{
    start_app_session();
    if (!isset($_SESSION['user_id'])) {
        return null;
    }
    if (!is_int($_SESSION['user_id'])) {
        expire_current_session($pdo);
        return null;
    }

    $statement = $pdo->prepare(
        'SELECT id, username, role, is_blocked, is_muted
         FROM users WHERE id = :id LIMIT 1'
    );
    $statement->execute([':id' => $_SESSION['user_id']]);
    $user = $statement->fetch();
    if (!$user || (int) $user['is_blocked'] === 1) {
        expire_current_session($pdo);
        return null;
    }

    $sessionStatement = $pdo->prepare(
        'SELECT id FROM user_sessions WHERE session_hash = :session_hash AND user_id = :user_id LIMIT 1'
    );
    $sessionStatement->execute([
        ':session_hash' => session_hash(),
        ':user_id' => (int) $user['id']
    ]);
    $trackedSession = $sessionStatement->fetch();
    if (!$trackedSession) {
        expire_current_session($pdo);
        return null;
    }

    $touchStatement = $pdo->prepare(
        'UPDATE user_sessions SET last_seen = UTC_TIMESTAMP() WHERE id = :id'
    );
    $touchStatement->execute([':id' => (int) $trackedSession['id']]);
    session_write_close();

    return $user;
}

// Exige uma conta autenticada para endpoints privados.
function current_user(PDO $pdo): array
{
    $user = current_user_or_null($pdo);
    if ($user === null) {
        json_response(401, ['error' => 'Entre na sua conta para continuar.']);
    }

    return $user;
}

// Revoga a sessão inválida mantendo o token CSRF disponível para novo login.
function expire_current_session(PDO $pdo): void
{
    $statement = $pdo->prepare('DELETE FROM user_sessions WHERE session_hash = :session_hash');
    $statement->execute([':session_hash' => session_hash()]);
    $token = $_SESSION['csrf_token'] ?? null;
    $_SESSION = [];
    if (is_string($token) && $token !== '') {
        $_SESSION['csrf_token'] = $token;
    }
    session_regenerate_id(true);
    csrf_token();
}

// Bloqueia no servidor qualquer tentativa de usar o painel sem papel administrativo.
function require_admin(array $user): void
{
    if ($user['role'] !== 'admin') {
        json_response(403, ['error' => 'Acesso restrito à administração.']);
    }
}

// Registra uma sessão com identificador aleatório no armazenamento compartilhado.
function create_tracked_session(PDO $pdo, int $userId): void
{
    $statement = $pdo->prepare(
        'INSERT INTO user_sessions (session_hash, user_id, last_seen)
         VALUES (:session_hash, :user_id, UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), last_seen = UTC_TIMESTAMP()'
    );
    $statement->execute([
        ':session_hash' => session_hash(),
        ':user_id' => $userId
    ]);
}

// Limita a frequência da limpeza de mensagens expiradas e sessões abandonadas.
function cleanup_expired_data(PDO $pdo): void
{
    $statement = $pdo->prepare(
        'INSERT INTO chat_maintenance (task, last_run)
         VALUES ("message_cleanup", UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE last_run = IF(
             last_run < UTC_TIMESTAMP() - INTERVAL 1 MINUTE,
             UTC_TIMESTAMP(),
             last_run
         )'
    );
    $statement->execute();

    if ($statement->rowCount() > 0) {
        $pdo->exec('DELETE FROM chat_messages WHERE created_at < UTC_TIMESTAMP() - INTERVAL 10 MINUTE');
        $pdo->exec('DELETE FROM user_sessions WHERE last_seen < UTC_TIMESTAMP() - INTERVAL 1 DAY');
    }
}

// Remove do banco a sessão associada ao navegador que está encerrando o login.
function remove_tracked_session(PDO $pdo): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $statement = $pdo->prepare('DELETE FROM user_sessions WHERE session_hash = :session_hash');
    $statement->execute([':session_hash' => session_hash()]);
}
