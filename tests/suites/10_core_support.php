<?php
declare(strict_types=1);
/** Core support layer: view helpers, CSRF, the remaining validator rules, record type map, upload guards. */

use App\Core\{Config, Csrf};
use App\Services\UploadService;
use App\Support\{Age, RecordRules, RecordTypes, Validator};

echo "\nAge Boundary Tests\n";
// Ages are printed on certificates and record views, so a one-day-old and a leap-day birthday
// have to read correctly, not just compute to a number.
check('a one day old newborn is singular', Age::between('2026-09-20', '2026-09-21') === '1 day');
check('a two day old newborn is plural', Age::between('2026-09-20', '2026-09-22') === '2 days');
check('a birth and the same day is zero days old', Age::between('2026-09-20', '2026-09-20') === '0 days');
check('one month old is singular', Age::between('2026-08-20', '2026-09-20') === '1 mo');
check('a 29 February birthday is still one on 28 February', Age::between('2024-02-29', '2026-02-28') === '1 yr');
check('a 29 February birthday turns two on 1 March', Age::between('2024-02-29', '2026-03-01') === '2 yrs');
check('an unparseable date of birth is null', Age::between('not-a-date') === null);

echo "\nView Helper Tests\n";
check('e() escapes HTML metacharacters', e('<script>"x"&\'y\'</script>') === '&lt;script&gt;&quot;x&quot;&amp;&#039;y&#039;&lt;/script&gt;');
check('e() passes plain text through', e('Hodan District') === 'Hodan District');
check('e() escapes null as an empty string', e(null) === '');
check('money() formats an amount', money(20) === '$20.00' && money('20.5') === '$20.50');
check('money() reports an unset amount', money(null) === 'Not set' && money(0) === 'Not set' && money(-5) === 'Not set');
check('url() prefixes the configured base URL', url('login.php') === rtrim((string) Config::get('app.base_url', ''), '/') . '/login.php');
check('asset() points at the public assets folder', str_ends_with(asset('css/app.css'), '/assets/css/app.css'));
check('is_post() is false outside a POST request', is_post() === false);
check('old() escapes the submitted value', old('nonexistent_field', '<b>x</b>') === '&lt;b&gt;x&lt;/b&gt;');

echo "\nCSRF Tests\n";
$csrfToken = Csrf::token();
check('token is 64 hexadecimal characters', (bool) preg_match('/^[0-9a-f]{64}$/', $csrfToken));
check('token is stable within a session', Csrf::token() === $csrfToken);
check('field() renders a hidden input carrying the token', Csrf::field() === '<input type="hidden" name="_csrf" value="' . $csrfToken . '">');
check('a matching token passes Csrf::check()', (function () use ($csrfToken): bool {
    $_POST['_csrf'] = $csrfToken;
    Csrf::check();          // must return normally
    unset($_POST['_csrf']);
    return true;
})());

// A rejected token ends the request, so it can only be observed from another process.
$blocked = runSnippet(
    '$_POST["_csrf"] = "not-the-real-token";' . "\n"
    . 'App\Core\Csrf::check();' . "\n"
    . 'file_put_contents(SNIPPET_OUT, "REQUEST COMPLETED");'
);
check('a wrong token halts the request with 419', $blocked['file'] === '' && str_contains($blocked['stdout'], '419'));
check('a missing token halts the request with 419', runSnippet(
    'App\Core\Csrf::check();' . "\n"
    . 'file_put_contents(SNIPPET_OUT, "REQUEST COMPLETED");'
)['file'] === '');

echo "\nValidator Rule Tests\n";
$v2 = Validator::make(['name' => 'Abdi', 'note' => str_repeat('x', 40)])
    ->maxLength('name', 'Full name', 2)
    ->maxLength('note', 'Note', 10);
check('maxLength fails an over-long value', isset($v2->errors()['name']) && $v2->errors()['name'] === 'Full name must be at most 2 characters.');
check('maxLength passes a value at the limit', !isset(Validator::make(['n' => 'abcd'])->maxLength('n', 'Note', 4)->errors()['n']));
check('maxLength measures characters, not bytes', !isset(Validator::make(['n' => 'Soomaali'])->maxLength('n', 'Name', 8)->errors()['n']));

check('required trims before deciding', isset(Validator::make(['n' => '   '])->required('n', 'Name')->errors()['n']));
check('onOrAfter accepts the same date', !isset(Validator::make(['a' => '2026-05-01', 'b' => '2026-05-01'])->onOrAfter('a', 'A', 'b', 'B')->errors()['a']));
check('onOrAfter fails on an earlier date', isset(Validator::make(['a' => '2026-04-30', 'b' => '2026-05-01'])->onOrAfter('a', 'A', 'b', 'B')->errors()['a']));
check('before fails when the dates are equal', isset(Validator::make(['a' => '2026-05-01', 'b' => '2026-05-01'])->before('a', 'A', 'b', 'B')->errors()['a']));
check('before passes when strictly earlier', !isset(Validator::make(['a' => '2026-04-30', 'b' => '2026-05-01'])->before('a', 'A', 'b', 'B')->errors()['a']));

check('date rejects a non-padded date', isset(Validator::make(['d' => '2026-1-1'])->date('d', 'Date')->errors()['d']));
check('date rejects an impossible day', isset(Validator::make(['d' => '2026-02-30'])->date('d', 'Date')->errors()['d']));
check('date ignores an empty value', !isset(Validator::make(['d' => ''])->date('d', 'Date')->errors()['d']));
check('notFuture accepts today', !isset(Validator::make(['d' => date('Y-m-d')])->notFuture('d', 'Date')->errors()['d']));
check('the first error on a field is the one reported', Validator::make(['d' => '2030-13-45'])
    ->date('d', 'Registration date')
    ->notFuture('d', 'Registration date')
    ->errors()['d'] === 'Registration date is not a valid date.');

