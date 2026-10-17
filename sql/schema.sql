-- Ferme Tranquille - MySQL 8 schema
-- Usage: mysql -u root -p ferme_tranquille < sql/schema.sql

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    login         VARCHAR(64)  NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_login (login)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS player_state (
    user_id    INT UNSIGNED NOT NULL,
    state_json JSON         NOT NULL,
    version    INT UNSIGNED NOT NULL DEFAULT 1,
    updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_player_state_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Debug log, purged after 30 days.
CREATE TABLE IF NOT EXISTS action_log (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id      INT UNSIGNED    NOT NULL,
    action_type  VARCHAR(32)     NOT NULL,
    payload_json JSON            NULL,
    created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_action_log_user_created (user_id, created_at),
    KEY idx_action_log_created (created_at),
    CONSTRAINT fk_action_log_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
