<?php
// -----------------------------------------------------------------------------
// Named users.
//
// Everyone signs in as themselves, and the tool records who did what. The site
// password in front of the whole domain stays where it is; this sits behind it.
//
// Until migration_017 has been run there are no users, and the app behaves
// exactly as it did before - open to whoever got past the site password. That
// way a deploy that lands before the migration does not lock anybody out.
// -----------------------------------------------------------------------------
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/central.php';

const LOGIN_TRIES  = 5;      // wrong passwords before a pause
const LOGIN_PAUSE  = 60;     // seconds to wait after that

// Where the people are. On an installation with several clients they are in the
// central database, so one account reaches every client it is allowed. On a
// single-database installation they sit in that database, as before.
function auth_db()    { return central_on() ? central_db() : db(); }
function auth_table() { return central_on() ? 'cen_users' : 'rec_users'; }

// Is there anywhere to keep people yet? Centrally that means the central tables
// exist; otherwise it means migration_017 has been run.
function users_ready()
{
    static $ok = null;
    if ($ok !== null) return $ok;
    if (central_on()) return central_ready();          // asked afresh; see central_ready()
    try { db()->query("SELECT id FROM rec_users LIMIT 1"); $ok = true; }
    catch (Throwable $e) { $ok = false; }
    return $ok;
}

function start_session()
{
    if (session_status() === PHP_SESSION_NONE) session_start();
}

// Is there anybody at all? A fresh installation asks for the first administrator
// rather than expecting someone to write a row by hand.
function any_users()
{
    if (!users_ready()) return false;
    return (int)auth_db()->query("SELECT COUNT(*) FROM " . auth_table() . " WHERE active = 1")->fetchColumn() > 0;
}

// The person signed in, or null. Read once per request.
function current_user()
{
    static $user = null;
    static $done = false;
    if ($done) return $user;
    $done = true;
    if (!users_ready()) return $user = null;
    start_session();
    $id = (int)($_SESSION['user_id'] ?? 0);
    if (!$id) return $user = null;
    $st = auth_db()->prepare("SELECT * FROM " . auth_table() . " WHERE id = ? AND active = 1");
    $st->execute([$id]);
    $user = $st->fetch() ?: null;
    if (!$user) unset($_SESSION['user_id']);          // deleted or turned off while signed in
    return $user;
}

function is_admin()
{
    $u = current_user();
    return $u && ($u['role'] === 'admin' || $u['role'] === 'owner');
}

// The owner sets up clients. Only meaningful on a central installation.
function is_owner()
{
    $u = current_user();
    return $u && $u['role'] === 'owner';
}

// Every page calls this through layout.php. It sends you to the sign-in page
// unless you are already there, or there are no users yet.
function require_login()
{
    $here = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if ($here === 'login.php') return;
    // a central installation whose tables have not been made yet: the sign-in
    // page makes them, and asks for the owner
    if (central_on() && !central_ready()) { header('Location: login.php'); exit; }
    if (!users_ready()) return;                        // the change has not been run yet
    // the change has been run but nobody exists: the first visitor makes the
    // administrator, rather than the site quietly staying open to anyone
    if (!any_users()) { header('Location: login.php'); exit; }
    $u = current_user();
    if (!$u) {
        $back = $_SERVER['REQUEST_URI'] ?? '';
        header('Location: login.php' . ($back ? '?back=' . urlencode($back) : ''));
        exit;
    }
    // a password set by an administrator has to be changed before anything else
    if (!empty($u['must_change']) && $here !== 'password.php' && $here !== 'logout.php') {
        header('Location: password.php');
        exit;
    }
    // and on an installation with several clients, one must be chosen before
    // any page can show anything - every query goes to that client's database
    if (central_on() && !current_client_id() && !in_array($here, ['clients.php', 'password.php', 'logout.php'], true)) {
        header('Location: clients.php?choose=1');
        exit;
    }
}

// Only an administrator may go further. Used by the Users page.
function require_admin()
{
    if (!users_ready()) return;
    if (!is_admin()) {
        flash('That page is for administrators only.');
        header('Location: index.php');
        exit;
    }
}

