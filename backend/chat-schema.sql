-- Contas: o papel de administrador só é atribuído manualmente no banco.
CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(24) NOT NULL,
    email VARCHAR(254) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('member', 'admin') NOT NULL DEFAULT 'member',
    chat_color CHAR(7) NOT NULL DEFAULT '#00bfff',
    is_muted TINYINT(1) NOT NULL DEFAULT 0,
    is_blocked TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY idx_users_email (email),
    UNIQUE KEY idx_users_username (username),
    KEY idx_users_role_status (role, is_blocked)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cada hash representa uma sessão de navegador que pode ser revogada pelo painel.
CREATE TABLE IF NOT EXISTS user_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_hash CHAR(64) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    last_seen TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY idx_user_sessions_hash (session_hash),
    KEY idx_user_sessions_user_seen (user_id, last_seen),
    CONSTRAINT fk_user_sessions_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Controla a frequência da limpeza sem executar exclusões em cada consulta.
CREATE TABLE IF NOT EXISTS chat_maintenance (
    task VARCHAR(32) NOT NULL,
    last_run TIMESTAMP NOT NULL,
    PRIMARY KEY (task)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mensagens são filtradas por idade e removidas periodicamente após dez minutos.
CREATE TABLE IF NOT EXISTS chat_messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id VARCHAR(64) NOT NULL,
    user_name VARCHAR(24) NOT NULL,
    user_color CHAR(7) NOT NULL,
    content VARCHAR(500) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_chat_messages_created_at (created_at),
    KEY idx_chat_messages_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
