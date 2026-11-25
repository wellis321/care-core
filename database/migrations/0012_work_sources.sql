-- "My work": the products Care Core asks, one person at a time, for what is waiting for them (docs: service-directory/docs/app-suite/my-work-design.md).
-- The key is the product's own read-only "my_work" key, sealed with APP_KEY. Nothing is copied here: the hub asks each product when a person opens the page.
CREATE TABLE IF NOT EXISTS work_sources (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    organisation_id BIGINT UNSIGNED NOT NULL,
    name            VARCHAR(100) NOT NULL,
    base_url        VARCHAR(300) NOT NULL,
    key_sealed      TEXT         NOT NULL,
    active          TINYINT(1)   NOT NULL DEFAULT 1,
    last_ok_at      DATETIME     NULL,
    last_error      VARCHAR(200) NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_work_org (organisation_id, active),
    CONSTRAINT fk_work_org FOREIGN KEY (organisation_id) REFERENCES organisations (id) ON DELETE CASCADE
) ENGINE=InnoDB;