// --- signing in and out --------------------------------------------------------

// How long before another try is allowed, or 0 when there is no wait.
function login_wait()
{
    start_session();
    $tries = (int)($_SESSION['login_tries'] ?? 0);
    $last  = (int)($_SESSION['login_last'] ?? 0);
    if ($tries < LOGIN_TRIES) return 0;
    $left = LOGIN_PAUSE - (time() - $last);
    return $left > 0 ? $left : 0;
}

// [true, null] or [false, why]. Deliberately vague about which half was wrong.
function attempt_login($username, $password)
{
    start_session();
    if ($wait = login_wait()) {
        return [false, 'Too many tries. Wait ' . $wait . ' seconds and try again.'];
    }
    $st = auth_db()->prepare("SELECT * FROM " . auth_table() . " WHERE username = ? AND active = 1");
    $st->execute([trim((string)$username)]);
    $u = $st->fetch();
    if (!$u || !password_verify((string)$password, $u['password_hash'])) {
        $_SESSION['login_tries'] = (int)($_SESSION['login_tries'] ?? 0) + 1;
        $_SESSION['login_last']  = time();
        return [false, 'That username and password do not match.'];
    }
    // keep the hash up to date if PHP's default has moved on
    if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
        auth_db()->prepare("UPDATE " . auth_table() . " SET password_hash = ? WHERE id = ?")
            ->execute([password_hash((string)$password, PASSWORD_DEFAULT), $u['id']]);
    }
    session_regenerate_id(true);                       // a fresh session id on sign-in
    $_SESSION['user_id'] = (int)$u['id'];
    unset($_SESSION['login_tries'], $_SESSION['login_last']);
    auth_db()->prepare("UPDATE " . auth_table() . " SET last_login_at = NOW() WHERE id = ?")->execute([$u['id']]);
    log_event('signed in', null, null, $u);
    return [true, null];
}

function log_out()
{
    $u = current_user();
    if ($u) account_log('signed out', null, $u);
    start_session();
    $_SESSION = [];
    session_destroy();
}

// --- who did what ---------------------------------------------------------------

// One line in the log. The name is stored alongside the id, so a line still
// reads properly after somebody is removed.
//
// It never throws: a failure to write the log must not stop the work itself.
function log_event($kind, $detail = null, $recId = null, $user = null)
{
    if (!users_ready()) return;
    // before a client is chosen there is no client database to write to, so
    // signing in and out are recorded centrally
    if (central_on() && !current_client_id()) { central_log($kind, $detail, $user); return; }
    try {
        $u = $user ?? current_user();
        db()->prepare("INSERT INTO rec_events (user_id, who, rec_id, kind, detail) VALUES (?,?,?,?,?)")
            ->execute([$u['id'] ?? null, $u['name'] ?? null,
                       $recId ?? (function_exists('rec_id') ? rec_id() : null),
                       mb_substr((string)$kind, 0, 40),
                       $detail === null ? null : mb_substr((string)$detail, 0, 255)]);
    } catch (Throwable $e) {
        // nothing: the log is a record of work, not part of it
    }
}

// Anything about accounts and people rather than about a reconciliation:
// centrally when there is a central database, otherwise in the one database
// there is.
function account_log($kind, $detail = null, $user = null)
{
    central_on() ? central_log($kind, $detail, $user) : log_event($kind, $detail, null, $user);
}

