# Architecture notes

## Request lifecycle
1. Apache serves a file from `public/`.
2. The page requires `app/bootstrap.php` (autoloader, error handler, secure session).
3. Protected pages call `Auth::requireRole()` / `Auth::requirePermission()`, which also re-checks that the account is still active.
4. POST handlers call `Csrf::check()`, then `Validator`, then a Service, then redirect with a flash message.
5. Services use `Database` (PDO, prepared statements) and write `Audit::log()` entries.

## Roles and permissions
Roles: administrator, officer, supervisor. Permissions are rows in `permissions` linked through `role_permissions` and read fresh on every request, so a grant or a revoke takes effect on an account's next click.

The **administrator is the superuser**. `Auth::isAdmin()` short-circuits `Auth::can()`, `Auth::canAny()` and `Auth::requireRole()`, so an administrator holds every permission whether or not the rows say so, and reaches every desk. Three things keep that from becoming a way to lock the system out: `RoleService::setPermissions()` tops the administrator role back up to the whole catalogue instead of accepting a narrower set, `RoleService::createPermission()` grants a new permission to the administrator as it is created, and `RoleService::syncAdministrator()` (called when the roles screen opens) repairs an install that predates this model. `admin/roles.php` therefore shows the administrator's boxes ticked and disabled, and no Save button, because the service would ignore the post anyway.

## Account lockout
Five failed sign-ins set `users.locked_until` to 15 minutes ahead (`app.security` config). While locked, `Auth::attempt` refuses the account and increments nothing. An administrator clears a lockout from the user list (the Unlock button appears only while locked) via `UserService::unlock()`, which also resets `failed_attempts`. A successful sign-in clears both counters.

Accounts still on the default password carry `must_change_password`; `Auth::requireLogin()` then permits only `change-password.php` and `logout.php`. The same method re-reads `users.status` on every request, so disabling an account signs it out on its next page rather than at its next sign-in.

## Session and CSRF
The session cookie is a session cookie (`lifetime = 0`), `HttpOnly`, `SameSite=Lax`, and `Secure` only when the site itself is served over HTTPS. `use_strict_mode` and `use_only_cookies` are both on, so an attacker cannot fix a session id of their choosing and a session id can never travel in a URL. `Auth::attempt()` calls `Session::regenerate()` after the password check, so a successful sign-in always gets a new id. A session idle for longer than `app.session.lifetime` is destroyed on the next request and the user is told to sign in again. CSRF tokens are 32 random bytes, compared with `hash_equals()`, and are replaced on sign-out.

`e()` returns an empty string for an array or an object without `__toString`, so a view mistake shows nothing instead of printing the word "Array" on an official page and a warning in the log.

One property cannot be asserted from the command line: PHP refuses to rotate a session id once output has been sent, so under the CLI SAPI `Session::regenerate()` is a no-op. The rotation is therefore covered by reading the code path, not by a check, and the suite says so rather than pretending.

## Record workflow
draft → submitted → under_review → approved | rejected | correction_required → (updated) → resubmitted.
Every transition inserts a row in `record_status_history` and `approvals` and an entry in `audit_logs`. Approved records are locked; approval creates a certificate (unpaid) whose fee comes from `system_settings`. Printing requires `payment_status = 'paid'` and `status = 'valid'`.

`CertificateService::issueForRecord()` refuses any record that is not `approved`, so a certificate cannot be minted for a draft by calling the service directly. It returns the existing certificate when one is already present, which keeps a revoked certificate revoked rather than quietly issuing a second one for the same record — re-issuing after a revocation is a deliberate act, not a side effect of re-approval. `markPaid()` checks `certificate.pay` itself and refuses a revoked certificate, so the guards hold even if a future caller skips the page-level checks.

## Registration numbers
Generated inside a transaction from `number_sequences` (prefix + year), e.g. BR-2026-0001. Numbers are unique and strictly increasing per year; they are not gap-free, because a rolled back unit of work and a deleted certificate both consume a number. A gap is acceptable in an official register, reusing a number is not.

## Public verification
`verify.php?token=...` returns only certificate type, number, registration number, issue date and status (Valid, Revoked, Not Found). Every lookup is stored in `certificate_verifications` (only the first 8 token characters).

## Digital signatures
`SignatureService::saveFromDataUrl()` accepts only `data:image/(png|jpeg);base64,...`, caps the decoded size at 500 KB, and confirms with `getimagesizefromstring()` that the bytes really are a PNG or JPEG before writing to `storage/uploads/signatures` (outside the web root). The extension follows the detected type, and `SignatureService::dataUri()` re-reads the MIME type when inlining a signature on the preview and print pages, so a JPEG is never labelled as a PNG.

## Profile photo
A staff photo follows the same rule as a signature, for the same reason: `storage/` is not web-facing, so the file cannot simply be linked. `PhotoService::saveUpload()` takes the upload, checks the error code and the 2 MB cap, then confirms with `getimagesize()` that the bytes are a JPG, PNG, GIF or WEBP and that neither side exceeds 3000 pixels — the name the browser sent is never trusted for either. It writes to `storage/uploads/avatars` under a name built from the account id, and `users.photo_path` keeps the relative path.

