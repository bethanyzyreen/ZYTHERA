<?php
require 'config.php';

$message = '';
$msgType = '';

// ── Cookie-based auto-restore ─────────────────────────────────
if (empty($_SESSION['logged_in_user']) && !empty($_COOKIE['zafirah_user'])) {
    $cEmail = $_COOKIE['zafirah_user'];
    $cRole  = $_COOKIE['zafirah_role'] ?? '';
    if (isset($_SESSION['users'][$cEmail]) && $_SESSION['users'][$cEmail]['role'] === $cRole) {
        $_SESSION['logged_in_user'] = $cEmail;
        $_SESSION['role']           = $cRole;
        $_SESSION['login_time']     = $_COOKIE['zafirah_login'] ?? date('h:i A');
        $_SESSION['session_start']  = time(); // restore so cookie-expiry guard works
        // Restore cart from DB
        $_SESSION['cart'][$cEmail] = loadCart($cEmail);
        if (!isset($_SESSION['profile_pic'][$cEmail])) {
            $_SESSION['profile_pic'][$cEmail] = $_SESSION['users'][$cEmail]['profile_pic'] ?? null;
        }
    }
}

if (isset($_GET['expired'])) {
    $message = "We couldn't find your account session — please log in again.";
    $msgType  = 'error';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email']    ?? '');
    $password = trim($_POST['password'] ?? '');

    $adminEmails = ['zafirah@gmail.com', 'admin@gmail.com'];

    // ── SIGN UP ──────────────────────────────────────────────
    if (isset($_POST['signup'])) {
        $name = trim($_POST['name'] ?? '');
        $role = in_array($email, $adminEmails, true) ? 'admin' : 'user';

        if (!$email || !$password || !$name) {
            $message = 'Please complete all fields.';
            $msgType  = 'error';
        } elseif (strlen($password) < 6) {
            $message = 'Password must be at least 6 characters.';
            $msgType  = 'error';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = 'Please enter a valid email address.';
            $msgType  = 'error';
        } elseif (isset($_SESSION['users'][$email])) {
            $message = 'Email already registered!';
            $msgType  = 'error';
        } else {
            saveUser($email, [
                'name'        => htmlspecialchars($name),
                'password'    => password_hash($password, PASSWORD_DEFAULT),
                'role'        => $role,
                'profile_pic' => null,
            ]);
            $_SESSION['cart'][$email]        = [];
            $_SESSION['profile_pic'][$email] = null;
            $message = 'Account created! You can now log in.';
            $msgType  = 'success';
        }

    // ── LOGIN ────────────────────────────────────────────────
    } elseif (isset($_POST['login'])) {
        $userExists = isset($_SESSION['users'][$email]);
        $passOk     = $userExists && password_verify($password, $_SESSION['users'][$email]['password']);

        if ($userExists && $passOk) {
            $_SESSION['logged_in_user'] = $email;
            $_SESSION['role']           = $_SESSION['users'][$email]['role'];
            $_SESSION['login_time']     = date('h:i A');
            $_SESSION['session_start']  = time();

            $exp = time() + 60;
            setcookie('zafirah_user',  $email,                             $exp, '/', '', false, true);
            setcookie('zafirah_role',  $_SESSION['users'][$email]['role'], $exp, '/', '', false, true);
            setcookie('zafirah_name',  $_SESSION['users'][$email]['name'], $exp, '/', '', false, true);
            setcookie('zafirah_login', date('h:i A'),                      $exp, '/', '', false, true);

            // Restore cart and profile pic from DB
            $_SESSION['cart'][$email] = loadCart($email);
            if (!isset($_SESSION['profile_pic'][$email])) {
                $_SESSION['profile_pic'][$email] = $_SESSION['users'][$email]['profile_pic'] ?? null;
            }

            header('Location: ' . (in_array($email, $adminEmails, true) ? 'admin.php' : 'website.php'));
            exit;
        } else {
            $message = 'Invalid email or password.';
            $msgType  = 'error';
        }
    }
}

