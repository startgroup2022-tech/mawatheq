-- v39: Case Intelligence — structured case analysis fields.
-- Closes gap-list items under "نواقص Case Intelligence": استخراج Legal Issues تلقائياً،
-- تحليل Burden of Proof، قياس Argument Strength، توليد Counterarguments، Risk Matrix متقدمة.
-- Added only when missing so a fresh install (whose schema.sql already has the column) and a
-- re-run of this file both stay harmless, matching the pattern used by the other migrations.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='case_analyses' AND COLUMN_NAME='structured_json');
SET @s := IF(@c=0, 'ALTER TABLE case_analyses ADD COLUMN structured_json MEDIUMTEXT DEFAULT NULL AFTER analysis', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
