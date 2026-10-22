-- Care Core: canonical schema (MySQL 8 / MariaDB 10.5+, utf8mb4).
--
-- Principles
--  * One database, many modules. Every tenant table carries organisation_id.
--  * A person is one permanent identity. Staff, people supported and contacts are RELATIONSHIPS of a person
--    (employments, support_episodes, ...), never copies of them.
--  * Relationships are periods (valid_from / valid_to, start_date / end_date). Nothing is overwritten: a rehire, a move
--    or a return to a team is a new row. "Active on date D" means start <= D and (end IS NULL or end >= D).
--  * Public identifiers are UUIDs (CHAR(36)); internal keys are BIGINT.
--  * Everything that changes state writes an audit row and an outbox event in the SAME transaction.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS schema_migrations, webhook_deliveries, webhook_endpoints, outbox_events, audit_log, scim_tokens,
    login_attempts, tasks, checklist_template_items, checklist_templates, key_worker_assignments, person_contacts,
    care_needs, support_episodes, team_assignments, teams, employment_changes, employments, users, persons,
    organisation_modules, organisations;

CREATE TABLE organisations (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid        CHAR(36)     NOT NULL,
    name        VARCHAR(200) NOT NULL,
    slug        VARCHAR(80)  NOT NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_org_uuid (uuid),
    UNIQUE KEY uq_org_slug (slug)
) ENGINE=InnoDB;

