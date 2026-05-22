<?php
// ── logout.php ───────────────────────────────────────────────
// Clears login session data and deletes all zafirah cookies.
// Cart is cleared in the DB on logout so it won't be restored
// on the next login (user must re-add items).
// ─────────────────────────────────────────────────────────────
require_once 'config.php';

// Clear cart from DB on logout
if (!empty($_SESSION['logged_in_user'])) {
    $logoutEmail = $_SESSION['logged_in_user'];
    saveCart($logoutEmail, []); // empties cart_items rows for this user

    // Clear all user-specific session data so it doesn't
    // leak to the next user who logs in on this browser/session
    unset($_SESSION['cart'][$logoutEmail]);
    unset($_SESSION['orders'][$logoutEmail]);
    unset($_SESSION['profile_pic'][$logoutEmail]);
}

// Clear login-related session keys
$keysToRemove = ['logged_in_user', 'role', 'login_time', 'session_start'];
foreach ($keysToRemove as $key) {
    unset($_SESSION[$key]);
}

// Delete all zafirah cookies
$cookiesToClear = ['zafirah_user', 'zafirah_role', 'zafirah_name', 'zafirah_login'];
foreach ($cookiesToClear as $name) {
    setcookie($name, '', time() - 3600, '/', '', false, true);
    unset($_COOKIE[$name]);
}

header('Location: logsign.php');
exit;