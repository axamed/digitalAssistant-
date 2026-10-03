<?php
/**
 * Sidebar definition.
 *
 * Every item is filtered by permission, not by role, so a user who is granted a
 * permission sees exactly the section that permission unlocks and nothing else.
 * An item with no 'permission' key is open to every signed-in account.
 *
 *   label       text shown in the sidebar
 *   path        page the item links to
 *   permission  permission code required, or a list of codes where any one will do
 *   roles       optional desk restriction; administrators are always let through
 *   section     optional heading; items sharing a section are grouped under it
 */
return [
    [
        'section' => 'My Work',
        'items'   => [
            ['label' => 'Dashboard', 'path' => 'dashboard.php'],
        ],
    ],
    [
        'section' => 'Registration',
        'items'   => [
            ['label' => 'Register Birth', 'path' => 'officer/birth.php', 'permission' => 'birth.create'],
            ['label' => 'Register Death', 'path' => 'officer/death.php', 'permission' => 'death.create'],
            ['label' => 'Register Marriage', 'path' => 'officer/marriage.php', 'permission' => 'marriage.create'],
            ['label' => 'Register Divorce', 'path' => 'officer/divorce.php', 'permission' => 'divorce.create'],
        ],
    ],
    [
        'section' => 'Records',
        'items'   => [
            ['label' => 'My Records', 'path' => 'officer/my-records.php', 'permission' => 'record.search', 'roles' => ['officer']],
            ['label' => 'All Records', 'path' => 'records/all-records.php', 'permission' => 'record.view'],
            ['label' => 'Search Records', 'path' => 'records/search.php', 'permission' => 'record.search'],
        ],
    ],
    [
        'section' => 'Approval &amp; Review',
        'items'   => [
            ['label' => 'Pending Approvals', 'path' => 'supervisor/approvals.php', 'permission' => 'record.approve'],
            ['label' => 'Officer Activity', 'path' => 'supervisor/activity.php', 'permission' => 'audit.view'],
        ],
    ],
    [
        'section' => 'Reports',
        'items'   => [
            ['label' => 'Reports &amp; Analytics', 'path' => 'supervisor/reports.php', 'permission' => 'report.generate'],
        ],
    ],
    [
        'section' => 'Certificates',
        'items'   => [
            ['label' => 'Certificates', 'path' => 'certificates/index.php', 'permission' => 'certificate.print'],
            ['label' => 'Certificate Register', 'path' => 'admin/certificates.php', 'permission' => 'certificate.revoke', 'roles' => ['administrator']],
        ],
    ],
    [
        'section' => 'Administration',
        'items'   => [
            ['label' => 'Users', 'path' => 'admin/users.php', 'permission' => 'user.manage'],
            ['label' => 'Roles &amp; Permissions', 'path' => 'admin/roles.php', 'permission' => 'role.manage'],
            ['label' => 'Audit Logs', 'path' => 'admin/audit.php', 'permission' => 'audit.view'],
            ['label' => 'Database Backup', 'path' => 'admin/backup.php', 'permission' => 'backup.manage'],
            ['label' => 'Settings &amp; Fees', 'path' => 'admin/settings.php', 'permission' => 'settings.manage'],
        ],
    ],
    [
        'section' => 'Tools',
        'items'   => [
            ['label' => 'Digital Assistant', 'path' => 'assistant.php'],
        ],
    ],
];