-- Modules a customer has switched on: people, staff, teams, care, identity.
CREATE TABLE organisation_modules (
    organisation_id BIGINT UNSIGNED NOT NULL,
    module          VARCHAR(40) NOT NULL,
    enabled         TINYINT(1)  NOT NULL DEFAULT 1,
    PRIMARY KEY (organisation_id, module),
    CONSTRAINT fk_orgmod_org FOREIGN KEY (organisation_id) REFERENCES organisations (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- The permanent identity of a human. Never deleted by the lifecycle: leaving only ends relationships.
CREATE TABLE persons (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid            CHAR(36)     NOT NULL,
    organisation_id BIGINT UNSIGNED NOT NULL,
    first_name      VARCHAR(100) NOT NULL,
    last_name       VARCHAR(100) NOT NULL,
    preferred_name  VARCHAR(100) NULL,
    date_of_birth   DATE         NULL,
    email           VARCHAR(190) NULL,
    phone           VARCHAR(40)  NULL,
    notes           TEXT         NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_person_uuid (uuid),
    KEY idx_person_org_name (organisation_id, last_name, first_name),
    KEY idx_person_org_email (organisation_id, email),
    CONSTRAINT fk_person_org FOREIGN KEY (organisation_id) REFERENCES organisations (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- A login. One account per email address. access_ends_on is how a leaver loses access on the right day without a job.
CREATE TABLE users (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid              CHAR(36)     NOT NULL,
    organisation_id   BIGINT UNSIGNED NOT NULL,
    person_id         BIGINT UNSIGNED NULL,
    email             VARCHAR(190) NOT NULL,
    password_hash     VARCHAR(255) NULL,
    role              ENUM('admin','manager','staff') NOT NULL DEFAULT 'staff',
    status            ENUM('active','invited','disabled') NOT NULL DEFAULT 'active',
    access_ends_on    DATE         NULL,
    invite_token_hash CHAR(64)     NULL,
    invite_expires_at DATETIME     NULL,
    last_login_at     DATETIME     NULL,
    created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_uuid (uuid),
    UNIQUE KEY uq_user_email (email),
    KEY idx_user_person (person_id),
    KEY idx_user_invite (invite_token_hash),
    CONSTRAINT fk_user_org    FOREIGN KEY (organisation_id) REFERENCES organisations (id) ON DELETE CASCADE,
    CONSTRAINT fk_user_person FOREIGN KEY (person_id)       REFERENCES persons (id)       ON DELETE SET NULL
) ENGINE=InnoDB;

-- One stint of employment. A rehire is a NEW row for the same person.
CREATE TABLE employments (
    id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid                   CHAR(36)     NOT NULL,
    organisation_id        BIGINT UNSIGNED NOT NULL,
    person_id              BIGINT UNSIGNED NOT NULL,
    employee_ref           VARCHAR(50)  NULL,
    job_title              VARCHAR(150) NOT NULL,
    contract_type          VARCHAR(40)  NOT NULL DEFAULT 'permanent',
    hours_per_week         DECIMAL(5,2) NULL,
    start_date             DATE         NOT NULL,
    end_date               DATE         NULL,
    leaving_reason         VARCHAR(80)  NULL,
    line_manager_person_id BIGINT UNSIGNED NULL,
    created_at             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_employment_uuid (uuid),
    UNIQUE KEY uq_employment_ref (organisation_id, employee_ref),
    KEY idx_employment_person (person_id, end_date),
    KEY idx_employment_org_end (organisation_id, end_date),
    CONSTRAINT fk_emp_org    FOREIGN KEY (organisation_id)        REFERENCES organisations (id) ON DELETE CASCADE,
    CONSTRAINT fk_emp_person FOREIGN KEY (person_id)              REFERENCES persons (id)       ON DELETE CASCADE,
    CONSTRAINT fk_emp_mgr    FOREIGN KEY (line_manager_person_id) REFERENCES persons (id)       ON DELETE SET NULL
) ENGINE=InnoDB;

-- What changed in a move, and from when.
CREATE TABLE employment_changes (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    employment_id  BIGINT UNSIGNED NOT NULL,
    effective_date DATE         NOT NULL,
    field          VARCHAR(40)  NOT NULL,
    from_value     VARCHAR(255) NULL,
    to_value       VARCHAR(255) NULL,
    changed_by     BIGINT UNSIGNED NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_empchg_emp (employment_id, effective_date),
    CONSTRAINT fk_empchg_emp FOREIGN KEY (employment_id) REFERENCES employments (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE teams (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid            CHAR(36)     NOT NULL,
    organisation_id BIGINT UNSIGNED NOT NULL,
    parent_team_id  BIGINT UNSIGNED NULL,
    name            VARCHAR(200) NOT NULL,
    kind            VARCHAR(40)  NOT NULL DEFAULT 'care',
    staff_only      TINYINT(1)   NOT NULL DEFAULT 0,
    active          TINYINT(1)   NOT NULL DEFAULT 1,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_team_uuid (uuid),
    KEY idx_team_org (organisation_id, active),
    KEY idx_team_parent (parent_team_id),
    CONSTRAINT fk_team_org    FOREIGN KEY (organisation_id) REFERENCES organisations (id) ON DELETE CASCADE,
    CONSTRAINT fk_team_parent FOREIGN KEY (parent_team_id)  REFERENCES teams (id)         ON DELETE SET NULL
) ENGINE=InnoDB;

-- Who is in which team, and when. Staff and people supported both live here (staff_only teams refuse the latter).
CREATE TABLE team_assignments (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    organisation_id BIGINT UNSIGNED NOT NULL,
    team_id         BIGINT UNSIGNED NOT NULL,
    person_id       BIGINT UNSIGNED NOT NULL,
    role            VARCHAR(80)  NULL,
    is_primary      TINYINT(1)   NOT NULL DEFAULT 0,
    valid_from      DATE         NOT NULL,
    valid_to        DATE         NULL,
    source          VARCHAR(30)  NOT NULL DEFAULT 'manual',
    notes           VARCHAR(500) NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ta_team (team_id, valid_to),
    KEY idx_ta_person (person_id, valid_to),
    CONSTRAINT fk_ta_org    FOREIGN KEY (organisation_id) REFERENCES organisations (id) ON DELETE CASCADE,
    CONSTRAINT fk_ta_team   FOREIGN KEY (team_id)         REFERENCES teams (id)         ON DELETE CASCADE,
    CONSTRAINT fk_ta_person FOREIGN KEY (person_id)       REFERENCES persons (id)       ON DELETE CASCADE
) ENGINE=InnoDB;

-- People we support: a period of support. The health and care detail below is the sensitive part of the database.
CREATE TABLE support_episodes (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid                CHAR(36)     NOT NULL,
    organisation_id     BIGINT UNSIGNED NOT NULL,
    person_id           BIGINT UNSIGNED NOT NULL,
    reference           VARCHAR(50)  NULL,
    nhs_number          VARCHAR(20)  NULL,
    local_authority_ref VARCHAR(50)  NULL,
    start_date          DATE         NOT NULL,
    end_date            DATE         NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_episode_uuid (uuid),
    KEY idx_episode_person (person_id, end_date),
    KEY idx_episode_org (organisation_id, end_date),
    CONSTRAINT fk_ep_org    FOREIGN KEY (organisation_id) REFERENCES organisations (id) ON DELETE CASCADE,
    CONSTRAINT fk_ep_person FOREIGN KEY (person_id)       REFERENCES persons (id)       ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE care_needs (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    organisation_id BIGINT UNSIGNED NOT NULL,
    person_id       BIGINT UNSIGNED NOT NULL,
    category        VARCHAR(100) NOT NULL,
    description     TEXT         NOT NULL,
    severity        ENUM('low','medium','high') NULL,
    review_date     DATE         NULL,
    ended_on        DATE         NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_need_person (person_id, ended_on),
    CONSTRAINT fk_need_org    FOREIGN KEY (organisation_id) REFERENCES organisations (id) ON DELETE CASCADE,
    CONSTRAINT fk_need_person FOREIGN KEY (person_id)       REFERENCES persons (id)       ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE person_contacts (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    organisation_id BIGINT UNSIGNED NOT NULL,
    person_id       BIGINT UNSIGNED NOT NULL,
    contact_type    ENUM('phone','email','address','next_of_kin','legal_guardian','social_worker','gp','other') NOT NULL,
    label           VARCHAR(100) NULL,
    value           VARCHAR(500) NOT NULL,
    is_primary      TINYINT(1)   NOT NULL DEFAULT 0,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_contact_person (person_id),
    CONSTRAINT fk_contact_org    FOREIGN KEY (organisation_id) REFERENCES organisations (id) ON DELETE CASCADE,
    CONSTRAINT fk_contact_person FOREIGN KEY (person_id)       REFERENCES persons (id)       ON DELETE CASCADE
) ENGINE=InnoDB;

-- A real foreign key to a real person: no copied names, no numbers that point at another database.
CREATE TABLE key_worker_assignments (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    organisation_id     BIGINT UNSIGNED NOT NULL,
    supported_person_id BIGINT UNSIGNED NOT NULL,
    staff_person_id     BIGINT UNSIGNED NOT NULL,
    role_label          VARCHAR(100) NULL,
    valid_from          DATE         NOT NULL,
    valid_to            DATE         NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_kw_supported (supported_person_id, valid_to),
    KEY idx_kw_staff (staff_person_id, valid_to),
    CONSTRAINT fk_kw_org       FOREIGN KEY (organisation_id)     REFERENCES organisations (id) ON DELETE CASCADE,
    CONSTRAINT fk_kw_supported FOREIGN KEY (supported_person_id) REFERENCES persons (id)       ON DELETE CASCADE,
    CONSTRAINT fk_kw_staff     FOREIGN KEY (staff_person_id)     REFERENCES persons (id)       ON DELETE CASCADE
) ENGINE=InnoDB;

-- Onboarding and offboarding checklists. Templates are per organisation; tasks are what a joiner or leaver actually creates.
CREATE TABLE checklist_templates (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    organisation_id BIGINT UNSIGNED NOT NULL,
    kind            ENUM('joiner','mover','leaver') NOT NULL,
    name            VARCHAR(150) NOT NULL,
    active          TINYINT(1)   NOT NULL DEFAULT 1,
    KEY idx_tpl_org (organisation_id, kind, active),
    CONSTRAINT fk_tpl_org FOREIGN KEY (organisation_id) REFERENCES organisations (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE checklist_template_items (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    template_id     BIGINT UNSIGNED NOT NULL,
    title           VARCHAR(200) NOT NULL,
    description     VARCHAR(500) NULL,
    owner_role      ENUM('admin','manager','staff') NOT NULL DEFAULT 'manager',
    due_offset_days INT          NOT NULL DEFAULT 0,
    sort_order      INT          NOT NULL DEFAULT 0,
    KEY idx_tplitem_tpl (template_id, sort_order),
    CONSTRAINT fk_tplitem_tpl FOREIGN KEY (template_id) REFERENCES checklist_templates (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE tasks (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid                 CHAR(36)     NOT NULL,
    organisation_id      BIGINT UNSIGNED NOT NULL,
    person_id            BIGINT UNSIGNED NOT NULL,
    employment_id        BIGINT UNSIGNED NULL,
    kind                 ENUM('joiner','mover','leaver','reassign','manual') NOT NULL,
    title                VARCHAR(200) NOT NULL,
    description          VARCHAR(500) NULL,
    status               ENUM('open','done','skipped') NOT NULL DEFAULT 'open',
    due_date             DATE         NULL,
    owner_role           ENUM('admin','manager','staff') NOT NULL DEFAULT 'manager',
    completed_by_user_id BIGINT UNSIGNED NULL,
    completed_at         DATETIME     NULL,
    created_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_task_uuid (uuid),
    KEY idx_task_org_status (organisation_id, status, due_date),
    KEY idx_task_person (person_id),
    CONSTRAINT fk_task_org    FOREIGN KEY (organisation_id) REFERENCES organisations (id) ON DELETE CASCADE,
    CONSTRAINT fk_task_person FOREIGN KEY (person_id)       REFERENCES persons (id)       ON DELETE CASCADE,
    CONSTRAINT fk_task_emp    FOREIGN KEY (employment_id)   REFERENCES employments (id)   ON DELETE SET NULL
) ENGINE=InnoDB;

-- Events. Written in the same transaction as the change; delivered later as signed webhooks (scripts/deliver-events.php).
-- Payloads carry identifiers and dates only: no names and no health information.
CREATE TABLE outbox_events (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid            CHAR(36)     NOT NULL,
    organisation_id BIGINT UNSIGNED NOT NULL,
    type            VARCHAR(80)  NOT NULL,
    subject_uuid    CHAR(36)     NULL,
    payload         LONGTEXT     NOT NULL,
    occurred_at     DATETIME(3)  NOT NULL,
    fanned_out_at   DATETIME     NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_event_uuid (uuid),
    KEY idx_event_org (organisation_id, id),
    CONSTRAINT fk_event_org FOREIGN KEY (organisation_id) REFERENCES organisations (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE webhook_endpoints (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    organisation_id BIGINT UNSIGNED NOT NULL,
    url             VARCHAR(500) NOT NULL,
    secret          VARCHAR(100) NOT NULL,
    event_types     VARCHAR(500) NOT NULL DEFAULT '*',
    active          TINYINT(1)   NOT NULL DEFAULT 1,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_wh_org (organisation_id, active),
    CONSTRAINT fk_wh_org FOREIGN KEY (organisation_id) REFERENCES organisations (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE webhook_deliveries (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    outbox_event_id  BIGINT UNSIGNED NOT NULL,
    endpoint_id      BIGINT UNSIGNED NOT NULL,
    attempts         INT          NOT NULL DEFAULT 0,
    status           ENUM('pending','delivered','failed') NOT NULL DEFAULT 'pending',
    http_status      INT          NULL,
    error            VARCHAR(300) NULL,
    next_attempt_at  DATETIME     NULL,
    delivered_at     DATETIME     NULL,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_delivery (outbox_event_id, endpoint_id),
    KEY idx_delivery_due (status, next_attempt_at),
    CONSTRAINT fk_del_event FOREIGN KEY (outbox_event_id) REFERENCES outbox_events (id)   ON DELETE CASCADE,
    CONSTRAINT fk_del_ep    FOREIGN KEY (endpoint_id)     REFERENCES webhook_endpoints (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Append-only record of who did what.
CREATE TABLE audit_log (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    organisation_id BIGINT UNSIGNED NOT NULL,
    actor_user_id   BIGINT UNSIGNED NULL,
    actor_label     VARCHAR(190) NULL,
    action          VARCHAR(80)  NOT NULL,
    entity_type     VARCHAR(40)  NOT NULL,
    entity_uuid     CHAR(36)     NULL,
    summary         VARCHAR(300) NOT NULL,
    detail          LONGTEXT     NULL,
    ip              VARCHAR(45)  NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_audit_org (organisation_id, id),
    KEY idx_audit_entity (entity_type, entity_uuid),
    CONSTRAINT fk_audit_org FOREIGN KEY (organisation_id) REFERENCES organisations (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE login_attempts (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    email        VARCHAR(190) NOT NULL,
    ip           VARCHAR(45)  NOT NULL,
    attempted_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_login_attempt (email, attempted_at),
    KEY idx_login_ip (ip, attempted_at)
) ENGINE=InnoDB;

-- Bearer tokens for the SCIM endpoint that an identity provider (for example Microsoft Entra) uses to push joiners and leavers.
CREATE TABLE scim_tokens (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    organisation_id BIGINT UNSIGNED NOT NULL,
    name            VARCHAR(100) NOT NULL,
    token_hash      CHAR(64)     NOT NULL,
    active          TINYINT(1)   NOT NULL DEFAULT 1,
    last_used_at    DATETIME     NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_scim_hash (token_hash),
    CONSTRAINT fk_scim_org FOREIGN KEY (organisation_id) REFERENCES organisations (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE schema_migrations (
    version    VARCHAR(100) NOT NULL PRIMARY KEY,
    applied_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;
