<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';
// Inicia a sessão antes de ler CSRF, usuário autenticado ou dados do formulário.
start_app_session();

try {
    $pdo = database();
} catch (Throwable $error) {
    error_log('Authentication database connection failed: ' . $error->getMessage());
    json_response(500, ['error' => 'O serviço de contas está indisponível.']);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Esta rota informa a sessão atual e fornece o token necessário ao login.
    $user = null;
    try {
        $currentUser = current_user_or_null($pdo);
        if ($currentUser !== null) {
            $user = public_user($currentUser);
        }
    } catch (Throwable $error) {
        error_log('Authentication session lookup failed: ' . $error->getMessage());
        json_response(500, ['error' => 'Não foi possível verificar sua sessão.']);
    }

    json_response(200, ['user' => $user, 'csrfToken' => csrf_token()]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: GET, POST');
    json_response(405, ['error' => 'Método não permitido.']);
}

// Todas as ações abaixo alteram estado e, por isso, exigem proteção CSRF.
require_csrf();
$payload = request_json();
$action = $payload['action'] ?? '';

if ($action === 'logout') {
    // Revoga a sessão no banco e renova o identificador entregue ao navegador.
    try {
        remove_tracked_session($pdo);
    } catch (Throwable $error) {
        error_log('Logout session removal failed: ' . $error->getMessage());
        json_response(500, ['error' => 'Não foi possível encerrar sua sessão.']);
    }
    $_SESSION = [];
    session_regenerate_id(true);
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    json_response(200, ['ok' => true, 'csrfToken' => $_SESSION['csrf_token']]);
}

if (isset($_SESSION['user_id'])) {
    json_response(409, ['error' => 'Encerre sua sessão atual antes de entrar ou criar outra conta.']);
}

if ($action === 'register') {
    // Cadastro público cria sempre um membro; o cliente não escolhe o papel.
    $username = isset($payload['username']) && is_string($payload['username'])
        ? trim($payload['username'])
        : '';
    $email = isset($payload['email']) && is_string($payload['email'])
        ? strtolower(trim($payload['email']))
        : '';
    $password = $payload['password'] ?? null;

    if (
        !preg_match('/^[\p{L}\p{N}_ ]{3,24}$/u', $username) ||
        strlen($email) > 254 ||
        !filter_var($email, FILTER_VALIDATE_EMAIL) ||
        !is_string($password) ||
        strlen($password) < 10 ||
        strlen($password) > 72
    ) {
        json_response(400, ['error' => 'Confira o nome, o e-mail e a senha (10 a 72 bytes).']);
    }

    try {
        $pdo->beginTransaction();
        $chatColors = [
            '#483d8b', '#4b0082', '#00bfff', '#ff00ff', '#ff1493', '#ff8c00',
            '#d2691e', '#556b2f', '#e9967a', '#ffa07a', '#00ff00', '#32cd32',
            '#808000', '#6b8e23', '#ffa500', '#ff4500', '#dda0dd', '#fa8072',
            '#ff6347', '#f5deb3'
        ];
        $statement = $pdo->prepare(
            'INSERT INTO users (username, email, password_hash, role, chat_color)
             VALUES (:username, :email, :password_hash, "member", :chat_color)'
        );
        $statement->execute([
            ':username' => $username,
            ':email' => $email,
            ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ':chat_color' => $chatColors[random_int(0, count($chatColors) - 1)]
        ]);
        $userId = (int) $pdo->lastInsertId();
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        create_tracked_session($pdo, $userId);
        $pdo->commit();
    } catch (PDOException $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        unset($_SESSION['user_id']);
        if ($error->getCode() === '23000') {
            json_response(409, ['error' => 'Este nome ou e-mail já está cadastrado.']);
        }
        error_log('Account registration failed: ' . $error->getMessage());
        json_response(500, ['error' => 'Não foi possível criar a conta.']);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        unset($_SESSION['user_id']);
        error_log('Account session creation failed: ' . $error->getMessage());
        json_response(500, ['error' => 'A conta não pôde iniciar uma sessão.']);
    }

    json_response(201, ['user' => ['id' => $userId, 'name' => $username, 'role' => 'member']]);
}

if ($action === 'login') {
    // O login não revela se o e-mail existe e recusa contas bloqueadas.
    $email = isset($payload['email']) && is_string($payload['email'])
        ? strtolower(trim($payload['email']))
        : '';
    $password = $payload['password'] ?? null;
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !is_string($password)) {
        json_response(400, ['error' => 'Informe um e-mail e uma senha válidos.']);
    }

    $statement = $pdo->prepare(
        'SELECT id, username, password_hash, role, is_blocked
         FROM users WHERE email = :email LIMIT 1'
    );
    $statement->execute([':email' => $email]);
    $user = $statement->fetch();
    if (!$user || !password_verify($password, $user['password_hash'])) {
        json_response(401, ['error' => 'E-mail ou senha incorretos.']);
    }
    if ((int) $user['is_blocked'] === 1) {
        json_response(403, ['error' => 'Esta conta está bloqueada. Entre em contato com a administração.']);
    }

    try {
        remove_tracked_session($pdo);
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        create_tracked_session($pdo, (int) $user['id']);
    } catch (Throwable $error) {
        unset($_SESSION['user_id']);
        error_log('Account login session creation failed: ' . $error->getMessage());
        json_response(500, ['error' => 'Não foi possível iniciar sua sessão.']);
    }
    json_response(200, ['user' => public_user($user)]);
}

json_response(400, ['error' => 'Ação de autenticação inválida.']);
