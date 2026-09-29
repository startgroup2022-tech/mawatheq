-- Allow lawyers to publish selected case tasks to clients.
SET NAMES utf8mb4;
-- Some older production databases were created before case_tasks was added.
-- Create it first so the conditional column migration below is safe to rerun.
CREATE TABLE IF NOT EXISTS case_tasks (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    case_id INT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    due_date DATE DEFAULT NULL,
    status ENUM('pending','done') NOT NULL DEFAULT 'pending',
    client_visible TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='case_tasks' AND COLUMN_NAME='client_visible');
SET @s := IF(@c=0, 'ALTER TABLE case_tasks ADD COLUMN client_visible TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
