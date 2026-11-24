-- Care Core as an OpenID Connect provider: the other products sign people in through it (docs: service-directory/docs/app-suite/single-sign-in-design.md).
-- A product is registered as a client. Its secret is stored as a hash. Codes are stored as hashes, last 60 seconds and work once.
CREATE TABLE IF NOT EXISTS sso_clients (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    organisation_id BIGINT UNSIGNED NOT NULL,
    client_id       VARCHAR(60)  NOT NULL,
    name            VARCHAR(100) NOT NULL,
    secret_hash     CHAR(64)     NOT NULL,
    redirect_uris   TEXT         NOT NULL,
    active          TINYINT(1)   NOT NULL DEFAULT 1,
    last_used_at    DATETIME     NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sso_client (client_id),
    CONSTRAINT fk_sso_client_org FOREIGN KEY (organisation_id) REFERENCES organisations (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sso_codes (
    code_hash       CHAR(64)     NOT NULL PRIMARY KEY,
    client_id       BIGINT UNSIGNED NOT NULL,
    user_id         BIGINT UNSIGNED NOT NULL,
    redirect_uri    VARCHAR(500) NOT NULL,
    nonce           VARCHAR(200) NULL,
    code_challenge  VARCHAR(100) NOT NULL,
    auth_time       INT UNSIGNED NOT NULL,
    amr             VARCHAR(40)  NOT NULL,
    expires_at      DATETIME     NOT NULL,
    used_at         DATETIME     NULL,
    KEY idx_sso_code_exp (expires_at),
    CONSTRAINT fk_sso_code_client FOREIGN KEY (client_id) REFERENCES sso_clients (id) ON DELETE CASCADE,
    CONSTRAINT fk_sso_code_user   FOREIGN KEY (user_id)   REFERENCES users (id)       ON DELETE CASCADE
) ENGINE=InnoDB;

-- Signing keys (RS256). The private half is encrypted with the application key. The newest active key signs; older ones stay published until retired.
CREATE TABLE IF NOT EXISTS sso_keys (
    kid         CHAR(16)   NOT NULL PRIMARY KEY,
    private_enc TEXT       NOT NULL,
    public_pem  TEXT       NOT NULL,
    active      TINYINT(1) NOT NULL DEFAULT 1,
    created_at  DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
