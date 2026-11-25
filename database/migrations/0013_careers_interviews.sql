-- Careers page and interviews.
-- A vacancy may be advertised on the organisation's public careers page (/careers/{slug}); what is shown is the role's
-- description plus the short text and pay/place lines written here. An interview is a booked time with the candidate;
-- the candidate sees it on a private link (no account) and can confirm or say they cannot make it. Care Core sends no
-- email, so staff copy the invitation text and send it themselves.
ALTER TABLE vacancies
    ADD COLUMN advertise      TINYINT(1)   NOT NULL DEFAULT 0 AFTER closing_date,
    ADD COLUMN public_summary TEXT         NULL AFTER advertise,
    ADD COLUMN salary_text    VARCHAR(100) NULL AFTER public_summary,
    ADD COLUMN location_text  VARCHAR(150) NULL AFTER salary_text;

CREATE TABLE interviews (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid               CHAR(36)     NOT NULL,
    organisation_id    BIGINT UNSIGNED NOT NULL,
    application_id     BIGINT UNSIGNED NOT NULL,
    starts_at          DATETIME     NOT NULL,
    duration_minutes   SMALLINT UNSIGNED NOT NULL DEFAULT 45,
    location           VARCHAR(200) NOT NULL,
    panel              VARCHAR(300) NULL,
    instructions       VARCHAR(1000) NULL,
    status             ENUM('scheduled','completed','cancelled','no_show') NOT NULL DEFAULT 'scheduled',
    candidate_response ENUM('none','confirmed','cannot_attend') NOT NULL DEFAULT 'none',
    response_note      VARCHAR(500) NULL,
    responded_at       DATETIME     NULL,
    public_token       CHAR(32)     NOT NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_interview_uuid (uuid),
    UNIQUE KEY uq_interview_token (public_token),
    KEY idx_interview_app (application_id, starts_at),
    KEY idx_interview_org (organisation_id, status, starts_at),
    CONSTRAINT fk_int_org FOREIGN KEY (organisation_id) REFERENCES organisations (id) ON DELETE CASCADE,
    CONSTRAINT fk_int_app FOREIGN KEY (application_id)  REFERENCES applications (id) ON DELETE CASCADE
) ENGINE=InnoDB;
