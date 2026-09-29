-- Private law-firm profiles, membership, and firm-scoped knowledge base.
SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS law_firms (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(180) NOT NULL,
    owner_lawyer_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (owner_lawyer_id) REFERENCES lawyers(id) ON DELETE CASCADE,
    UNIQUE KEY uq_law_firm_owner (owner_lawyer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS law_firm_members (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    firm_id INT UNSIGNED NOT NULL,
    lawyer_id INT UNSIGNED NOT NULL,
    member_role ENUM('owner','partner','lawyer','staff') NOT NULL DEFAULT 'lawyer',
    joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_law_firm_member_lawyer (lawyer_id),
    INDEX idx_law_firm_members_firm (firm_id,member_role),
    FOREIGN KEY (firm_id) REFERENCES law_firms(id) ON DELETE CASCADE,
    FOREIGN KEY (lawyer_id) REFERENCES lawyers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS firm_knowledge (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    firm_id INT UNSIGNED NOT NULL,
    author_lawyer_id INT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    category VARCHAR(100) DEFAULT NULL,
    content LONGTEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_firm_knowledge_firm (firm_id,updated_at),
    FOREIGN KEY (firm_id) REFERENCES law_firms(id) ON DELETE CASCADE,
    FOREIGN KEY (author_lawyer_id) REFERENCES lawyers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