if (!empty($_SESSION['logged_in_user'])) {
    $adminEmails2 = ['zafirah@gmail.com', 'admin@gmail.com'];
    header('Location: ' . (in_array($_SESSION['logged_in_user'], $adminEmails2, true) ? 'admin.php' : 'website.php'));
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ZAFIRAH</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,700;1,700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --cream:#f5f2ec;--sage:#d4e4d4;--mid:#7aab7a;
  --green:#2d5a2d;--deep:#1a2e1a;--white:#fff;
  --red:#dc2626;--radius:16px;
}
body{
  min-height:100vh;
  background:linear-gradient(135deg,#c8dcc8 0%,#f5f2ec 60%,#e8d8c8 100%);
  font-family:'DM Sans',sans-serif;
  display:flex;flex-direction:column;
  align-items:center;justify-content:center;
  padding:80px 16px 32px;
}
.top-bar{
  position:fixed;top:0;left:0;right:0;
  display:flex;justify-content:space-between;align-items:center;
  padding:12px 24px;
  background:rgba(255,255,255,.75);
  backdrop-filter:blur(12px);
  border-bottom:1px solid rgba(0,0,0,.06);
  z-index:100;
}
.top-btn{
  display:flex;align-items:center;gap:7px;
  text-decoration:none;font-size:.85rem;font-weight:500;
  color:var(--green);cursor:pointer;border:none;background:none;
  padding:7px 14px;border-radius:30px;transition:.2s;
}
.top-btn:hover{background:var(--sage);}
.session-bar{position:fixed;top:49px;left:0;right:0;height:3px;background:var(--sage);z-index:99;}
.session-bar-fill{height:100%;background:var(--green);transition:width 1s linear;}
.card{
  width:100%;max-width:460px;
  background:rgba(255,255,255,.92);
  backdrop-filter:blur(20px);
  border-radius:28px;
  box-shadow:0 16px 48px rgba(45,90,45,.15);
  padding:40px 40px 36px;
}
@media(max-width:500px){.card{padding:28px 20px 24px;}}
.brand{font-family:'Playfair Display',serif;color:var(--green);font-size:2.2rem;text-align:center;letter-spacing:3px;margin-bottom:4px;}
.tagline{text-align:center;color:#888;font-size:.82rem;margin-bottom:28px;}
.tabs{display:flex;background:var(--sage);border-radius:50px;padding:4px;margin-bottom:28px;}
.tabs button{flex:1;padding:11px;border:none;border-radius:50px;background:transparent;font-family:'DM Sans',sans-serif;font-size:.9rem;font-weight:600;color:var(--green);cursor:pointer;transition:.25s;}
.tabs button.active{background:var(--green);color:#fff;box-shadow:0 3px 12px rgba(45,90,45,.3);}
.form{display:none;}
.form.active{display:block;}
.field{position:relative;margin-bottom:18px;}
.field input{width:100%;padding:15px 14px 7px;background:var(--sage);border:2px solid transparent;border-radius:var(--radius);outline:none;font-family:'DM Sans',sans-serif;font-size:.95rem;color:var(--deep);transition:.2s;}
.field input:focus{border-color:var(--green);background:#fff;}
.field label{position:absolute;left:14px;top:14px;font-size:.85rem;color:#999;pointer-events:none;transition:.2s;}
.field input:focus~label,.field input:not(:placeholder-shown)~label{top:4px;font-size:.68rem;color:var(--green);font-weight:600;}
.btn-submit{width:100%;padding:14px;border:none;border-radius:50px;font-family:'DM Sans',sans-serif;font-size:1rem;font-weight:700;cursor:pointer;transition:.25s;margin-top:4px;letter-spacing:.5px;}
.btn-submit.user{background:var(--green);color:#fff;}
.btn-submit:hover{opacity:.88;transform:translateY(-1px);}
.toast{position:fixed;top:72px;right:20px;padding:14px 20px;border-radius:14px;color:#fff;font-size:.86rem;font-weight:500;z-index:9999;opacity:0;transform:translateY(-10px);transition:.3s;pointer-events:none;max-width:300px;}
.toast.show{opacity:1;transform:translateY(0);}
.toast.success{background:#16a34a;}
.toast.error{background:var(--red);}
.footer-brand{display:flex;align-items:center;justify-content:center;gap:10px;margin-top:24px;font-family:'Playfair Display',serif;color:var(--deep);font-size:.95rem;font-weight:700;letter-spacing:3px;opacity:.75;}
.footer-logo{width:28px;height:28px;object-fit:contain;}
body.dark{background:linear-gradient(135deg,#2d5a2d 0%,#3d7a3d 50%,#2a552a 100%);}
body.dark .top-bar{background:rgba(45,90,45,.85);border-bottom:1px solid rgba(255,255,255,.1);}
body.dark .top-btn{color:#e8f5e8;}
body.dark .top-btn:hover{background:rgba(255,255,255,.15);}
body.dark .card{background:rgba(255,255,255,.12);backdrop-filter:blur(24px);box-shadow:0 16px 48px rgba(0,0,0,.25);border:1px solid rgba(255,255,255,.2);}
body.dark .brand{color:#fff;}
body.dark .tagline{color:rgba(255,255,255,.7);}
body.dark .tabs{background:rgba(0,0,0,.2);border:1px solid rgba(255,255,255,.15);}
body.dark .tabs button{color:rgba(255,255,255,.8);}
body.dark .tabs button.active{background:var(--deep);color:#fff;}
body.dark .field input{background:rgba(255,255,255,.15);color:#fff;border:2px solid rgba(255,255,255,.2);}
body.dark .field input:focus{background:rgba(255,255,255,.22);border-color:rgba(255,255,255,.5);}
body.dark .field label{color:rgba(255,255,255,.6);}
body.dark .field input:focus~label,.body.dark .field input:not(:placeholder-shown)~label{color:#d4e4d4;}
body.dark .footer-brand{color:rgba(255,255,255,.85);opacity:1;}
body.dark .footer-logo{filter:brightness(0) invert(1);}
body.dark .btn-submit.user{background:var(--deep);color:#fff;}
</style>
</head>
<body>

<div class="top-bar">
  <a href="website.php" class="top-btn">Back to Home</a>
  <button class="top-btn" onclick="document.body.classList.toggle('dark')">Dark Mode</button>
</div>

<div class="session-bar"><div class="session-bar-fill" id="sessionBarFill" style="width:100%;"></div></div>
<div id="toast" class="toast"></div>

<?php if ($message): ?>
<script>document.addEventListener('DOMContentLoaded',()=>showToast(<?= json_encode($message) ?>,<?= json_encode($msgType) ?>));</script>
<?php endif; ?>

<div class="card">
  <div class="brand">ZAFIRAH</div>
  <p class="tagline">Furniture crafted for lives that deserve beauty.</p>

  <div class="tabs">
    <button id="loginTab"  class="active" onclick="switchTab('login')">Login</button>
    <button id="signupTab"               onclick="switchTab('signup')">Sign Up</button>
  </div>

  <form id="loginForm" class="form active" method="POST" novalidate>
    <div class="field">
      <input type="email" name="email" placeholder=" " required autocomplete="email">
      <label>Email Address</label>
    </div>
    <div class="field">
      <input type="password" name="password" placeholder=" " required autocomplete="current-password">
      <label>Password</label>
    </div>
    <button type="submit" name="login" class="btn-submit user">Login</button>
  </form>

  <form id="signupForm" class="form" method="POST" novalidate>
    <div class="field">
      <input type="text" name="name" placeholder=" " required autocomplete="name">
      <label>Full Name</label>
    </div>
    <div class="field">
      <input type="email" name="email" placeholder=" " required autocomplete="email">
      <label>Email Address</label>
    </div>
    <div class="field">
      <input type="password" name="password" placeholder=" " required autocomplete="new-password" minlength="6">
      <label>Password (min 6 chars)</label>
    </div>
    <button type="submit" name="signup" class="btn-submit user">Create Account</button>
  </form>

  <div class="footer-brand">
    <img src="/php_work/e-commerce/pci/Group_15.svg" class="footer-logo">
    <span>ZAFIRAH</span>
  </div>
</div>

<script>
function switchTab(tab) {
  document.getElementById('loginTab').classList.toggle('active',  tab==='login');
  document.getElementById('signupTab').classList.toggle('active', tab==='signup');
  document.getElementById('loginForm').classList.toggle('active', tab==='login');
  document.getElementById('signupForm').classList.toggle('active',tab==='signup');
}

function showToast(msg, type='success') {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.className   = 'toast ' + type + ' show';
  setTimeout(()=>t.classList.remove('show'), 5000);
}

(function() {
  const COOKIE_LIFETIME = 60;
  const fill = document.getElementById('sessionBarFill');
  if (!fill) return;
  function getCookie(name) {
    const match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
    return match ? decodeURIComponent(match[2]) : null;
  }
  if (!getCookie('zafirah_user')) { fill.style.width = '0%'; return; }
  let elapsed = 0;
  const interval = setInterval(() => {
    elapsed++;
    const pct = Math.max(0, ((COOKIE_LIFETIME - elapsed) / COOKIE_LIFETIME) * 100);
    fill.style.width = pct + '%';
    fill.style.background = pct > 40 ? '#2d5a2d' : pct > 15 ? '#f59e0b' : '#dc2626';
    if (elapsed >= COOKIE_LIFETIME) {
      clearInterval(interval);
      showToast("Session expired. Please log in again.", 'error');
    }
  }, 1000);
})();
</script>
</body>
</html>