Two properties follow from that layout. `PhotoService::absolutePath()` refuses anything not under `avatars/` and strips `..`, so a path in the column cannot reach the rest of the disk. `PhotoService::delete()` unlinks only a file whose name carries the account's own `user_<id>_` stamp, so replacing or removing one member's photo can never delete another's — and the row is updated *before* the old file is removed, so a failed update never leaves an account without a photo. `public/avatar.php` then streams the signed-in member's own image and nothing else: it takes no user id, so a photo cannot be enumerated, and the MIME type is read from the file rather than from the extension. The sidebar and top bar ask for that URL through `app/Views/partials/avatar.php`, which falls back to the initials of the name when there is no photo, and `avatar_photo_stamp()` adds the file's modification time to the URL so a browser does not keep showing the old face.

## Tests
`php tests/run.php` is the only entry point. It holds the assertions of the original end-to-end flows, then loads every `tests/suites/*.php` file in filename order, so a suite can be read on its own:

| Suite | Covers |
| --- | --- |
| `10_core_support` | age boundaries, view helpers, CSRF, the remaining validator rules, record type map, upload guards |
| `15_records_listing` | the shared record listing query and the "My Records" / "All Records" screens |
| `20_users_roles` | password policy, account creation, lockout, deactivation rules, role permissions and the superuser administrator |
| `25_profile_security` | the member's own password, username and photo, and the profile screen that offers them |
| `30_settings_notifications` | settings storage, notification inbox, digital assistant knowledge base |
| `40_reports_stats` | district, monthly, officer, fee and dashboard reports, the individual event reports, the all-events register and their CSV exports |
| `50_backup_export` | database dump, backup permissions, CSV export |
| `60_certificates_payments` | certificate numbering and issuance, printing gates, fee collection, the fee permission guard and revocation |
| `70_session_hardening` | session cookie flags, sign-in and sign-out, flash messages, idle timeout, disabled account |

Two rules keep the suite repeatable, and both matter more than the number of checks:

- **Clean up after yourself.** `purgeRecordFixtures()` removes the records, certificates and history the suite created, `purgeTestUsers()` removes the `test_*` accounts (their notifications cascade). Accounts are removed *after* records, because `record_status_history.user_id` is a restricting foreign key. State that a test borrows from a seeded account (a lockout, `must_change_password`, a role's permission set) is snapshotted first and written back, so two consecutive runs print the same output. The one thing deliberately *not* rewound is the certificate counter: an official register may skip a number, but it must never issue the same one twice, so the sequence stays where the test took it.
- **Test the guards that end the request.** `Csrf::check()`, `Auth::requirePermission()` and `ExportService::downloadCsv()` call `abort()` or `exit()`, so they cannot be observed in-process. `runSnippet()` runs them in a child process and reports back what the process wrote and printed, which is how "a wrong token halts with 419" and "an officer without `backup.manage` gets 403" are asserted. A snippet that includes a page from `public/` passes `$bootstrap = false`, because a page requires `app/bootstrap.php` itself and loading it twice redeclares the helpers; the session is handed to the child instead, and the refusal is then observed exactly as it is in the browser.

A third rule came out of the role screen: **do not assert the configuration, assert the rules.** `admin/roles.php` lets an administrator move a permission between roles at any time, so a check like "an officer takes payments" fails the moment someone deliberately removes `certificate.pay` from the officer desk - a legitimate change, not a defect. The role suite therefore checks invariants that must hold whatever an administrator grants (every role keeps at least one permission, every grant refers to a real catalogue entry, the administrator always ends up with the whole catalogue), and the workflow signs in through `signInWithPermission('certificate.pay')`, which asks the database who may take a fee now instead of assuming a desk.

The superuser model makes that last point sharper rather than weaker, because it removes a whole class of fixture. No seeded account can stand in for "somebody without `certificate.pay`": the administrator passes every check, and an administrator may have moved the fee to any desk. So the fee guard is asserted the only way that holds under any configuration - a throwaway `test_fee_denied` account is created on a desk the paying user is not signed in on, `certificate.pay` is taken off that desk for the length of the child process and written straight back, and the 403 is then observed on an account that provably cannot hold the permission. `purgeTestUsers()` removes the account afterwards, and the suite checks that it did.

The four development accounts are borrowed the same way, and with the same care. `applySeedAccountState()` puts `nasra`, `abdi`, `faduma` and `mahad` into the state the README documents — active, unlocked, on `ChangeMe#2026` — so a database that has been used by hand (someone went through `change-password.php`, or a reset was issued) cannot fail the run on a password the suite never chose. `must_change_password` is cleared for the duration of the run, because a default-password account is redirected to `change-password.php` by `Auth::requireLogin()`, which ends the process. `restoreSeedAccounts()` writes the original rows back, and the shutdown hook calls it too, so an aborted run cannot leave an account holding the suite's password.

Each suite is loaded inside a `try`/`catch`. Without it the application error handler would render its 500 page and exit, so a suite that threw would look like a short but successful run with exit code 0 — the worst possible failure mode for a test runner. The same failure mode has a second cause, so the runner closes it too: a guard reached in-process (`redirect()`, `abort()`) only warns about headers under the CLI SAPI and then calls `exit()`, and the run used to stop there with code 0. A shutdown hook restores the borrowed accounts and, unless the summary was printed, reports the early exit as a failure with the last error it hit.

A fourth rule, and the one the reports screen taught: **assert against live data, not against an assumption about it.** `Stats::recentRecords(4)` was meant to check the row limit, but 4 is the *user id* — the limit is the second argument — so the check silently asserted that one desk held at most four records and went red the moment the office registered more by hand. The same page produced a second trap: a check that "the birth report shows its own record" passes even when the death report is on screen, because the all-events register at the foot of the page names every event. Checks that read a rendered page now scope themselves to the section they mean (`explode()` on the heading of the next card) and read CSV through `str_getcsv()`, because the writer quotes any field holding a comma.