// The central log: signing in and out, and anything done above a single client.
function central_log($kind, $detail = null, $user = null)
{
    if (!central_ready()) return;
    try {
        $u = $user ?? current_user();
        central_db()->prepare("INSERT INTO cen_events (user_id, who, client_id, kind, detail)
                               VALUES (?,?,?,?,?)")
            ->execute([$u['id'] ?? null, $u['name'] ?? null, current_client_id(),
                       mb_substr((string)$kind, 0, 40),
                       $detail === null ? null : mb_substr((string)$detail, 0, 255)]);
    } catch (Throwable $e) {
        // the log is a record of work, not part of it
    }
}

// "David Mitchell", for showing beside something that was done.
function user_name($id)
{
    if (!$id || !users_ready()) return '';
    static $cache = [];
    if (!array_key_exists($id, $cache)) {
        $st = auth_db()->prepare("SELECT name FROM " . auth_table() . " WHERE id = ?");
        $st->execute([(int)$id]);
        $cache[$id] = $st->fetchColumn() ?: '';
    }
    return $cache[$id];
}

// The id of whoever is signed in, for a column on a row. Null when nobody is.
function current_user_id()
{
    $u = current_user();
    return $u ? (int)$u['id'] : null;
}

// --- managing people ------------------------------------------------------------

function all_users()
{
    if (!users_ready()) return [];
    return auth_db()->query("SELECT * FROM " . auth_table() . " ORDER BY active DESC, name")->fetchAll();
}

// [true, message] or [false, why]. $role is 'admin' or 'user'.
function create_user($username, $name, $password, $role, $mustChange = 1)
{
    $username = trim((string)$username);
    $name     = trim((string)$name) ?: $username;
    if (!preg_match('/^[A-Za-z0-9._@-]{3,60}$/', $username)) {
        return [false, 'A username is 3 to 60 characters: letters, numbers, dot, dash, underscore or @.'];
    }
    if (strlen((string)$password) < 8) return [false, 'A password must be at least 8 characters.'];
    $allowed = central_on() ? ['owner', 'admin', 'user'] : ['admin', 'user'];
    $role = in_array($role, $allowed, true) ? $role : 'user';
    try {
        auth_db()->prepare("INSERT INTO " . auth_table() . " (username, name, password_hash, role, must_change)
                       VALUES (?,?,?,?,?)")
            ->execute([$username, $name, password_hash((string)$password, PASSWORD_DEFAULT),
                       $role, $mustChange ? 1 : 0]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') return [false, 'There is already someone with that username.'];
        throw $e;
    }
    account_log('added a user', $username . ' (' . $role . ')');
    return [true, $name . ' can now sign in.'];
}

function set_password($userId, $password, $mustChange = 0)
{
    if (strlen((string)$password) < 8) return [false, 'A password must be at least 8 characters.'];
    auth_db()->prepare("UPDATE " . auth_table() . " SET password_hash = ?, must_change = ? WHERE id = ?")
        ->execute([password_hash((string)$password, PASSWORD_DEFAULT), $mustChange ? 1 : 0, (int)$userId]);
    return [true, 'Password changed.'];
}

// Turning someone off rather than deleting keeps the log readable.
function set_user_active($userId, $active)
{
    $userId = (int)$userId;
    if (!$active && !other_admins_exist($userId)) {
        return [false, 'That is the only administrator, so it cannot be turned off.'];
    }
    auth_db()->prepare("UPDATE " . auth_table() . " SET active = ? WHERE id = ?")->execute([$active ? 1 : 0, $userId]);
    account_log($active ? 'turned a user on' : 'turned a user off', user_name($userId));
    return [true, 'Saved.'];
}

function set_user_role($userId, $role)
{
    $userId = (int)$userId;
    $role   = $role === 'admin' ? 'admin' : 'user';
    if ($role !== 'admin' && !other_admins_exist($userId)) {
        return [false, 'That is the only administrator, so the role cannot be changed.'];
    }
    auth_db()->prepare("UPDATE " . auth_table() . " SET role = ? WHERE id = ?")->execute([$role, $userId]);
    account_log('changed a role', user_name($userId) . ' -> ' . $role);
    return [true, 'Saved.'];
}

// Is there another active administrator besides this one? Guards against
// locking everybody out.
function other_admins_exist($exceptId)
{
    $st = auth_db()->prepare("SELECT COUNT(*) FROM " . auth_table()
                              . " WHERE role IN ('admin','owner') AND active = 1 AND id <> ?");
    $st->execute([(int)$exceptId]);
    return (int)$st->fetchColumn() > 0;
}
