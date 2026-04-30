<?php
session_start();

require_once __DIR__ . '/../Data/mini_lib_db.php';

// ── Handle Login POST ──────────────────────────────────────
if (isset($_POST['login'])) {
    $admin_id = trim($_POST['admin_id']);
    $password = $_POST['password'];

    $stmt = $conn->prepare("SELECT * FROM admins WHERE admin_id = ?");
    $stmt->bind_param("s", $admin_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $admin  = $result->fetch_assoc();

    if ($admin && password_verify($password, $admin['password'])) {
        $_SESSION['admin_id']   = $admin['admin_id'];
        $_SESSION['admin_name'] = $admin['name'];
        $_SESSION['admin_role'] = $admin['role'];
        echo "success";
    } else {
        echo "Invalid Admin ID or password.";
    }
    exit();
}

// ── Handle Register POST ───────────────────────────────────
if (isset($_POST['register'])) {
    $admin_id   = trim($_POST['admin_id']);
    $name       = trim($_POST['name']);
    $email      = trim($_POST['email']);
    $password   = $_POST['password'];
    $role       = $_POST['role'];
    $department = trim($_POST['department']);

    $check = $conn->prepare("SELECT id FROM admins WHERE admin_id = ? OR email = ?");
    $check->bind_param("ss", $admin_id, $email);
    $check->execute();
    $check->store_result();

    if ($check->num_rows > 0) {
        echo "Admin ID or email already exists.";
        exit();
    }

    $hashed = password_hash($password, PASSWORD_DEFAULT);
    $stmt   = $conn->prepare("INSERT INTO admins (admin_id, name, email, password, role, department) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssss", $admin_id, $name, $email, $hashed, $role, $department);

    if ($stmt->execute()) {
        echo "Registration successful";
    } else {
        echo "Registration failed. Please try again.";
    }
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Admin Portal</title>
  <link rel="stylesheet" href="../style.css"/>
</head>
<body class="auth-body">

<!-- LOGIN SCREEN -->
<div class="auth-screen" id="screen-login">
  <div class="auth-card">
    <div class="auth-card-header">
      <h1>Admin Portal</h1>
      <p>Library Management System</p>
    </div>
    <div class="auth-field">
      <label for="l-id">Admin ID</label>
      <input type="text" id="l-id" placeholder="Enter your admin ID" autocomplete="username"/>
    </div>
    <div class="auth-field">
      <label for="l-pw">Password</label>
      <div class="pw-wrap">
        <input type="password" id="l-pw" placeholder="Enter your password" autocomplete="current-password"/>
        <button class="eye-btn" type="button" onclick="togglePw('l-pw', this)">
          <svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
        </button>
      </div>
      <div class="auth-err" id="err-login"></div>
    </div>
    <div class="remember-row">
      <input type="checkbox" id="remember"/>
      <label for="remember">Remember my Admin ID</label>
    </div>
    <button class="auth-btn-primary" onclick="handleLogin()">Login</button>
    <button class="auth-btn-secondary" onclick="showScreen('register')">Create New Account</button>
    <a href="#" class="auth-forgot" onclick="forgotPw(event)">Forgot your password?</a>
  </div>
</div>

<!-- REGISTER SCREEN -->
<div class="auth-screen" id="screen-register" style="display:none;">
  <div class="auth-card">
    <div class="auth-card-header">
      <h1>Create Account</h1>
      <p>Register as a new administrator</p>
    </div>
    <div class="auth-field">
      <label for="r-id">Admin ID</label>
      <input type="text" id="r-id" placeholder="Enter admin ID"/>
      <div class="auth-err" id="err-r-id">Admin ID is required.</div>
    </div>
    <div class="auth-field">
      <label for="r-name">Full Name</label>
      <input type="text" id="r-name" placeholder="Enter full name"/>
      <div class="auth-err" id="err-r-name">Full name is required.</div>
    </div>
    <div class="auth-field">
      <label for="r-pw">Password</label>
      <div class="pw-wrap">
        <input type="password" id="r-pw" placeholder="Min. 6 characters" autocomplete="new-password"/>
        <button class="eye-btn" type="button" onclick="togglePw('r-pw', this)">
          <svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
        </button>
      </div>
      <div class="auth-err" id="err-r-pw">Password must be at least 6 characters.</div>
    </div>
    <div class="auth-field">
      <label for="r-role">Admin Role</label>
      <select id="r-role">
        <option value="">Select role</option>
        <option value="super_admin">Super Administrator</option>
        <option value="librarian">Librarian</option>
        <option value="assistant">Library Assistant</option>
        <option value="cataloger">Cataloger</option>
      </select>
      <div class="auth-err" id="err-r-role">Please select a role.</div>
    </div>
    <div class="auth-field">
      <label for="r-dept">Department</label>
      <input type="text" id="r-dept" placeholder="e.g., Circulation"/>
    </div>
    <button class="auth-btn-primary" onclick="handleRegister()">Register Account</button>
    <button class="auth-btn-secondary" onclick="showScreen('login')">Cancel</button>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>
  function showScreen(name) {
    document.getElementById('screen-login').style.display    = name === 'login'    ? '' : 'none';
    document.getElementById('screen-register').style.display = name === 'register' ? '' : 'none';
    clearErrors();
  }

  function togglePw(id, btn) {
    const inp = document.getElementById(id);
    const isHidden = inp.type === 'password';
    inp.type = isHidden ? 'text' : 'password';
    btn.querySelector('svg').innerHTML = isHidden
      ? '<line x1="1" y1="1" x2="23" y2="23"/><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/>'
      : '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
  }

  function showToast(msg, type = '') {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'toast show ' + type;
    setTimeout(() => { t.className = 'toast'; }, 3200);
  }

  function clearErrors() {
    document.querySelectorAll('.auth-err').forEach(e => { e.style.display = 'none'; });
    document.querySelectorAll('.auth-field input, .auth-field select').forEach(el => el.classList.remove('input-error'));
  }

  function setErr(inputId, errId) {
    const el  = document.getElementById(inputId);
    const err = document.getElementById(errId);
    if (el)  el.classList.add('input-error');
    if (err) err.style.display = 'block';
  }

  function handleLogin() {
    clearErrors();
    const id = document.getElementById('l-id').value.trim();
    const pw = document.getElementById('l-pw').value;
    if (!id || !pw) {
      const err = document.getElementById('err-login');
      err.textContent = 'Please fill in all fields.';
      err.style.display = 'block';
      return;
    }
    if (document.getElementById('remember').checked) {
      localStorage.setItem('libraryAdminId', id);
    } else {
      localStorage.removeItem('libraryAdminId');
    }
    fetch('auth.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: `login=1&admin_id=${encodeURIComponent(id)}&password=${encodeURIComponent(pw)}`
    })
    .then(res => res.text())
    .then(data => {
      if (data.includes('success')) {
        showToast('Login successful!', 'success');
        setTimeout(() => { window.location.href = '../Library/dashboard.php'; }, 1000);
      } else {
        const err = document.getElementById('err-login');
        err.textContent = data.trim();
        err.style.display = 'block';
        document.getElementById('l-pw').classList.add('input-error');
      }
    })
    .catch(() => showToast('Server error. Please try again.', 'error'));
  }

  function handleRegister() {
    clearErrors();
    const id    = document.getElementById('r-id').value.trim();
    const name  = document.getElementById('r-name').value.trim();
    const pw    = document.getElementById('r-pw').value;
    const role  = document.getElementById('r-role').value;
    const dept  = document.getElementById('r-dept').value.trim();
    const email = id.toLowerCase().replace(/\s+/g, '') + '@library.admin';

    let valid = true;
    if (!id)           { setErr('r-id',   'err-r-id');   valid = false; }
    if (!name)         { setErr('r-name', 'err-r-name'); valid = false; }
    if (pw.length < 6) { setErr('r-pw',   'err-r-pw');   valid = false; }
    if (!role)         { setErr('r-role', 'err-r-role'); valid = false; }
    if (!valid) return;

    fetch('auth.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: `register=1&admin_id=${encodeURIComponent(id)}&name=${encodeURIComponent(name)}&email=${encodeURIComponent(email)}&password=${encodeURIComponent(pw)}&role=${encodeURIComponent(role)}&department=${encodeURIComponent(dept)}`
    })
    .then(res => res.text())
    .then(data => {
      if (data.includes('successful')) {
        showToast('Registered successfully!', 'success');
        setTimeout(() => showScreen('login'), 1400);
      } else {
        showToast(data.trim(), 'error');
      }
    })
    .catch(() => showToast('Server error. Please try again.', 'error'));
  }

  function forgotPw(e) {
    e.preventDefault();
    showToast('Please contact your system administrator.');
  }

  const saved = localStorage.getItem('libraryAdminId');
  if (saved) {
    document.getElementById('l-id').value = saved;
    document.getElementById('remember').checked = true;
  }
</script>
</body>
</html>