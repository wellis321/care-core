-- Two-step sign-in: an authenticator app (time-based codes). The secret is kept encrypted; recovery codes are kept only as hashes and each works once.
CREATE TABLE IF NOT EXISTS user_security (
    user_id             BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    totp_secret_sealed  TEXT     NULL,
    totp_enabled_at     DATETIME NULL,
    totp_last_step      BIGINT   NULL,
    CONSTRAINT fk_usec_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS recovery_codes (
    id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id   BIGINT UNSIGNED NOT NULL,
    code_hash CHAR(64) NOT NULL,
    used_at   DATETIME NULL,
    KEY idx_rc_user (user_id),
    CONSTRAINT fk_rc_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

