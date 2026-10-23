-- Recruitment: the staff-side counterpart of a referral. A job role is the reference definition (what the job is, who it
-- reports to, what must be checked before someone can start); a vacancy is a post to fill; an application moves through
-- interview to an offer, and hiring it starts the person using the ordinary joiner process (so the person, the employment,
-- the onboarding tasks and the events all come from there). Unsuccessful applicants are not people until they are hired.

CREATE TABLE job_roles (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid               CHAR(36)     NOT NULL,
    organisation_id    BIGINT UNSIGNED NOT NULL,
    title              VARCHAR(150) NOT NULL,
    code               VARCHAR(30)  NULL,
    description        TEXT         NULL,
    person_specification TEXT       NULL,
    reports_to_role_id BIGINT UNSIGNED NULL,
    default_team_id    BIGINT UNSIGNED NULL,
    contract_type      VARCHAR(30)  NOT NULL DEFAULT 'permanent',
    hours_per_week     DECIMAL(5,2) NULL,
    grade              VARCHAR(60)  NULL,
    active             TINYINT(1)   NOT NULL DEFAULT 1,
    created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_jobrole_uuid (uuid),
    UNIQUE KEY uq_jobrole_code (organisation_id, code),
    KEY idx_jobrole_org (organisation_id, active, title),
    CONSTRAINT fk_jobrole_org  FOREIGN KEY (organisation_id)    REFERENCES organisations (id) ON DELETE CASCADE,
    CONSTRAINT fk_jobrole_rep  FOREIGN KEY (reports_to_role_id) REFERENCES job_roles (id)     ON DELETE SET NULL,
    CONSTRAINT fk_jobrole_team FOREIGN KEY (default_team_id)    REFERENCES teams (id)         ON DELETE SET NULL
) ENGINE=InnoDB;

-- What has to be done before someone can start in the role (references, right to work, PVG, registration...).
CREATE TABLE job_role_requirements (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    role_id     BIGINT UNSIGNED NOT NULL,
    title       VARCHAR(150) NOT NULL,
    description VARCHAR(500) NULL,
    is_required TINYINT(1)   NOT NULL DEFAULT 1,
    sort_order  INT          NOT NULL DEFAULT 0,
    KEY idx_jobreq_role (role_id, sort_order),
    CONSTRAINT fk_jobreq_role FOREIGN KEY (role_id) REFERENCES job_roles (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE vacancies (
    id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid                   CHAR(36)     NOT NULL,
    organisation_id        BIGINT UNSIGNED NOT NULL,
    reference              VARCHAR(30)  NOT NULL,
    role_id                BIGINT UNSIGNED NOT NULL,
    title                  VARCHAR(150) NOT NULL,
    team_id                BIGINT UNSIGNED NULL,
    line_manager_person_id BIGINT UNSIGNED NULL,
    posts                  INT          NOT NULL DEFAULT 1,
    contract_type          VARCHAR(30)  NOT NULL DEFAULT 'permanent',
    hours_per_week         DECIMAL(5,2) NULL,
    status                 ENUM('draft','open','filled','closed') NOT NULL DEFAULT 'draft',
    opened_on              DATE         NULL,
    closing_date           DATE         NULL,
    closed_on              DATE         NULL,
    notes                  TEXT         NULL,
    created_at             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_vacancy_uuid (uuid),
    UNIQUE KEY uq_vacancy_ref (organisation_id, reference),
    KEY idx_vacancy_status (organisation_id, status),
    CONSTRAINT fk_vac_org  FOREIGN KEY (organisation_id)        REFERENCES organisations (id) ON DELETE CASCADE,
    CONSTRAINT fk_vac_role FOREIGN KEY (role_id)                REFERENCES job_roles (id)     ON DELETE RESTRICT,
    CONSTRAINT fk_vac_team FOREIGN KEY (team_id)                REFERENCES teams (id)         ON DELETE SET NULL,
    CONSTRAINT fk_vac_mgr  FOREIGN KEY (line_manager_person_id) REFERENCES persons (id)       ON DELETE SET NULL
) ENGINE=InnoDB;

-- A candidate's application. The details are the candidate's own until they are hired, when they become a person.
CREATE TABLE applications (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid                CHAR(36)     NOT NULL,
    organisation_id     BIGINT UNSIGNED NOT NULL,
    vacancy_id          BIGINT UNSIGNED NOT NULL,
    first_name          VARCHAR(100) NOT NULL,
    last_name           VARCHAR(100) NOT NULL,
    email               VARCHAR(190) NULL,
    phone               VARCHAR(40)  NULL,
    date_of_birth       DATE         NULL,
    source              VARCHAR(100) NULL,
    stage               ENUM('applied','shortlisted','interview','offer','hired','rejected','withdrawn') NOT NULL DEFAULT 'applied',
    stage_changed_on    DATE         NOT NULL,
    interview_date      DATE         NULL,
    interview_notes     TEXT         NULL,
    offer_date          DATE         NULL,
    offer_accepted_on   DATE         NULL,
    proposed_start_date DATE         NULL,
    reason              VARCHAR(500) NULL,
    notes               TEXT         NULL,
    person_id           BIGINT UNSIGNED NULL,
    employment_id       BIGINT UNSIGNED NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_application_uuid (uuid),
    KEY idx_application_vac (vacancy_id, stage),
    CONSTRAINT fk_app_org  FOREIGN KEY (organisation_id) REFERENCES organisations (id) ON DELETE CASCADE,
    CONSTRAINT fk_app_vac  FOREIGN KEY (vacancy_id)      REFERENCES vacancies (id)     ON DELETE CASCADE,
    CONSTRAINT fk_app_per  FOREIGN KEY (person_id)       REFERENCES persons (id)       ON DELETE SET NULL
) ENGINE=InnoDB;

-- The role's requirements, copied to each application so they can be ticked off for that candidate.
CREATE TABLE application_checks (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid           CHAR(36)     NOT NULL,
    application_id BIGINT UNSIGNED NOT NULL,
    title          VARCHAR(150) NOT NULL,
    description    VARCHAR(500) NULL,
    is_required    TINYINT(1)   NOT NULL DEFAULT 1,
    sort_order     INT          NOT NULL DEFAULT 0,
    done_on        DATE         NULL,
    notes          VARCHAR(500) NULL,
    UNIQUE KEY uq_appcheck_uuid (uuid),
    KEY idx_appcheck_app (application_id, sort_order),
    CONSTRAINT fk_appcheck_app FOREIGN KEY (application_id) REFERENCES applications (id) ON DELETE CASCADE
) ENGINE=InnoDB;
