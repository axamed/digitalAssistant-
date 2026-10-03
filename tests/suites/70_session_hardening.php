<?php
declare(strict_types=1);
/** Session cookie hardening, flash messages, and the guards that end a request. */

use App\Core\{Auth, Config, Csrf, Database, Session};

echo "\nSession Cookie Tests\n";
$cookie = session_get_cookie_params();
check('the session cookie is HttpOnly', $cookie['httponly'] === true);
check('the session cookie is SameSite Lax', ($cookie['samesite'] ?? '') === 'Lax', var_export($cookie['samesite'] ?? null, true));
check('the session cookie expires when the browser closes', (int) $cookie['lifetime'] === 0);
check('the session cookie is limited to the site path', ($cookie['path'] ?? '') === '/');
check('the session cookie is only sent over HTTPS when the site uses it', $cookie['secure'] === false);
check('the session cookie is named from the configuration', session_name() === (string) Config::get('app.session.name', 'crs_session'), session_name());
check('uninitialised session ids are refused', ini_get('session.use_strict_mode') === '1');
check('the session id cannot travel in a URL', ini_get('session.use_only_cookies') === '1');

echo "\nSign-In and Sign-Out Tests\n";
Auth::logout(false);
check('no user is signed in to begin with', Auth::check() === false && Auth::user() === null);
check('Auth::id() is null when signed out', Auth::id() === null);
check('a wrong password signs nobody in', Auth::attempt('nasra', 'WrongPassword!999') !== true && Auth::check() === false);
check('an unknown user signs nobody in', Auth::attempt('nobody-here', 'ChangeMe#2026') !== true && Auth::check() === false);
$tokenBeforeLogin = Csrf::token();
check('the right password signs the user in', Auth::attempt('nasra', 'ChangeMe#2026') === true);
check('the signed-in user is readable from the session', (Auth::user()['username'] ?? '') === 'nasra');
$signedInUser = Auth::user();
check('the signed-in user carries a role and a home page', isset($signedInUser['role'], $signedInUser['role_label']) && Auth::homePath() === Auth::HOME[$signedInUser['role']], Auth::homePath());
check('signing in leaves an open form usable', Csrf::token() === $tokenBeforeLogin);
check('a signed-out user has no permissions', (static function (): bool {
    $before = Auth::can('settings.manage');
    Auth::logout(false);
    return $before === true && Auth::can('settings.manage') === false;
})());
check('signing out removes the user from the session', Auth::check() === false && Auth::user() === null);
check('signing out empties the session', Session::get('anything') === null);
check('signing out replaces the CSRF token', Csrf::token() !== $tokenBeforeLogin);

echo "\nFlash Message Tests\n";
flash('success', 'Record approved.');
flash('warning', 'Fee still unpaid.');
$first = Session::pullFlash();
check('flash messages are returned in the order they were set', array_column($first, 'message') === ['Record approved.', 'Fee still unpaid.']);
check('flash messages keep their type', array_column($first, 'type') === ['success', 'warning']);
check('flash messages are shown only once', Session::pullFlash() === []);
flash('danger', 'Something failed.');
check('a flash message set after a pull is still delivered', array_column(Session::pullFlash(), 'message') === ['Something failed.']);

echo "\nEscaping Tests\n";
check('e() renders nothing for an array', e(['a' => 'b']) === '');
check('e() renders nothing for a plain object', e(new stdClass()) === '');
check('e() still renders an object with a string form', e(new class {
    public function __toString(): string
    {
        return 'Hodan';
    }
}) === 'Hodan');
check('e() escapes an ampersand and a single quote', e("Ali & Sons'") === 'Ali &amp; Sons&#039;');

echo "\nDeactivated Account Tests\n";
// requireLogin() ends the request, so it is observed from a child process: with an active
// account the marker is written, with a deactivated one the process stops before writing it.
$reachedWhenActive = runSnippet(
    'App\Core\Auth::attempt("faduma", "ChangeMe#2026");' . "\n"
    . 'App\Core\Auth::requireLogin();' . "\n"
    . 'file_put_contents(SNIPPET_OUT, "REACHED");'
);
check('an active account passes the sign-in check', $reachedWhenActive['file'] === 'REACHED', $reachedWhenActive['file']);

$fadumaId = (int) Database::scalar("SELECT id FROM users WHERE username = 'faduma'");
$reachedWhenDeactivated = runSnippet(
    'App\Core\Auth::attempt("faduma", "ChangeMe#2026");' . "\n"
    . 'App\Core\Database::execute("UPDATE users SET status = ? WHERE username = ?", ["inactive", "faduma"]);' . "\n"
    . 'App\Core\Auth::requireLogin();' . "\n"
    . 'file_put_contents(SNIPPET_OUT, "REACHED");'
);
Database::execute("UPDATE users SET status = 'active' WHERE id = ?", [$fadumaId]);
check('an account disabled mid-session is signed out on its next request', $reachedWhenDeactivated['file'] === '', 'marker: ' . var_export($reachedWhenDeactivated['file'], true));
check('the account is usable again once reactivated', Auth::attempt('faduma', 'ChangeMe#2026') === true);
Auth::logout(false);

echo "\nIdle Session Tests\n";
// A session older than the configured lifetime is thrown away on the next request, which is a
// branch that never runs in the rest of the suite. PHP's CLI marks headers as sent once a session
// has been opened, so re-opening one here warns; the decision under test is the timeout, not PHP's
// cookie handling, so the child processes mute warnings.
$quietCli = "error_reporting(E_ALL & ~E_WARNING);\n";

$idle = runSnippet(
    $quietCli
    . 'App\Core\Session::start();' . "\n"
    . '$_SESSION["user"] = ["id" => 1, "username" => "nasra"];' . "\n"
    . '$_SESSION["_last"] = time() - 3600;' . "\n"
    . 'session_write_close();' . "\n"
    . 'App\Core\Session::start();' . "\n"
    . 'file_put_contents(SNIPPET_OUT, (string) (int) isset($_SESSION["user"]));'
);
check('an idle session is discarded', $idle['file'] === '0', 'user still present: ' . $idle['file']);

$idleFlash = runSnippet(
    $quietCli
    . 'App\Core\Session::start();' . "\n"
    . '$_SESSION["user"] = ["id" => 1];' . "\n"
    . '$_SESSION["_last"] = time() - 3600;' . "\n"
    . 'session_write_close();' . "\n"
    . 'App\Core\Session::start();' . "\n"
    . 'file_put_contents(SNIPPET_OUT, json_encode(App\Core\Session::pullFlash()));'
);
$messages = json_decode($idleFlash['file'], true) ?: [];
check('an expired session explains itself', str_contains($idleFlash['file'], 'session expired'), $idleFlash['file']);
check('the explanation is a warning flash', ($messages[0]['type'] ?? '') === 'warning', $idleFlash['file']);

$lifetime = (int) Config::get('app.session.lifetime', 1800);
$fresh = runSnippet(
    $quietCli
    . 'App\Core\Session::start();' . "\n"
    . '$_SESSION["user"] = ["id" => 1, "username" => "nasra"];' . "\n"
    . '$_SESSION["_last"] = time();' . "\n"
    . 'session_write_close();' . "\n"
    . 'App\Core\Session::start();' . "\n"
    . 'file_put_contents(SNIPPET_OUT, (string) (int) isset($_SESSION["user"]));'
);
check('a session inside the lifetime is kept', $fresh['file'] === '1', 'user: ' . $fresh['file']);
check('the configured idle timeout is a sensible number of minutes', $lifetime >= 300 && $lifetime <= 7200, (string) $lifetime);

Auth::logout(false);
