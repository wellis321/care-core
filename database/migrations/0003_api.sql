-- Public identifiers for everything the API exposes, and the tables the API itself needs.

ALTER TABLE team_assignments       ADD COLUMN uuid CHAR(36) NULL AFTER id;
ALTER TABLE care_needs             ADD COLUMN uuid CHAR(36) NULL AFTER id;
ALTER TABLE person_contacts        ADD COLUMN uuid CHAR(36) NULL AFTER id;
ALTER TABLE key_worker_assignments ADD COLUMN uuid CHAR(36) NULL AFTER id;
ALTER TABLE webhook_endpoints      ADD COLUMN uuid CHAR(36) NULL AFTER id;
UPDATE team_assignments       SET uuid = UUID() WHERE uuid IS NULL;
UPDATE care_needs             SET uuid = UUID() WHERE uuid IS NULL;
UPDATE person_contacts        SET uuid = UUID() WHERE uuid IS NULL;
UPDATE key_worker_assignments SET uuid = UUID() WHERE uuid IS NULL;
UPDATE webhook_endpoints      SET uuid = UUID() WHERE uuid IS NULL;
ALTER TABLE team_assignments       MODIFY uuid CHAR(36) NOT NULL, ADD UNIQUE KEY uq_ta_uuid (uuid);
ALTER TABLE care_needs             MODIFY uuid CHAR(36) NOT NULL, ADD UNIQUE KEY uq_need_uuid (uuid);
ALTER TABLE person_contacts        MODIFY uuid CHAR(36) NOT NULL, ADD UNIQUE KEY uq_contact_uuid (uuid);
ALTER TABLE key_worker_assignments MODIFY uuid CHAR(36) NOT NULL, ADD UNIQUE KEY uq_kw_uuid (uuid);
ALTER TABLE webhook_endpoints      MODIFY uuid CHAR(36) NOT NULL, ADD UNIQUE KEY uq_wh_uuid (uuid);

-- An API key. Only a hash of the secret is kept; key_prefix is shown so people can tell keys apart.
CREATE TABLE api_keys (
    id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid                  CHAR(36)     NOT NULL,
    organisation_id       BIGINT UNSIGNED NOT NULL,
    name                  VARCHAR(100) NOT NULL,
    key_prefix            CHAR(12)     NOT NULL,
    key_hash              CHAR(64)     NOT NULL,
    scopes                VARCHAR(600) NOT NULL,
    allowed_ips           VARCHAR(500) NULL,
    rate_limit_per_minute SMALLINT UNSIGNED NOT NULL DEFAULT 120,
    expires_at            DATETIME     NULL,
    active                TINYINT(1)   NOT NULL DEFAULT 1,
    last_used_at          DATETIME     NULL,
    last_used_ip          VARCHAR(45)  NULL,
    created_by_user_id    BIGINT UNSIGNED NULL,
    created_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at            DATETIME     NULL,
    UNIQUE KEY uq_apikey_uuid (uuid),
    UNIQUE KEY uq_apikey_prefix (key_prefix),
    KEY idx_apikey_org (organisation_id),
    CONSTRAINT fk_apikey_org FOREIGN KEY (organisation_id) REFERENCES organisations (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Requests per key per minute, for rate limiting.
CREATE TABLE api_rate (
    key_id       BIGINT UNSIGNED NOT NULL,
    window_start INT UNSIGNED    NOT NULL,
    hits         INT UNSIGNED    NOT NULL DEFAULT 0,
    PRIMARY KEY (key_id, window_start),
    CONSTRAINT fk_apirate_key FOREIGN KEY (key_id) REFERENCES api_keys (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- The stored result of a POST that carried an Idempotency-Key, so a retry returns the same answer instead of doing it twice.
CREATE TABLE api_idempotency (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    key_id        BIGINT UNSIGNED NOT NULL,
    idem_key      VARCHAR(100) NOT NULL,
    request_hash  CHAR(64)     NOT NULL,
    status        SMALLINT UNSIGNED NULL,
    response_body MEDIUMTEXT   NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_idem (key_id, idem_key),
    KEY idx_idem_created (created_at),
    CONSTRAINT fk_idem_key FOREIGN KEY (key_id) REFERENCES api_keys (id) ON DELETE CASCADE
) ENGINE=InnoDB;
