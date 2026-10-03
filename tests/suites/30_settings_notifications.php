<?php
declare(strict_types=1);
/** System settings, in-app notifications and the digital assistant knowledge base. */

use App\Core\{Auth, Database};
use App\Services\{AssistantService, NotificationService, Settings, UserService};

purgeFixtures();
Auth::logout(false);
Auth::attempt('nasra', 'ChangeMe#2026');

echo "\nSettings Storage Tests\n";
$officeName = Settings::get('office_name');
check('a stored setting is returned', $officeName !== '' && $officeName === (string) Database::scalar('SELECT setting_value FROM system_settings WHERE setting_key = ?', ['office_name']));
check('an unknown key falls back to the default', Settings::get('no.such.setting', 'fallback') === 'fallback');
check('an unknown key with no default is an empty string', Settings::get('no.such.setting') === '');
check('an unknown record type has no fee', Settings::fee('adoption') === 0.0);
check('a fee is read as a number', Settings::fee('birth') === (float) Database::scalar('SELECT setting_value FROM system_settings WHERE setting_key = ?', ['fee_birth']));

Settings::set('test.scratch', 'first value');
check('a new setting is stored and read back', Settings::get('test.scratch') === 'first value' && (string) Database::scalar('SELECT setting_value FROM system_settings WHERE setting_key = ?', ['test.scratch']) === 'first value');
Settings::set('test.scratch', 'second value');
check('an existing setting is updated, not duplicated', Settings::get('test.scratch') === 'second value' && (int) Database::scalar('SELECT COUNT(*) FROM system_settings WHERE setting_key = ?', ['test.scratch']) === 1);
Settings::set('test.blank', '   ');
check('a blank stored value falls back to the default', Settings::get('test.blank', 'fallback') === 'fallback');

$allSettings = Settings::all();
check('all() returns every setting keyed by name', isset($allSettings['office_name'], $allSettings['fee_birth'], $allSettings['test.scratch']) && count($allSettings) === (int) Database::scalar('SELECT COUNT(*) FROM system_settings'));
check('all() is ordered by key', array_keys($allSettings) === (static function (array $keys): array { sort($keys); return $keys; })(array_keys($allSettings)));

Database::execute('DELETE FROM system_settings WHERE setting_key LIKE ?', ['test.%']);
check('scratch settings are removed again', (int) Database::scalar('SELECT COUNT(*) FROM system_settings WHERE setting_key LIKE ?', ['test.%']) === 0);

echo "\nNotification Tests\n";
$recipient = UserService::create(['full_name' => 'Test Notifier', 'username' => 'test_notify', 'role_name' => 'officer', 'password' => 'TestNotify#2026']);
$recipientId = (int) $recipient['id'];

$notificationId = NotificationService::send($recipientId, 'Record approved', 'Your birth record was approved.', 'success', 'records/view.php?type=birth&id=1');
check('a notification returns its new id', $notificationId > 0);
$row = NotificationService::forUser($recipientId)[0] ?? [];
check('a notification keeps its type and link', ($row['type'] ?? '') === 'success' && ($row['link'] ?? '') === 'records/view.php?type=birth&id=1');
check('a notification starts unread', (int) ($row['is_read'] ?? 1) === 0);
check('the unread count follows the new notification', NotificationService::unreadCount($recipientId) === 1);

NotificationService::send($recipientId, 'Correction required', 'Supervisor requested a correction.', 'warning');
NotificationService::send($recipientId, 'New record pending', 'A record was submitted for review.', 'info');
check('every unread notification is counted', NotificationService::unreadCount($recipientId) === 3);
$list = NotificationService::forUser($recipientId);
check('notifications are listed newest first', ($list[0]['title'] ?? '') === 'New record pending' && count($list) === 3);
check('the list can be limited', count(NotificationService::forUser($recipientId, 2)) === 2);
check('a notification list is empty for a user with none', NotificationService::forUser(999999) === []);
check('a guest has no notifications and no unread count', NotificationService::forUser(0) === [] && NotificationService::unreadCount(0) === 0);

check('marking a notification read reports success', NotificationService::markAsRead($notificationId, $recipientId));
check('the unread count drops after reading', NotificationService::unreadCount($recipientId) === 2);
check('another user cannot read somebody else\'s notification', !NotificationService::markAsRead($notificationId, $recipientId + 1));
check('reading an unknown notification reports nothing to do', !NotificationService::markAsRead(999999, $recipientId));

check('marking everything read is reported as done', NotificationService::markAllAsRead($recipientId));
check('nothing is left unread', NotificationService::unreadCount($recipientId) === 0);
check('clearing read notifications removes them', NotificationService::clearRead($recipientId) === 3 && NotificationService::forUser($recipientId) === []);
NotificationService::send($recipientId, 'Later notice', 'Sent after the inbox was cleared.', 'info');
check('clearing read notifications keeps unread ones', NotificationService::clearRead($recipientId) === 0 && NotificationService::unreadCount($recipientId) === 1);

// The session default must resolve to the signed-in account.
Auth::logout(false);
Auth::attempt('test_notify', 'TestNotify#2026');
check('the unread count defaults to the signed-in user', NotificationService::unreadCount() === 1);
check('the notification list defaults to the signed-in user', count(NotificationService::forUser()) === 1);
Auth::logout(false);

echo "\nDigital Assistant Tests\n";
check('a birth question finds the birth guidance', (static function (): bool {
    $a = AssistantService::ask('How do I register a birth?');
    return $a['found'] === true && str_contains($a['title'], 'Birth');
})());
check('a mahr question finds the marriage guidance', (static function (): bool {
    $a = AssistantService::ask('what is the mahr for a marriage?');
    return $a['found'] === true && str_contains($a['title'], 'Marriage');
})());
check('a verification question finds the certificate guidance', (static function (): bool {
    $a = AssistantService::ask('how do I verify a certificate token?');
    return $a['found'] === true && str_contains($a['title'], 'Certificate');
})());
check('a court question finds the divorce guidance', (static function (): bool {
    $a = AssistantService::ask('divorce decree from the court');
    return $a['found'] === true && str_contains($a['title'], 'Divorce');
})());
check('a guarantor question finds the death guidance', (static function (): bool {
    $a = AssistantService::ask('who is the guarantor for a death?');
    return $a['found'] === true && str_contains($a['title'], 'Death');
})());
check('a supervisor question finds the workflow guidance', (static function (): bool {
    $a = AssistantService::ask('can a supervisor approve my own record?');
    return $a['found'] === true && str_contains($a['title'], 'Approval');
})());
check('matching ignores case', AssistantService::ask('BIRTH REGISTRATION')['found'] === true);
check('an empty question asks for one', AssistantService::ask('   ')['found'] === false);
check('an unrelated question is not answered', AssistantService::ask('quantum banana teleport')['found'] === false);
check('the assistant exposes no way to change a record', get_class_methods(AssistantService::class) === ['ask']);

$districtAnswer = AssistantService::ask('which districts of banadir are covered?')['answer'];
$missingDistricts = array_values(array_filter(
    Database::fetchAll('SELECT name FROM districts'),
    static fn(array $d): bool => !str_contains($districtAnswer, (string) $d['name'])
));
check('the assistant lists every district in the database', $missingDistricts === [], 'missing: ' . implode(', ', array_column($missingDistricts, 'name')));

purgeFixtures();
Auth::logout(false);
