-- Structured details for a person, so other systems can rely on them being in the same place and shape: sex, the NHS/CHI
-- number (a person has one for life, so it belongs on the person rather than on a support episode), employment status,
-- GP practice and a home address in parts. These are the person's current details; changes are in the audit log.

ALTER TABLE persons
    ADD COLUMN sex               CHAR(1)      NULL AFTER date_of_birth,
    ADD COLUMN nhs_number        VARCHAR(10)  NULL AFTER sex,
    ADD COLUMN employment_status VARCHAR(100) NULL AFTER phone,
    ADD COLUMN gp_practice       VARCHAR(255) NULL AFTER employment_status,
    ADD COLUMN address_line1     VARCHAR(190) NULL AFTER gp_practice,
    ADD COLUMN address_line2     VARCHAR(190) NULL AFTER address_line1,
    ADD COLUMN town              VARCHAR(100) NULL AFTER address_line2,
    ADD COLUMN area              VARCHAR(100) NULL AFTER town,
    ADD COLUMN postcode          VARCHAR(12)  NULL AFTER area,
    ADD UNIQUE KEY uq_person_nhs (organisation_id, nhs_number);
