-- v39: Case Intelligence — structured case analysis fields.
-- Closes gap-list items under "نواقص Case Intelligence": استخراج Legal Issues تلقائياً،
-- تحليل Burden of Proof، قياس Argument Strength، توليد Counterarguments، Risk Matrix متقدمة.
ALTER TABLE case_analyses ADD COLUMN structured_json MEDIUMTEXT DEFAULT NULL AFTER analysis;
