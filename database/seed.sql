-- Reference data: roles, permissions, districts, settings.
-- Run after schema.sql.
USE civil_registration;

-- 1. Roles
INSERT INTO roles (name, label) VALUES
 ('administrator', 'System Administrator'),
 ('officer', 'Civil Registration Officer'),
 ('supervisor', 'Supervisor / Manager')
ON DUPLICATE KEY UPDATE label=VALUES(label);

-- 2. Permissions
INSERT INTO permissions (code, description) VALUES
 ('user.manage', 'Create, edit, activate and deactivate users'),
 ('role.manage', 'Manage roles and permissions'),
 ('settings.manage', 'Change system settings and fees'),
 ('backup.manage', 'Create and download backups'),
 ('audit.view', 'View audit logs'),
 ('birth.create', 'Register birth records'),
 ('death.create', 'Register death records'),
 ('marriage.create', 'Register marriage records'),
 ('divorce.create', 'Register divorce records'),
 ('record.search', 'Search records'),
 ('record.view', 'View record details'),
 ('record.update', 'Update permitted records'),
 ('record.delete', 'Delete or archive permitted records'),
 ('record.submit', 'Submit records for approval'),
 ('record.review', 'Review submitted records'),
 ('record.approve', 'Approve records with digital signature'),
 ('record.reject', 'Reject records or request correction'),
 ('report.generate', 'Generate and export administrative reports'),
 ('certificate.print', 'Preview and print official certificates'),
 ('certificate.pay', 'Record certificate fee payment'),
 ('certificate.revoke', 'Revoke or restore certificates')
ON DUPLICATE KEY UPDATE description=VALUES(description);

-- 3. Role Permissions mapping
DELETE FROM role_permissions;

-- Administrator permissions: the whole catalogue. The administrator is the superuser, so a
-- fresh install must start with every permission already granted, including any added later.
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.name = 'administrator';

-- Officer permissions
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.name = 'officer'
  AND p.code IN (
    'birth.create', 'death.create', 'marriage.create', 'divorce.create',
    'record.search', 'record.view', 'record.update', 'record.delete', 'record.submit',
    'certificate.print', 'certificate.pay'
  );

-- Supervisor permissions
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.name = 'supervisor'
  AND p.code IN (
    'record.review', 'record.approve', 'record.reject', 'record.update', 'record.delete',
    'record.search', 'record.view', 'report.generate', 'audit.view',
    'certificate.print', 'certificate.pay', 'certificate.revoke'
  );

-- 4. Districts (all 20 Banadir districts)
INSERT INTO districts (name) VALUES
 ('Abdiaziz'),
 ('Bondhere'),
 ('Darul Salaam'),
 ('Daynile'),
 ('Dharkenley'),
 ('Garas Baley'),
 ('Gubaadley'),
 ('Hamar Jajab'),
 ('Hamar Weyne'),
 ('Hawl Wadag'),
 ('Heliwa'),
 ('Hodan'),
 ('Kaxda'),
 ('Karan'),
 ('Shangani'),
 ('Shibis'),
 ('Waberi'),
 ('Wadajir'),
 ('Wardhiigleey'),
 ('Yaqshid')
ON DUPLICATE KEY UPDATE name=VALUES(name);

-- 5. System Settings
INSERT INTO system_settings (setting_key, setting_value) VALUES
 ('office_name', 'Civil Registration Office, Mogadishu Municipal Government'),
 ('office_address', 'Mogadishu Municipality Headquarters, Hamar Weyne District, Banadir Region, Somalia'),
('office_phone', '+252 61 521 2845'),
  ('office_whatsapp', '252615212845'),
  ('office_email', 'registry@mogadishu.gov.so'),
 ('office_hours', 'Saturday - Thursday: 8:00 AM - 3:30 PM, Friday: Closed'),
 ('certificate_prefix', 'CERT'),
  ('fee_birth', '20.00'),
  ('fee_marriage', '25.00'),
  ('fee_death', '15.00'),
  ('fee_divorce', '30.00')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