check('phone accepts a Somali mobile number', !isset(Validator::make(['p' => '+252 61 511 2233'])->phone('p', 'Phone')->errors()['p']));
check('phone rejects letters', isset(Validator::make(['p' => 'abc'])->phone('p', 'Phone')->errors()['p']));
check('phone rejects a value that is too long', isset(Validator::make(['p' => '1234567890123456'])->phone('p', 'Phone')->errors()['p']));
check('phone rejects a value that is too short', isset(Validator::make(['p' => '12345'])->phone('p', 'Phone')->errors()['p']));

check('in() accepts a listed option', !isset(Validator::make(['g' => 'male'])->in('g', 'Gender', RecordTypes::GENDERS)->errors()['g']));
check('in() rejects an unlisted option', isset(Validator::make(['g' => 'M'])->in('g', 'Gender', RecordTypes::GENDERS)->errors()['g']));
check('numeric accepts a decimal amount', !isset(Validator::make(['n' => '20.50'])->numeric('n', 'Amount')->errors()['n']));
check('numeric rejects text', isset(Validator::make(['n' => 'twenty'])->numeric('n', 'Amount')->errors()['n']));
check('min accepts the boundary value', !isset(Validator::make(['n' => '0'])->min('n', 'Amount', 0.0)->errors()['n']));
check('min rejects a negative amount', Validator::make(['n' => '-1'])->min('n', 'Amount', 0.0)->errors()['n'] === 'Amount must be at least 0.');
check('email accepts an address', !isset(Validator::make(['e' => 'registry@mogadishu.gov.so'])->email('e', 'Email')->errors()['e']));
check('email rejects a malformed address', isset(Validator::make(['e' => 'registry@'])->email('e', 'Email')->errors()['e']));
check('matches fails on a confirmation mismatch', isset(Validator::make(['p' => 'Secret#2026x', 'c' => 'Secret#2026'])->matches('c', 'p', 'Passwords do not match.')->errors()['c']));
check('matches passes when both values agree', !isset(Validator::make(['p' => 'same', 'c' => 'same'])->matches('c', 'p', 'Passwords do not match.')->errors()['c']));
check('custom attaches a fixed message', Validator::make([])->custom('field', 'Potential duplicate detected.')->errors()['field'] === 'Potential duplicate detected.');
check('errors() is empty and fails() is false for clean input', Validator::make(['n' => 'ok'])->required('n', 'Name')->errors() === [] && !Validator::make(['n' => 'ok'])->required('n', 'Name')->fails());

echo "\nRecord Type Map Tests\n";
check('all four record types are mapped', count(RecordTypes::ALL) === 4 && RecordTypes::exists('marriage') && !RecordTypes::exists('adoption'));
check('table() resolves the SQL whitelist name', RecordTypes::table('divorce') === 'divorce_records');
check('table() refuses an unknown type', (function (): bool {
    try {
        RecordTypes::table('adoption');
        return false;
    } catch (\InvalidArgumentException $e) {
        return true;
    }
})());
check('birth fields are grouped for the form', count(RecordTypes::groups('birth')) >= 3 && isset(RecordTypes::fields('birth')['child_full_name']));
check('every field carries a label for the validator', array_reduce(
    RecordTypes::fields('death'),
    static fn(bool $ok, array $f): bool => $ok && ($f['label'] ?? '') !== '',
    true
));
check('a divorce is identified by its marriage reference', RecordRules::duplicateField('divorce') === 'marriage_registration_number');
check('a birth duplicate is reported on the child name', RecordRules::duplicateField('birth') === 'child_full_name');

echo "\nUpload Guard Tests\n";
$tmpUpload = (string) tempnam(sys_get_temp_dir(), 'crs_upload_');
file_put_contents($tmpUpload, "<?php echo 'not an image';");
$uploadError = null;
try {
    UploadService::handleUpload([
        'name' => 'photo.png', 'type' => 'image/png', 'tmp_name' => $tmpUpload,
        'error' => UPLOAD_ERR_OK, 'size' => filesize($tmpUpload),
    ], 'birth');
} catch (\RuntimeException $e) {
    $uploadError = $e->getMessage();
}
@unlink($tmpUpload);
check('a non-image upload is refused whatever the browser claimed', $uploadError !== null && str_contains($uploadError, 'Invalid file type'));

$uploadError = null;
try {
    UploadService::handleUpload(['error' => UPLOAD_ERR_INI_SIZE], 'birth');
} catch (\RuntimeException $e) {
    $uploadError = $e->getMessage();
}
check('a failed upload reports its error code', $uploadError !== null && str_contains($uploadError, 'error code: ' . UPLOAD_ERR_INI_SIZE));

$uploadError = null;
try {
    UploadService::handleUpload(['error' => [UPLOAD_ERR_OK], 'tmp_name' => $tmpUpload, 'size' => 10], 'birth');
} catch (\RuntimeException $e) {
    $uploadError = $e->getMessage();
}
check('a malformed file array is refused', $uploadError !== null && str_contains($uploadError, 'Invalid file upload parameters'));

$uploadError = null;
try {
    UploadService::handleUpload(['error' => UPLOAD_ERR_OK, 'tmp_name' => $tmpUpload, 'size' => 3 * 1024 * 1024], 'birth');
} catch (\RuntimeException $e) {
    $uploadError = $e->getMessage();
}
check('an upload over 2 MB is refused', $uploadError !== null && str_contains($uploadError, '2 MB'));
