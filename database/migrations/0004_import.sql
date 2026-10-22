-- Importing data from other systems: which source record became which Care Core record (so a re-run never duplicates),
-- and a log of every import and dry run.

CREATE TABLE import_refs (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    organisation_id BIGINT UNSIGNED NOT NULL,
    entity          VARCHAR(30)  NOT NULL,
    source_ref      VARCHAR(120) NOT NULL,
    target_uuid     CHAR(36)     NOT NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_import_ref (organisation_id, entity, source_ref),
    KEY idx_import_target (target_uuid),
    CONSTRAINT fk_impref_org FOREIGN KEY (organisation_id) REFERENCES organisations (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE import_runs (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid            CHAR(36)     NOT NULL,
    organisation_id BIGINT UNSIGNED NOT NULL,
    label           VARCHAR(200) NULL,
    mode            ENUM('dry_run','import') NOT NULL,
    status          ENUM('completed','rolled_back','failed') NOT NULL,
    skip_errors     TINYINT(1)   NOT NULL DEFAULT 0,
    summary         LONGTEXT     NULL,
    actor_label     VARCHAR(190) NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_import_uuid (uuid),
    KEY idx_import_org (organisation_id, id),
    CONSTRAINT fk_imprun_org FOREIGN KEY (organisation_id) REFERENCES organisations (id) ON DELETE CASCADE
) ENGINE=InnoDB;
