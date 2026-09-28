<?php
require_once dirname(__DIR__) . '/config.php';
enforce_portal_role();

$tab = $_GET['tab'] ?? 'accounts';

// ── 1. Accounts with customer info ──
$allAccounts = $pdo->query("
    SELECT a.AccountNumber, a.Balance, a.OpenedDate, a.BranchID,
           c.CustomerID, c.Name AS Owner, c.Email, c.Address, c.DOB,
           b.BranchName
    FROM account a
    JOIN customer c ON c.CustomerID = a.CustomerID
    JOIN branch   b ON b.BranchID   = a.BranchID
    ORDER BY a.AccountNumber
")->fetchAll();

// ── 1b. Fetch ALL contacts per customer (multivalued attribute) ──
$contactsByCustomer = [];
$rows = $pdo->query("SELECT CustomerID, Contact FROM customer_contact ORDER BY CustomerID, Contact")->fetchAll();
foreach ($rows as $r) {
    $contactsByCustomer[$r['CustomerID']][] = $r['Contact'];
}

// ── 2. Pending loans ──
$pendingApps = $pdo->query("
    SELECT p.*, c.Name AS CustomerName, c.Email, c.CustomerID, b.BranchName,
           IFNULL((SELECT SUM(Balance) FROM account WHERE CustomerID = c.CustomerID), 0) AS CustomerBalance
    FROM pending_loan_application p
    JOIN customer c ON c.CustomerID = p.CustomerID
    JOIN branch   b ON b.BranchID   = p.BranchID
    ORDER BY p.AppliedDate ASC
")->fetchAll();
$pendingCount = count($pendingApps);

// ── 3. Approved loans WITH FILTERS ──
$f_customer    = trim($_GET['f_customer']   ?? '');
$f_loanid      = trim($_GET['f_loanid']     ?? '');
$f_type        = $_GET['f_type']            ?? '';
$f_date_from   = $_GET['f_date_from']       ?? '';
$f_date_to     = $_GET['f_date_to']         ?? '';
$f_amount_min  = $_GET['f_amount_min']      ?? '';
$f_amount_max  = $_GET['f_amount_max']      ?? '';

$where  = ["lp.Approval = 1"];
$params = [];

if ($f_customer !== '') {
    $where[]  = "c.Name LIKE ?";
    $params[] = '%' . $f_customer . '%';
}
if ($f_loanid !== '') {
    $idNum    = intval(preg_replace('/[^0-9]/', '', $f_loanid));
    $where[]  = "l.LoanID = ?";
    $params[] = $idNum;
}
if ($f_type !== '') {
    $where[]  = "l.LoanType = ?";
    $params[] = $f_type;
}
if ($f_date_from !== '') {
    $where[]  = "DATE(l.IssueDate) >= ?";
    $params[] = $f_date_from;
}
if ($f_date_to !== '') {
    $where[]  = "DATE(l.IssueDate) <= ?";
    $params[] = $f_date_to;
}
if ($f_amount_min !== '' && is_numeric($f_amount_min)) {
    $where[]  = "l.Amount >= ?";
    $params[] = floatval($f_amount_min);
}
if ($f_amount_max !== '' && is_numeric($f_amount_max)) {
    $where[]  = "l.Amount <= ?";
    $params[] = floatval($f_amount_max);
}

$whereSql = implode(' AND ', $where);

$stmt = $pdo->prepare("
    SELECT l.LoanID, l.LoanType, l.Amount, l.LoanRate, l.IssueDate,
           c.Name AS Customer, c.CustomerID,
           lp.ApprovedDate,
           e.Name AS ManagerName
    FROM loan l
    JOIN loan_process lp ON lp.LoanID = l.LoanID
    JOIN customer c ON c.CustomerID = lp.CustomerID
    LEFT JOIN manager m  ON m.EmployeeID = l.ManagerID
    LEFT JOIN employee e ON e.EmployeeID = m.EmployeeID
    WHERE $whereSql
    ORDER BY l.IssueDate DESC
");
$stmt->execute($params);
$approvedLoans = $stmt->fetchAll();

$loanTypes = $pdo->query("SELECT DISTINCT LoanType FROM loan ORDER BY LoanType")->fetchAll(PDO::FETCH_COLUMN);

$totalFilteredCount  = count($approvedLoans);
$totalFilteredAmount = array_sum(array_column($approvedLoans, 'Amount'));

$hasFilters = ($f_customer !== '' || $f_loanid !== '' || $f_type !== ''
               || $f_date_from !== '' || $f_date_to !== ''
               || $f_amount_min !== '' || $f_amount_max !== '');

// ── 4. Cashiers (Employee JOIN cashier) ──
$cashiers = $pdo->query("
    SELECT e.EmployeeID, e.Name, e.Email, e.Salary, e.WorkingHours,
           e.YearsExperience, e.BranchID, b.BranchName
    FROM employee e
    JOIN cashier c ON c.EmployeeID = e.EmployeeID
    LEFT JOIN branch b ON b.BranchID = e.BranchID
    ORDER BY e.EmployeeID
")->fetchAll();

// ── 5. Branches with managers ──
$branches = $pdo->query("
    SELECT b.BranchID, b.BranchName, b.Address, b.Phone,
           em.EmployeeID AS ManagerID, em.Name AS ManagerName, em.Email AS ManagerEmail
    FROM branch b
    LEFT JOIN manager m   ON m.EmployeeID = b.ManagerID
    LEFT JOIN employee em ON em.EmployeeID = m.EmployeeID
    ORDER BY b.BranchID
")->fetchAll();

// ── 6. Transactions ──
$allTransactions = $pdo->query("
    SELECT t.TransactionID, t.Date, t.Amount, t.Type, t.AccountNumber, t.Description,
           c.Name AS CustomerName, e.Name AS CashierName
    FROM `transaction` t
    LEFT JOIN customer c ON c.CustomerID = t.CustomerID
    LEFT JOIN employee e ON e.EmployeeID = t.CashierID
    ORDER BY t.Date DESC LIMIT 500
")->fetchAll();

$txnCount  = count($allTransactions);
$txnSumDep = 0; $txnSumWit = 0;
foreach ($allTransactions as $t) {
    if ($t['Type'] === 'Deposit')    $txnSumDep += $t['Amount'];
    if ($t['Type'] === 'Withdrawal') $txnSumWit += $t['Amount'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><title>Nexus Bank — Manager</title>
<style>
:root{--navy:#0a1f44;--accent:#4a90e2;--white:#fff;--grey:#f4f6fa;--danger:#d9534f;--success:#28a745;}
*{box-sizing:border-box;margin:0;padding:0;font-family:'Segoe UI',Arial,sans-serif;}
body{background:var(--grey);min-height:100vh;}
.topbar{background:var(--navy);color:#fff;padding:14px 30px;display:flex;justify-content:space-between;align-items:center;}
.topbar .brand{font-size:22px;font-weight:700;letter-spacing:1px;}
.topbar .brand span{color:var(--accent);}
.topbar .userinfo{font-size:14px;}
.topbar .userinfo a{color:#ffd27f;text-decoration:none;margin-left:14px;}
.container{max-width:1300px;margin:30px auto;padding:0 20px;}
.card{background:#fff;border-radius:10px;padding:25px 30px;box-shadow:0 2px 12px rgba(10,31,68,.08);margin-bottom:24px;}
.card h2{color:var(--navy);margin-bottom:16px;font-size:20px;border-bottom:2px solid var(--grey);padding-bottom:10px;}
.btn{display:inline-block;background:var(--navy);color:#fff;padding:11px 20px;border:none;border-radius:6px;font-size:15px;font-weight:600;cursor:pointer;text-decoration:none;}
.btn.full{width:100%;}
.btn.accent{background:var(--accent);}
.btn.success{background:var(--success);}
.btn.danger{background:var(--danger);}
.btn.small{padding:6px 12px;font-size:13px;}
.btn.gray{background:#777;}
.alert{padding:12px 16px;border-radius:6px;margin-bottom:16px;font-size:14px;}
.alert-success{background:#e6f7ea;color:#1e7a34;border:1px solid #b8e6c2;}
.alert-error{background:#fdecec;color:#a02525;border:1px solid #f5c2c2;}
table{width:100%;border-collapse:collapse;margin-top:10px;font-size:14px;}
th,td{text-align:left;padding:10px 12px;border-bottom:1px solid #e4e8f0;vertical-align:top;}
th{background:var(--navy);color:#fff;font-weight:600;}
tr:hover td{background:#f8faff;}
.tabs{display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;}
.tabs a{padding:8px 16px;border-radius:6px;text-decoration:none;font-size:14px;font-weight:600;background:#e6ecf7;color:var(--navy);}
.tabs a.active,.tabs a:hover{background:var(--navy);color:#fff;}
.dot{display:inline-block;width:10px;height:10px;background:red;border-radius:50%;margin-left:8px;animation:pulse 1.2s infinite;}
@keyframes pulse{0%{opacity:1;}50%{opacity:.4;}100%{opacity:1;}}
.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:15px;margin-bottom:20px;}
.stat{background:#f8faff;border-left:4px solid var(--accent);padding:15px;border-radius:6px;}
.stat .label{font-size:12px;text-transform:uppercase;letter-spacing:1px;color:#777;margin-bottom:6px;}
.stat .value{font-size:20px;font-weight:700;color:var(--navy);}
.stat.dep .value{color:var(--success);} .stat.wit .value{color:var(--danger);}
.credit{color:var(--success);font-weight:600;} .debit{color:var(--danger);font-weight:600;}
.search-bar{margin-bottom:12px;}
.search-bar input{width:100%;padding:11px;border:1px solid #cfd6e4;border-radius:6px;font-size:14px;}
.search-bar input:focus{outline:none;border-color:var(--accent);}
.login-wrap{max-width:420px;margin:70px auto;}
.login-wrap .logo{text-align:center;color:var(--navy);font-size:30px;font-weight:800;margin-bottom:6px;}
.login-wrap .logo span{color:var(--accent);}
.login-wrap .tagline{text-align:center;color:#777;margin-bottom:24px;font-size:14px;}
.form-group{margin-bottom:16px;}
.form-group label{display:block;margin-bottom:6px;font-weight:600;color:var(--navy);font-size:14px;}
.form-group input,.form-group select{width:100%;padding:11px 12px;border:1px solid #cfd6e4;border-radius:6px;font-size:14px;}

/* Modal */
.modal-overlay{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(10,31,68,0.7);z-index:1000;overflow-y:auto;}
.modal-box{background:#fff;max-width:650px;margin:40px auto;border-radius:10px;box-shadow:0 10px 30px rgba(0,0,0,0.3);}
.modal-header{background:var(--navy);color:#fff;padding:18px 25px;border-radius:10px 10px 0 0;display:flex;justify-content:space-between;align-items:center;}
.modal-header h3{font-size:18px;margin:0;}
.modal-close{background:none;border:none;color:#fff;font-size:24px;cursor:pointer;}
.modal-body{padding:25px;}
.modal-footer{padding:18px 25px;border-top:1px solid #e4e8f0;display:flex;justify-content:flex-end;gap:12px;}
.readonly{background:#f4f6fa;color:#777;cursor:not-allowed;}
.badge{display:inline-block;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:600;}
.badge-branch{background:#e6ecf7;color:var(--navy);}
.badge-approved{background:#d4edda;color:#1e7a34;}
.badge-rejected{background:#f8d7da;color:#a02525;}

/* Multivalued phone rows */
.phone-row{display:flex;gap:8px;margin-bottom:8px;}
.phone-row input{flex:1;}
.phone-row button{background:var(--danger);color:#fff;border:none;border-radius:6px;padding:0 14px;cursor:pointer;font-size:18px;line-height:1;}
.phone-list{margin:0;padding-left:0;list-style:none;}
.phone-list li{padding:2px 0;font-size:13px;color:#555;}

/* ★ Loan filter panel */
.filter-panel{background:#f8faff;border:1px solid #cfd6e4;border-radius:8px;padding:18px;margin-bottom:18px;}
.filter-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;align-items:end;}
.filter-field{display:flex;flex-direction:column;}
.filter-field label{font-size:12px;font-weight:600;color:var(--navy);margin-bottom:5px;text-transform:uppercase;letter-spacing:.5px;}
.filter-field input,.filter-field select{padding:9px 11px;border:1px solid #cfd6e4;border-radius:6px;font-size:14px;background:#fff;width:100%;box-sizing:border-box;}
.filter-field input:focus,.filter-field select:focus{outline:none;border-color:var(--accent);}
.filter-actions{flex-direction:row;gap:8px;align-items:flex-end;}
.filter-actions .btn{padding:9px 16px;white-space:nowrap;}
.filter-summary{background:#eef4ff;border-left:4px solid var(--accent);padding:12px 16px;border-radius:6px;font-size:13px;color:#333;margin-bottom:14px;}
@media(max-width:900px){.filter-grid{grid-template-columns:repeat(2,1fr);}}
@media(max-width:600px){.filter-grid{grid-template-columns:1fr;}}
</style>
</head>
<body>

<?php if (is_logged_in()): ?>
<div class="topbar">
  <div class="brand">NEXUS<span>BANK</span> <span style="font-size:14px;color:#ffd27f;">— Management</span></div>
  <div class="userinfo">
    <?= htmlspecialchars(current_user()['name']) ?> | <strong>Manager</strong>
    <a href="<?= MANAGER_URL ?>logout.php">Logout</a>
  </div>
</div>
<?php endif; ?>

<div class="container">
<?php show_flash(); ?>

<?php if (!is_logged_in() || role() !== 'manager'): ?>
  <div class="login-wrap">
    <div class="logo">NEXUS<span>BANK</span></div>
    <div class="tagline">Management Portal</div>
    <div class="card">
      <h2>Manager Sign in</h2>
      <form method="post" action="<?= MANAGER_URL ?>login.php">
        <div class="form-group"><label>Email</label><input type="email" name="email" required></div>
        <div class="form-group"><label>Password</label><input type="password" name="password" required></div>
        <button class="btn full" type="submit">Sign In</button>
      </form>
    </div>
  </div>

<?php else: ?>
  <div class="tabs">
    <a href="?tab=accounts"     class="<?= $tab==='accounts'?'active':'' ?>">Accounts</a>
    <a href="?tab=loans"        class="<?= $tab==='loans'?'active':'' ?>">
      Loan Approvals <?php if ($pendingCount>0): ?><span class="dot"></span><?php endif; ?>
    </a>
    <a href="?tab=cashiers"     class="<?= $tab==='cashiers'?'active':'' ?>">Cashiers</a>
    <a href="?tab=branches"     class="<?= $tab==='branches'?'active':'' ?>">Branches</a>
    <a href="?tab=transactions" class="<?= $tab==='transactions'?'active':'' ?>">All Transactions</a>
  </div>

  <!-- ===================== ACCOUNTS ===================== -->
  <?php if ($tab === 'accounts'): ?>
    <div class="card">
      <h2>All Customer Accounts</h2>

      <div class="search-bar">
        <input type="text" id="acct_filter"
               placeholder="🔍 Search by customer name, email, or account number…"
               oninput="filterTable('acct_table','acct_filter')">
      </div>

      <table id="acct_table">
        <thead>
          <tr>
            <th>Account No.</th>
            <th>Customer</th>
            <th>Email</th>
            <th>Phone Numbers</th>
            <th>Address</th>
            <th>Branch</th>
            <th>Balance</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($allAccounts as $a): ?>
          <?php $phones = $contactsByCustomer[$a['CustomerID']] ?? []; ?>
          <tr>
            <td><strong><?= htmlspecialchars($a['AccountNumber']) ?></strong></td>
            <td><?= htmlspecialchars($a['Owner']) ?></td>
            <td><?= htmlspecialchars($a['Email'] ?? '—') ?></td>
            <td>
              <?php if ($phones): ?>
                <ul class="phone-list">
                  <?php foreach ($phones as $p): ?>
                    <li>📞 <?= htmlspecialchars($p) ?></li>
                  <?php endforeach; ?>
                </ul>
              <?php else: ?>
                <em style="color:#999;">No contacts</em>
              <?php endif; ?>
            </td>
            <td style="max-width:200px;"><?= htmlspecialchars($a['Address'] ?? '—') ?></td>
            <td><?= htmlspecialchars($a['BranchName']) ?></td>
            <td><strong>LKR <?= number_format($a['Balance'],2) ?></strong></td>
            <td>
              <button class="btn accent small"
                onclick='openAccountEdit(<?= json_encode([
                    "CustomerID"    => $a["CustomerID"],
                    "Name"          => $a["Owner"],
                    "Email"         => $a["Email"],
                    "Address"       => $a["Address"],
                    "AccountNumber" => $a["AccountNumber"],
                    "Balance"       => $a["Balance"],
                    "Contacts"      => $phones
                ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>✎ Edit</button>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <!-- ===================== LOANS ===================== -->
  <?php if ($tab === 'loans'): ?>

    <!-- Pending Loan Applications -->
    <div class="card">
      <h2>Pending Loan Applications <?php if ($pendingCount>0): ?><span class="dot"></span><?php endif; ?></h2>
      <?php if (!$pendingApps): ?>
        <p style="color:#777;">No pending loan applications.</p>
      <?php else: ?>
        <table>
          <thead>
            <tr>
              <th>App ID</th><th>Customer</th><th>Loan Type</th>
              <th>Amount</th><th>Balance</th><th>Applied</th><th>Action</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($pendingApps as $app): ?>
            <tr>
              <td>#<?= $app['ApplicationID'] ?></td>
              <td>
                <strong><?= htmlspecialchars($app['CustomerName']) ?></strong><br>
                <small style="color:#777;"><?= htmlspecialchars($app['Email']) ?></small>
              </td>
              <td><?= htmlspecialchars($app['LoanType']) ?></td>
              <td>LKR <?= number_format($app['Amount'],2) ?></td>
              <td>LKR <?= number_format($app['CustomerBalance'],2) ?></td>
              <td><?= htmlspecialchars($app['AppliedDate']) ?></td>
              <td>
                <button class="btn accent small"
                  onclick='openVerifyModal(<?= json_encode($app, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>🔍 Verify</button>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <!-- ★ Issued Loans with Filters -->
    <div class="card">
      <h2>Issued Loans</h2>

      <form method="get" action="" class="filter-panel">
        <input type="hidden" name="tab" value="loans">

        <div class="filter-grid">
          <div class="filter-field">
            <label>Customer name</label>
            <input type="text" name="f_customer" placeholder="e.g. Kavindu"
                   value="<?= htmlspecialchars($f_customer) ?>">
          </div>

          <div class="filter-field">
            <label>Loan ID</label>
            <input type="text" name="f_loanid" placeholder="e.g. LN0001 or 1"
                   value="<?= htmlspecialchars($f_loanid) ?>">
          </div>

          <div class="filter-field">
            <label>Loan type</label>
            <select name="f_type">
              <option value="">All types</option>
              <?php foreach ($loanTypes as $t): ?>
                <option value="<?= htmlspecialchars($t) ?>" <?= $f_type === $t ? 'selected' : '' ?>>
                  <?= htmlspecialchars($t) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="filter-field">
            <label>Approved from</label>
            <input type="date" name="f_date_from" value="<?= htmlspecialchars($f_date_from) ?>">
          </div>

          <div class="filter-field">
            <label>Approved to</label>
            <input type="date" name="f_date_to" value="<?= htmlspecialchars($f_date_to) ?>">
          </div>

          <div class="filter-field">
            <label>Amount min (LKR)</label>
            <input type="number" name="f_amount_min" step="100" min="0" placeholder="0"
                   value="<?= htmlspecialchars($f_amount_min) ?>">
          </div>

          <div class="filter-field">
            <label>Amount max (LKR)</label>
            <input type="number" name="f_amount_max" step="100" min="0" placeholder="Any"
                   value="<?= htmlspecialchars($f_amount_max) ?>">
          </div>

          <div class="filter-field filter-actions">
            <button type="submit" class="btn accent small">🔍 Filter</button>
            <?php if ($hasFilters): ?>
              <a href="?tab=loans" class="btn gray small">✕ Clear</a>
            <?php endif; ?>
          </div>
        </div>
      </form>

      <?php if ($hasFilters): ?>
        <div class="filter-summary">
          Showing <strong><?= $totalFilteredCount ?></strong>
          loan<?= $totalFilteredCount === 1 ? '' : 's' ?>
          · Total amount: <strong>LKR <?= number_format($totalFilteredAmount, 2) ?></strong>
          <?php if ($f_customer): ?> · Name contains "<em><?= htmlspecialchars($f_customer) ?></em>"<?php endif; ?>
          <?php if ($f_type): ?> · Type: <em><?= htmlspecialchars($f_type) ?></em><?php endif; ?>
          <?php if ($f_date_from || $f_date_to): ?>
            · Dates: <em><?= htmlspecialchars($f_date_from ?: '…') ?> → <?= htmlspecialchars($f_date_to ?: '…') ?></em>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <?php if (!$approvedLoans): ?>
        <p style="color:#777;padding:20px 0;">
          <?= $hasFilters ? 'No loans match your filters.' : 'No loans issued yet.' ?>
        </p>
      <?php else: ?>
        <table>
          <thead>
            <tr>
              <th>Loan ID</th><th>Customer</th><th>Type</th><th>Amount</th>
              <th>Rate</th><th>Issued</th><th>Approved</th><th>Approved By</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($approvedLoans as $l): ?>
            <tr>
              <td><strong>LN<?= str_pad($l['LoanID'], 4, '0', STR_PAD_LEFT) ?></strong></td>
              <td><?= htmlspecialchars($l['Customer']) ?></td>
              <td><?= htmlspecialchars($l['LoanType']) ?></td>
              <td>LKR <?= number_format($l['Amount'], 2) ?></td>
              <td><?= $l['LoanRate'] ?>%</td>
              <td><?= htmlspecialchars($l['IssueDate']) ?></td>
              <td><?= htmlspecialchars($l['ApprovedDate'] ?? '—') ?></td>
              <td><?= htmlspecialchars($l['ManagerName'] ?? '—') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <!-- ===================== CASHIERS ===================== -->
  <?php if ($tab === 'cashiers'): ?>
    <div class="card">
      <h2>Cashier Management</h2>

      <div class="search-bar">
        <input type="text" id="cashier_filter"
               placeholder="🔍 Search by name, email, employee ID, branch…"
               oninput="filterTable('cashier_table','cashier_filter')">
      </div>

      <?php if (!$cashiers): ?>
        <p style="color:#777;">No cashiers registered.</p>
      <?php else: ?>
        <table id="cashier_table">
          <thead>
            <tr>
              <th>Employee ID</th><th>Name</th><th>Email</th><th>Branch</th>
              <th>Salary</th><th>Hours / Week</th><th>Experience</th><th>Action</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($cashiers as $c): ?>
            <tr>
              <td><strong><?= htmlspecialchars($c['EmployeeID']) ?></strong></td>
              <td><?= htmlspecialchars($c['Name']) ?></td>
              <td><?= htmlspecialchars($c['Email'] ?? '—') ?></td>
              <td>
                <?= htmlspecialchars($c['BranchName'] ?? '—') ?>
                <?php if ($c['BranchID']): ?>
                  <span class="badge badge-branch"><?= htmlspecialchars($c['BranchID']) ?></span>
                <?php endif; ?>
              </td>
              <td>LKR <?= number_format($c['Salary'],2) ?></td>
              <td><?= $c['WorkingHours'] ?></td>
              <td><?= $c['YearsExperience'] ?> yrs</td>
              <td>
                <button class="btn accent small"
                  onclick='openCashierEdit(<?= json_encode($c, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>✎ Edit</button>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <!-- ===================== BRANCHES ===================== -->
  <?php if ($tab === 'branches'): ?>
    <div class="card">
      <h2>All Branches</h2>
      <p style="color:#777;font-size:13px;margin-bottom:10px;">
        Read-only overview — branch name, manager(s), and contact details.
      </p>
      <table>
        <thead>
          <tr>
            <th>Branch ID</th><th>Branch Name</th><th>Manager</th><th>Contact Details</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($branches as $b): ?>
          <tr>
            <td><strong><?= htmlspecialchars($b['BranchID']) ?></strong></td>
            <td><?= htmlspecialchars($b['BranchName']) ?></td>
            <td>
              <?php if ($b['ManagerName']): ?>
                <?= htmlspecialchars($b['ManagerName']) ?><br>
                <small style="color:#777;"><?= htmlspecialchars($b['ManagerEmail'] ?? '') ?></small>
              <?php else: ?>
                <em style="color:#999;">Not assigned</em>
              <?php endif; ?>
            </td>
            <td>
              📞 <?= htmlspecialchars($b['Phone']) ?><br>
              <small style="color:#777;"><?= htmlspecialchars($b['Address']) ?></small>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <!-- ===================== TRANSACTIONS ===================== -->
  <?php if ($tab === 'transactions'): ?>
    <div class="card">
      <h2>All Bank Transactions</h2>
      <div class="stats">
        <div class="stat"><div class="label">Transactions</div><div class="value"><?= $txnCount ?></div></div>
        <div class="stat dep"><div class="label">Total Deposits</div><div class="value">LKR <?= number_format($txnSumDep,2) ?></div></div>
        <div class="stat wit"><div class="label">Total Withdrawals</div><div class="value">LKR <?= number_format($txnSumWit,2) ?></div></div>
        <div class="stat"><div class="label">Net Flow</div><div class="value">LKR <?= number_format($txnSumDep - $txnSumWit,2) ?></div></div>
      </div>
      <div class="search-bar">
        <input type="text" id="txn_filter" placeholder="Filter by account, customer, cashier, type…"
               oninput="filterTable('txn_table','txn_filter')">
      </div>
      <table id="txn_table">
        <thead>
          <tr>
            <th>Date</th><th>Account</th><th>Customer</th><th>Type</th>
            <th>Amount</th><th>Cashier</th><th>Description</th><th>Transaction ID</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$allTransactions): ?>
          <tr><td colspan="8" style="text-align:center;color:#777;">No transactions yet.</td></tr>
        <?php else: foreach ($allTransactions as $t): ?>
          <tr>
            <td><?= htmlspecialchars($t['Date']) ?></td>
            <td><?= htmlspecialchars($t['AccountNumber']) ?></td>
            <td><?= htmlspecialchars($t['CustomerName'] ?? '—') ?></td>
            <td><?= htmlspecialchars($t['Type']) ?></td>
            <td class="<?= $t['Type']==='Deposit'?'credit':'debit' ?>">
              <?= $t['Type']==='Deposit' ? '+' : '-' ?>LKR <?= number_format($t['Amount'],2) ?>
            </td>
            <td><?= htmlspecialchars($t['CashierName'] ?? 'Self / System') ?></td>
            <td><?= htmlspecialchars($t['Description'] ?? '—') ?></td>
            <td style="font-family:monospace;font-size:12px;"><?= htmlspecialchars($t['TransactionID']) ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
<?php endif; ?>
</div>

<!-- ★ ACCOUNT EDIT MODAL -->
<div class="modal-overlay" id="accountEditModal">
  <div class="modal-box">
    <div class="modal-header">
      <h3>✎ Edit Customer Account</h3>
      <button class="modal-close" onclick="closeModal('accountEditModal')">&times;</button>
    </div>
    <form method="post" action="<?= MANAGER_URL ?>account_edit.php">
      <div class="modal-body">
        <input type="hidden" name="customer_id" id="ae_customer_id">

        <div class="form-group">
          <label>Account Number (fixed)</label>
          <input type="text" id="ae_account" class="readonly" disabled>
        </div>

        <div class="form-group">
          <label>Balance (fixed)</label>
          <input type="text" id="ae_balance" class="readonly" disabled>
        </div>

        <div class="form-group">
          <label>Customer Name</label>
          <input type="text" name="name" id="ae_name" required>
        </div>

        <div class="form-group">
          <label>Email</label>
          <input type="email" name="email" id="ae_email" required>
        </div>

        <div class="form-group">
          <label>Address</label>
          <input type="text" name="address" id="ae_address">
        </div>

        <div class="form-group">
          <label>Phone Numbers <span style="color:#777;font-weight:400;">(multivalued — add as many as you need)</span></label>
          <div id="ae_phones_container"></div>
          <button type="button" class="btn gray small" style="margin-top:8px;" onclick="addPhoneRow('')">+ Add another phone</button>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn gray" onclick="closeModal('accountEditModal')">Cancel</button>
        <button type="submit" class="btn accent">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- ★ CASHIER EDIT MODAL -->
<div class="modal-overlay" id="cashierEditModal">
  <div class="modal-box">
    <div class="modal-header">
      <h3>✎ Edit Cashier</h3>
      <button class="modal-close" onclick="closeModal('cashierEditModal')">&times;</button>
    </div>
    <form method="post" action="<?= MANAGER_URL ?>cashier_edit.php">
      <div class="modal-body">
        <input type="hidden" name="employee_id" id="ce_employee_id">

        <div class="form-group">
          <label>Employee ID (fixed)</label>
          <input type="text" id="ce_emp_display" class="readonly" disabled>
        </div>

        <div class="form-group">
          <label>Name</label>
          <input type="text" name="name" id="ce_name" required>
        </div>

        <div class="form-group">
          <label>Email</label>
          <input type="email" name="email" id="ce_email" required>
        </div>

        <div class="form-group">
          <label>Salary (LKR)</label>
          <input type="number" name="salary" id="ce_salary" step="0.01" min="0">
        </div>

        <div class="form-group">
          <label>Working Hours / Week</label>
          <input type="number" name="working_hours" id="ce_hours" step="0.5" min="0" max="80">
        </div>

        <div class="form-group">
          <label>Years of Experience</label>
          <input type="number" name="years_experience" id="ce_years" min="0">
        </div>

        <div class="form-group">
          <label>Branch</label>
          <select name="branch_id" id="ce_branch">
            <option value="">— None —</option>
            <?php foreach ($branches as $b): ?>
              <option value="<?= htmlspecialchars($b['BranchID']) ?>">
                <?= htmlspecialchars($b['BranchName']) ?> (<?= htmlspecialchars($b['BranchID']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn gray" onclick="closeModal('cashierEditModal')">Cancel</button>
        <button type="submit" class="btn accent">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- VERIFY LOAN MODAL -->
<div class="modal-overlay" id="verifyModal">
  <div class="modal-box">
    <div class="modal-header">
      <h3>📋 Loan Application — Verification</h3>
      <button class="modal-close" onclick="closeModal('verifyModal')">&times;</button>
    </div>
    <div class="modal-body">
      <h4 style="color:var(--accent);margin-bottom:10px;">👤 Applicant</h4>
      <div id="modal_applicant" style="margin-bottom:15px;"></div>
      <h4 style="color:var(--accent);margin-bottom:10px;">💰 Loan Request</h4>
      <div id="modal_loan" style="margin-bottom:15px;"></div>
      <h4 style="color:var(--accent);margin-bottom:10px;">💼 Employment & Income</h4>
      <div id="modal_employment"></div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn gray" onclick="closeModal('verifyModal')">CLOSE</button>
      <form method="post" action="<?= MANAGER_URL ?>loan_action.php" style="display:inline;">
        <input type="hidden" name="application_id" id="modal_app_id">
        <button type="submit" name="action" value="reject"  class="btn danger">REJECT</button>
        <button type="submit" name="action" value="approve" class="btn success">✓ APPROVE & CREDIT</button>
      </form>
    </div>
  </div>
</div>

<script>
// Generic filter for any table
function filterTable(tableId, inputId) {
    var q = document.getElementById(inputId).value.toLowerCase();
    document.querySelectorAll('#' + tableId + ' tbody tr').forEach(function(r) {
        r.style.display = r.innerText.toLowerCase().includes(q) ? '' : 'none';
    });
}

// Modal helpers
function closeModal(id) { document.getElementById(id).style.display = 'none'; }
window.onclick = function(event) {
    ['accountEditModal','cashierEditModal','verifyModal'].forEach(function(id) {
        var m = document.getElementById(id);
        if (event.target == m) m.style.display = 'none';
    });
}

// Multivalued phone rows
function addPhoneRow(value) {
    var container = document.getElementById('ae_phones_container');
    var row = document.createElement('div');
    row.className = 'phone-row';

    var input = document.createElement('input');
    input.type = 'text';
    input.name = 'contacts[]';
    input.placeholder = 'e.g. 077-1234567';
    input.value = value || '';

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.innerHTML = '×';
    btn.title = 'Remove';
    btn.onclick = function() { row.remove(); };

    row.appendChild(input);
    row.appendChild(btn);
    container.appendChild(row);
}

function setPhones(contacts) {
    var container = document.getElementById('ae_phones_container');
    container.innerHTML = '';
    if (!contacts || contacts.length === 0) {
        addPhoneRow('');
    } else {
        contacts.forEach(function(c) { addPhoneRow(c); });
    }
}

// Open account edit
function openAccountEdit(a) {
    document.getElementById('ae_customer_id').value = a.CustomerID;
    document.getElementById('ae_account').value     = a.AccountNumber;
    document.getElementById('ae_balance').value     = 'LKR ' + Number(a.Balance).toLocaleString();
    document.getElementById('ae_name').value        = a.Name || '';
    document.getElementById('ae_email').value       = a.Email || '';
    document.getElementById('ae_address').value     = a.Address || '';
    setPhones(a.Contacts || []);
    document.getElementById('accountEditModal').style.display = 'block';
}

// Open cashier edit
function openCashierEdit(c) {
    document.getElementById('ce_employee_id').value = c.EmployeeID;
    document.getElementById('ce_emp_display').value = c.EmployeeID;
    document.getElementById('ce_name').value        = c.Name || '';
    document.getElementById('ce_email').value       = c.Email || '';
    document.getElementById('ce_salary').value      = c.Salary || 0;
    document.getElementById('ce_hours').value       = c.WorkingHours || 0;
    document.getElementById('ce_years').value       = c.YearsExperience || 0;
    document.getElementById('ce_branch').value      = c.BranchID || '';
    document.getElementById('cashierEditModal').style.display = 'block';
}

// Loan verify modal
function openVerifyModal(app) {
    document.getElementById('modal_app_id').value = app.ApplicationID;

    var balance = Number(app.CustomerBalance || 0);
    var amount  = Number(app.Amount);

    var minBalance = 50000;
    var multiplier = 4;

    // Eligibility is decided by the customer's monthly income vs requested amount
    // (manager-side display only — the rule is enforced on the customer side)
    var salaryBased = Number(app.MonthlyIncome || 0) * multiplier;
    var eligible = (balance >= minBalance) && (amount <= salaryBased);
    var eligText = eligible
        ? '<span style="color:var(--success);font-weight:700;">✓ Within eligibility rules</span>'
        : '<span style="color:var(--danger);font-weight:700;">⚠️ Outside eligibility rules</span>';

    document.getElementById('modal_applicant').innerHTML =
        '<p>Name: <strong>' + app.CustomerName + '</strong></p>' +
        '<p>Email: ' + app.Email + '</p>' +
        '<p>Contact: ' + (app.ContactNumber || '—') + '</p>' +
        '<p>Branch: ' + (app.BranchName || '—') + '</p>' +
        '<p>Total Balance: <strong>LKR ' + balance.toLocaleString() + '</strong></p>' +
        '<p>Eligibility: ' + eligText + '</p>';

    document.getElementById('modal_loan').innerHTML =
        '<p>Type: <strong>' + app.LoanType + '</strong></p>' +
        '<p>Amount: <strong>LKR ' + amount.toLocaleString() + '</strong></p>' +
        '<p>Purpose: ' + (app.Purpose || '—') + '</p>' +
        '<p>Term: ' + (app.TermMonths || '—') + ' months</p>';

    document.getElementById('modal_employment').innerHTML =
        '<p>Occupation: ' + (app.Occupation || '—') + '</p>' +
        '<p>Monthly Income: LKR ' + Number(app.MonthlyIncome || 0).toLocaleString() + '</p>' +
        '<p>Existing Loans: ' + (app.ExistingLoans || '—') + '</p>' +
        '<p>Collateral: ' + (app.Collateral || '—') + '</p>';

    document.getElementById('verifyModal').style.display = 'block';
}
</script>
</body>
</html>