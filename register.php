<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Show form
    $branches = $pdo->query("SELECT BranchID, BranchName FROM branch")->fetchAll();
    ?>
    <!DOCTYPE html><html><head><meta charset="UTF-8"><title>Register — Nexus Bank</title>
    <style>
    body{font-family:'Segoe UI',Arial;background:#f4f6fa;padding:40px;}
    .box{max-width:480px;margin:auto;background:#fff;padding:30px;border-radius:10px;box-shadow:0 2px 12px rgba(10,31,68,.08);}
    h2{color:#0a1f44;margin-bottom:20px;}
    label{display:block;margin-bottom:6px;font-weight:600;color:#0a1f44;font-size:14px;}
    input,select{width:100%;padding:10px;border:1px solid #cfd6e4;border-radius:6px;margin-bottom:14px;font-size:14px;}
    button{background:#0a1f44;color:#fff;padding:11px 20px;border:none;border-radius:6px;font-weight:600;cursor:pointer;width:100%;font-size:15px;}
    .back{text-align:center;margin-top:16px;font-size:13px;}
    .back a{color:#4a90e2;text-decoration:none;}
    </style></head><body>
    <div class="box">
      <h2>Open a New Account</h2>
      <form method="post">
        <label>Full Name</label><input type="text" name="name" required>
        <label>Email</label><input type="email" name="email" required>
        <label>Address</label><input type="text" name="address" required>
        <label>Date of Birth</label><input type="date" name="dob" required>
        <label>Password</label><input type="password" name="password" required>
        <label>Initial Deposit (LKR)</label><input type="number" name="initial_deposit" step="0.01" min="0" required>
        <label>Account Type</label>
        <select name="account_type">
          <option>Savings</option><option>Current</option>
        </select>
        <label>Branch</label>
        <select name="branch_id" required>
          <?php foreach ($branches as $b): ?>
            <option value="<?= htmlspecialchars($b['BranchID']) ?>"><?= htmlspecialchars($b['BranchName']) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit">Submit Application</button>
      </form>
      <div class="back"><a href="<?= CUSTOMER_URL ?>">← Back to login</a></div>
    </div>
    </body></html>
    <?php
    exit;
}

// POST — save application
$name     = trim($_POST['name'] ?? '');
$email    = trim($_POST['email'] ?? '');
$address  = trim($_POST['address'] ?? '');
$dob      = $_POST['dob'] ?? null;
$password = $_POST['password'] ?? '';
$initial  = floatval($_POST['initial_deposit'] ?? 0);
$type     = $_POST['account_type'] ?? 'Savings';
$branch   = $_POST['branch_id'] ?? '';

if (!$name || !$email || !$address || !$password || !$branch) {
    set_flash('error', 'All fields are required.');
    redirect(CUSTOMER_URL . 'register.php');
}

// check if email already used
$stmt = $pdo->prepare("SELECT 1 FROM customer WHERE Email = ?");
$stmt->execute([$email]);
if ($stmt->fetch()) {
    set_flash('error', 'This email is already registered.');
    redirect(CUSTOMER_URL . 'register.php');
}

$stmt = $pdo->prepare("
    INSERT INTO pending_account_application
    (Name, Email, Address, DOB, Password, InitialDeposit, AccountType, BranchID)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
");
$stmt->execute([$name, $email, $address, $dob, $password, $initial, $type, $branch]);

set_flash('success', 'Application submitted. Please wait for cashier approval.');
redirect(CUSTOMER_URL);