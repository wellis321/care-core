-- Referrals: where a person's support starts. Care Core holds the referral (it is the master, as it is for people); the
-- products that need it (Care Plans first) read it by API and are told by an event that carries identifiers only.
-- A referral is about a person who may not be a person here yet, so it carries a name until it is linked to one.
-- Nothing is deleted. A decided referral keeps its reason.

CREATE TABLE referrals (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid            CHAR(36)     NOT NULL,
    organisation_id BIGINT UNSIGNED NOT NULL,
    reference       VARCHAR(30)  NOT NULL,
    received_on     DATE         NOT NULL,
    source          ENUM('council','nhs','family','self','provider','other') NOT NULL,
    referrer        VARCHAR(200) NULL,
    person_name     VARCHAR(150) NOT NULL,
    reason          VARCHAR(600) NOT NULL,
    needs_summary   VARCHAR(1000) NULL,
    risks           VARCHAR(600) NULL,
    what_matters    VARCHAR(600) NULL,
    plan_types      VARCHAR(120) NOT NULL DEFAULT '',
    wanted_from     DATE         NULL,
    status          ENUM('received','assessing','accepted','declined','withdrawn') NOT NULL DEFAULT 'received',
    decided_at      DATETIME     NULL,
    decision_reason VARCHAR(400) NULL,
    person_id       BIGINT UNSIGNED NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_referral_uuid (uuid),
    UNIQUE KEY uq_referral_ref (organisation_id, reference),
    KEY idx_referral_org (organisation_id, status, received_on),
    KEY idx_referral_person (person_id),
    CONSTRAINT fk_referral_org FOREIGN KEY (organisation_id) REFERENCES organisations (id) ON DELETE CASCADE,
    CONSTRAINT fk_referral_per FOREIGN KEY (person_id)       REFERENCES persons (id)       ON DELETE SET NULL
) ENGINE=InnoDB;

-- The specialist skills the support will need. Free text, so each product can match them to its own skill list.
CREATE TABLE referral_skills (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    referral_id BIGINT UNSIGNED NOT NULL,
    skill       VARCHAR(150) NOT NULL,
    sort_order  INT          NOT NULL DEFAULT 0,
    UNIQUE KEY uq_refskill (referral_id, skill),
    CONSTRAINT fk_refskill_ref FOREIGN KEY (referral_id) REFERENCES referrals (id) ON DELETE CASCADE
) ENGINE=InnoDB;
