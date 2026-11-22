-- A referral usually says who the person is: date of birth and CHI/NHS number. They are carried to the person record when one is started from the referral.
ALTER TABLE referrals ADD COLUMN date_of_birth DATE NULL AFTER person_name, ADD COLUMN nhs_number VARCHAR(10) NULL AFTER date_of_birth;
