-- Civil Registration System — Schema (MySQL 8 / MariaDB 10.4+, InnoDB, utf8mb4)
-- Mogadishu Municipal Government, Banadir Region, Somalia

CREATE DATABASE IF NOT EXISTS civil_registration CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE civil_registration;

-- Drop triggers if they exist prior to table setup
DROP TRIGGER IF EXISTS audit_logs_no_update;
DROP TRIGGER IF EXISTS audit_logs_no_delete;

-- ===== 1. Access Control =====
CREATE TABLE IF NOT EXISTS roles (
  id TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(30) NOT NULL UNIQUE,
  label VARCHAR(60) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS permissions (
  id SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(50) NOT NULL UNIQUE,
  description VARCHAR(150) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS role_permissions (
  role_id TINYINT UNSIGNED NOT NULL,
  permission_id SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(120) NOT NULL,
  username VARCHAR(40) NOT NULL UNIQUE,
  email VARCHAR(120) NULL UNIQUE,
  phone VARCHAR(20) NULL,
  password_hash VARCHAR(255) NOT NULL,
  photo_path VARCHAR(190) NULL,
  role_id TINYINT UNSIGNED NOT NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  must_change_password TINYINT(1) NOT NULL DEFAULT 1,
  failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  last_login DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_users_username (username),
  INDEX idx_users_role (role_id),
  INDEX idx_users_status (status),
  FOREIGN KEY (role_id) REFERENCES roles(id)
) ENGINE=InnoDB;

-- ===== 2. Reference Data =====
CREATE TABLE IF NOT EXISTS districts (
  id TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(60) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS number_sequences (
  prefix VARCHAR(10) NOT NULL,
  year SMALLINT UNSIGNED NOT NULL,
  last_number INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (prefix, year)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS system_settings (
  setting_key VARCHAR(60) PRIMARY KEY,
  setting_value VARCHAR(255) NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ===== 3. Supporting Documents & Photos =====
-- Created before civil records so birth_records can foreign key photo_document_id
CREATE TABLE IF NOT EXISTS supporting_documents (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  record_type ENUM('birth','death','marriage','divorce') NOT NULL,
  record_id INT UNSIGNED NULL,
  original_name VARCHAR(255) NOT NULL,
  stored_name VARCHAR(80) NOT NULL UNIQUE,
  mime_type VARCHAR(80) NOT NULL,
  file_size INT UNSIGNED NOT NULL,
  uploaded_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_doc_record (record_type, record_id),
  FOREIGN KEY (uploaded_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- ===== 4. Civil Records (Age is calculated live, never stored) =====

-- Birth Records (free-text hospital_of_birth, optional child's photo)
CREATE TABLE IF NOT EXISTS birth_records (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  registration_number VARCHAR(30) NOT NULL UNIQUE,
  child_full_name VARCHAR(150) NOT NULL,
  gender ENUM('female','male') NOT NULL,
  date_of_birth DATE NOT NULL,
  time_of_birth TIME NULL,
  place_of_birth VARCHAR(150) NOT NULL,
  hospital_of_birth VARCHAR(120) NOT NULL,
  district_id TINYINT UNSIGNED NOT NULL,
  father_full_name VARCHAR(150) NOT NULL,
  father_nationality VARCHAR(60) NULL DEFAULT 'Somali',
  father_reference VARCHAR(60) NULL,
  father_phone VARCHAR(20) NULL,
  mother_full_name VARCHAR(150) NOT NULL,
  mother_nationality VARCHAR(60) NULL DEFAULT 'Somali',
  mother_reference VARCHAR(60) NULL,
  mother_phone VARCHAR(20) NULL,
  registration_date DATE NOT NULL,
  photo_document_id INT UNSIGNED NULL,
  status ENUM('draft','submitted','under_review','approved','rejected','correction_required') NOT NULL DEFAULT 'draft',
  is_archived TINYINT(1) NOT NULL DEFAULT 0,
  notes TEXT NULL,
  submitted_by INT UNSIGNED NOT NULL,
  approved_by INT UNSIGNED NULL,
  approved_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_birth_reg (registration_number),
  INDEX idx_birth_name (child_full_name),
  INDEX idx_birth_dob (date_of_birth),
  INDEX idx_birth_reg_date (registration_date),
  INDEX idx_birth_status (status),
  INDEX idx_birth_district (district_id),
  INDEX idx_birth_archived (is_archived),
  FOREIGN KEY (district_id) REFERENCES districts(id),
  FOREIGN KEY (submitted_by) REFERENCES users(id),
  FOREIGN KEY (approved_by) REFERENCES users(id),
  FOREIGN KEY (photo_document_id) REFERENCES supporting_documents(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Death Records (incl. guarantor_full_name required, hospital_document_reference optional)
CREATE TABLE IF NOT EXISTS death_records (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  registration_number VARCHAR(30) NOT NULL UNIQUE,
  deceased_full_name VARCHAR(150) NOT NULL,
  gender ENUM('female','male') NOT NULL,
  date_of_birth DATE NOT NULL,
  date_of_death DATE NOT NULL,
  time_of_death TIME NULL,
  place_of_death VARCHAR(150) NOT NULL,
  district_id TINYINT UNSIGNED NOT NULL,
  guarantor_full_name VARCHAR(150) NOT NULL,
  hospital_document_reference VARCHAR(100) NULL,
  nationality VARCHAR(60) NULL DEFAULT 'Somali',
  reference_number VARCHAR(60) NULL,
  marital_status ENUM('single','married','divorced','widowed') NULL,
  spouse_name VARCHAR(150) NULL,
  informant_name VARCHAR(150) NOT NULL,
  informant_relationship VARCHAR(60) NULL,
  informant_phone VARCHAR(20) NULL,
  cause_of_death TEXT NULL,
  registration_date DATE NOT NULL,
  status ENUM('draft','submitted','under_review','approved','rejected','correction_required') NOT NULL DEFAULT 'draft',
  is_archived TINYINT(1) NOT NULL DEFAULT 0,
  notes TEXT NULL,
  submitted_by INT UNSIGNED NOT NULL,
  approved_by INT UNSIGNED NULL,
  approved_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_death_reg (registration_number),
  INDEX idx_death_name (deceased_full_name),
  INDEX idx_death_dob (date_of_birth),
  INDEX idx_death_dod (date_of_death),
  INDEX idx_death_reg_date (registration_date),
  INDEX idx_death_status (status),
  INDEX idx_death_district (district_id),
  INDEX idx_death_archived (is_archived),
  FOREIGN KEY (district_id) REFERENCES districts(id),
  FOREIGN KEY (submitted_by) REFERENCES users(id),
  FOREIGN KEY (approved_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- Marriage Records (plain Husband / Wife terminology, mahr_amount, two witnesses, no marriage_type)
CREATE TABLE IF NOT EXISTS marriage_records (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  registration_number VARCHAR(30) NOT NULL UNIQUE,
  husband_full_name VARCHAR(150) NOT NULL,
  husband_date_of_birth DATE NOT NULL,
  husband_nationality VARCHAR(60) NULL DEFAULT 'Somali',
  husband_reference VARCHAR(60) NULL,
  husband_phone VARCHAR(20) NOT NULL,
  husband_address VARCHAR(200) NULL,
  wife_full_name VARCHAR(150) NOT NULL,
  wife_date_of_birth DATE NOT NULL,
  wife_nationality VARCHAR(60) NULL DEFAULT 'Somali',
  wife_reference VARCHAR(60) NULL,
  wife_phone VARCHAR(20) NOT NULL,
  wife_address VARCHAR(200) NULL,
  marriage_date DATE NOT NULL,
  place_of_marriage VARCHAR(150) NOT NULL,
  district_id TINYINT UNSIGNED NOT NULL,
  mahr_amount VARCHAR(100) NOT NULL,
  witness1_full_name VARCHAR(150) NOT NULL,
  witness1_phone VARCHAR(20) NOT NULL,
  witness2_full_name VARCHAR(150) NOT NULL,
  witness2_phone VARCHAR(20) NOT NULL,
  registration_date DATE NOT NULL,
  status ENUM('draft','submitted','under_review','approved','rejected','correction_required') NOT NULL DEFAULT 'draft',
  is_archived TINYINT(1) NOT NULL DEFAULT 0,
  notes TEXT NULL,
  submitted_by INT UNSIGNED NOT NULL,
  approved_by INT UNSIGNED NULL,
  approved_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_marr_reg (registration_number),
  INDEX idx_marr_husband (husband_full_name),
  INDEX idx_marr_wife (wife_full_name),
  INDEX idx_marr_date (marriage_date),
  INDEX idx_marr_reg_date (registration_date),
  INDEX idx_marr_status (status),
  INDEX idx_marr_district (district_id),
  INDEX idx_marr_archived (is_archived),
  FOREIGN KEY (district_id) REFERENCES districts(id),
  FOREIGN KEY (submitted_by) REFERENCES users(id),
  FOREIGN KEY (approved_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- Divorce Records (husband/wife names and dates of birth, marriage reg number, court reference)
CREATE TABLE IF NOT EXISTS divorce_records (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  registration_number VARCHAR(30) NOT NULL UNIQUE,
  marriage_registration_number VARCHAR(30) NOT NULL,
  husband_full_name VARCHAR(150) NOT NULL,
  husband_date_of_birth DATE NOT NULL,
  wife_full_name VARCHAR(150) NOT NULL,
  wife_date_of_birth DATE NOT NULL,
  divorce_date DATE NOT NULL,
  district_id TINYINT UNSIGNED NOT NULL,
  divorce_reason VARCHAR(200) NULL,
  court_reference VARCHAR(100) NULL,
  registration_date DATE NOT NULL,
  status ENUM('draft','submitted','under_review','approved','rejected','correction_required') NOT NULL DEFAULT 'draft',
  is_archived TINYINT(1) NOT NULL DEFAULT 0,
  notes TEXT NULL,
  submitted_by INT UNSIGNED NOT NULL,
  approved_by INT UNSIGNED NULL,
  approved_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_div_reg (registration_number),
  INDEX idx_div_husband (husband_full_name),
  INDEX idx_div_wife (wife_full_name),
  INDEX idx_div_date (divorce_date),
  INDEX idx_div_reg_date (registration_date),
  INDEX idx_div_status (status),
  INDEX idx_div_district (district_id),
  INDEX idx_div_archived (is_archived),
  FOREIGN KEY (district_id) REFERENCES districts(id),
  FOREIGN KEY (submitted_by) REFERENCES users(id),
  FOREIGN KEY (approved_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- ===== 5. Workflow & Approvals =====
CREATE TABLE IF NOT EXISTS approvals (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  record_type ENUM('birth','death','marriage','divorce') NOT NULL,
  record_id INT UNSIGNED NOT NULL,
  submitted_by INT UNSIGNED NOT NULL,
  reviewed_by INT UNSIGNED NULL,
  decision ENUM('pending','approved','rejected','correction_required') NOT NULL DEFAULT 'pending',
  comments TEXT NULL,
  rejection_reason TEXT NULL,
  submitted_at DATETIME NOT NULL,
  reviewed_at DATETIME NULL,
  INDEX idx_appr_record (record_type, record_id),
  FOREIGN KEY (submitted_by) REFERENCES users(id),
  FOREIGN KEY (reviewed_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS record_status_history (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  record_type ENUM('birth','death','marriage','divorce') NOT NULL,
  record_id INT UNSIGNED NOT NULL,
  status VARCHAR(30) NOT NULL,
  comment VARCHAR(500) NULL,
  user_id INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_hist_record (record_type, record_id),
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- ===== 6. Certificates & Verification =====
CREATE TABLE IF NOT EXISTS certificates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  certificate_number VARCHAR(30) NOT NULL UNIQUE,
  record_type ENUM('birth','death','marriage','divorce') NOT NULL,
  record_id INT UNSIGNED NOT NULL,
  verification_token VARCHAR(64) NOT NULL UNIQUE,
  issue_date DATE NOT NULL,
  issued_by INT UNSIGNED NOT NULL,
  fee_amount DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  payment_status ENUM('unpaid','paid') NOT NULL DEFAULT 'unpaid',
  paid_by INT UNSIGNED NULL,
  paid_at DATETIME NULL,
  status ENUM('valid','revoked') NOT NULL DEFAULT 'valid',
  signature_image VARCHAR(255) NULL,
  signed_by INT UNSIGNED NULL,
  signed_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_cert_number (certificate_number),
  INDEX idx_cert_record (record_type, record_id),
  INDEX idx_cert_token (verification_token),
  INDEX idx_cert_issue_date (issue_date),
  INDEX idx_cert_status (status),
  FOREIGN KEY (issued_by) REFERENCES users(id),
  FOREIGN KEY (paid_by) REFERENCES users(id),
  FOREIGN KEY (signed_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS certificate_verifications (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  certificate_id INT UNSIGNED NULL,
  token_prefix VARCHAR(16) NOT NULL,
  result ENUM('valid','revoked','not_found') NOT NULL,
  ip_address VARCHAR(45) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_cert_verif (certificate_id),
  FOREIGN KEY (certificate_id) REFERENCES certificates(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ===== 7. System Support, Notifications & Audit =====
CREATE TABLE IF NOT EXISTS notifications (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  title VARCHAR(120) NOT NULL,
  message VARCHAR(255) NOT NULL,
  type VARCHAR(30) NOT NULL DEFAULT 'info',
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  link VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_notif_user (user_id, is_read),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  username VARCHAR(60) NULL,
  action VARCHAR(50) NOT NULL,
  module VARCHAR(40) NOT NULL,
  record_type VARCHAR(20) NULL,
  record_id INT UNSIGNED NULL,
  description VARCHAR(500) NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_audit_user (user_id),
  INDEX idx_audit_action (action),
  INDEX idx_audit_created (created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS backup_logs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  created_by INT UNSIGNED NULL,
  file_name VARCHAR(150) NOT NULL,
  backup_type ENUM('manual','automatic') NOT NULL DEFAULT 'manual',
  file_size INT UNSIGNED NULL,
  status ENUM('success','failed') NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS contact_messages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(120) NOT NULL,
  phone VARCHAR(20) NULL,
  message TEXT NOT NULL,
  status ENUM('new','read','resolved') NOT NULL DEFAULT 'new',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ===== 8. Audit Logs Append-Only Enforcement =====
DELIMITER $$
CREATE TRIGGER audit_logs_no_update BEFORE UPDATE ON audit_logs FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit logs are append-only: modifications are prohibited';
END$$

CREATE TRIGGER audit_logs_no_delete BEFORE DELETE ON audit_logs FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit logs are append-only: deletions are prohibited';
END$$
DELIMITER ;
