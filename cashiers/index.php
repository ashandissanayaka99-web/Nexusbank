<?php
require_once dirname(__DIR__) . '/config.php';
enforce_portal_role();

$action       = $_GET['action'] ?? '';
$selectedAcc  = $_GET['acc']    ?? '';
$searchQuery  = trim($_GET['q'] ?? '');
$searchResults = [];
$selected     = null;

if (is_logged_in() && role() === 'cashier') {

    // Pending account applications
    $pendingApps = $pdo->query("
        SELECT p.*, b.BranchName
        FROM pending_account_application p
        JOIN branch b ON b.BranchID = p.BranchID
        ORDER BY p.AppliedDate ASC
    ")->fetchAll();

    // ★ NEW: Pending transfers initiated by customers
    $pendingTransfers = $pdo->query("
        SELECT pt.TransferID, pt.FromAccount, pt.ToAccount, pt.Amount,
               pt.InitiatedAt,
               c.Name AS CustomerName, c.CustomerID,
               fa.Balance AS FromBalance
        FROM pending_transfer pt
        JOIN customer c   ON c.CustomerID  = pt.CustomerID
        JOIN account  fa  ON fa.AccountNumber = pt.FromAccount
        ORDER BY pt.InitiatedAt ASC
    ")->fetchAll();

    // Search accounts
    if ($searchQuery !== '') {
        $like = "%$searchQuery%";
        $stmt = $pdo->prepare("
            SELECT a.AccountNumber, a.Balance, a.BranchID,
                   c.CustomerID, c.Name AS Owner, b.BranchName
            FROM account a
            JOIN customer c ON c.CustomerID = a.CustomerID
            JOIN branch   b ON b.BranchID   = a.BranchID
            WHERE c.Name LIKE ? OR a.AccountNumber LIKE ?
            ORDER BY a.AccountNumber
        ");
        $stmt->execute([$like, $like]);
        $searchResults = $stmt->fetchAll();
    }

    if ($selectedAcc !== '') {
        $stmt = $pdo->prepare("
            SELECT a.*, c.Name AS Owner, b.BranchName
            FROM account a
            JOIN customer c ON c.CustomerID = a.CustomerID
            JOIN branch   b ON b.BranchID   = a.BranchID
            WHERE a.AccountNumber = ?
        ");
        $stmt->execute([$selectedAcc]);
        $selected = $stmt->fetch();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><title>Nexus Bank — Cashier</title>
<style>
:root{--navy:#0a1f44;--accent:#4a90e2;--white:#fff;--grey:#f4f6fa;--danger:#d9534f;--success:#28a745;}
*{box-sizing:border-box;margin:0;padding:0;font-family:'Segoe UI',Arial,sans-serif;}
body{background:var(--grey);min-height:100vh;}
.topbar{background:var(--navy);color:#fff;padding:14px 30px;display:flex;justify-content:space-between;align-items:center;}
.topbar .brand{font-size:22px;font-weight:700;letter-spacing:1px;}
.topbar .brand span{color:var(--accent);}
.topbar .userinfo{font-size:14px;}
.topbar .userinfo a{color:#ffd27f;text-decoration:none;margin-left:14px;}
.container{max-width:1100px;margin:30px auto;padding:0 20px;}
.card{background:#fff;border-radius:10px;padding:25px 30px;box-shadow:0 2px 12px rgba(10,31,68,.08);margin-bottom:24px;}
.card h2{color:var(--navy);margin-bottom:16px;font-size:20px;border-bottom:2px solid var(--grey);padding-bottom:10px;}
.form-group{margin-bottom:16px;}
.form-group label{display:block;margin-bottom:6px;font-weight:600;color:var(--navy);font-size:14px;}
.form-group input{width:100%;padding:11px 12px;border:1px solid #cfd6e4;border-radius:6px;font-size:14px;}
.form-group input:focus{outline:none;border-color:var(--accent);}
.btn{display:inline-block;background:var(--navy);color:#fff;padding:11px 20px;border:none;border-radius:6px;font-size:15px;font-weight:600;cursor:pointer;text-decoration:none;}
.btn.full{width:100%;}
.btn.accent{background:var(--accent);}
.btn.success{background:var(--success);}
.btn.danger{background:var(--danger);}
.btn.small{padding:6px 12px;font-size:13px;}
.btn.outline{background:transparent;border:2px solid #777;color:#777;}
.alert{padding:12px 16px;border-radius:6px;margin-bottom:16px;font-size:14px;}
.alert-success{background:#e6f7ea;color:#1e7a34;border:1px solid #b8e6c2;}
.alert-error{background:#fdecec;color:#a02525;border:1px solid #f5c2c2;}
table{width:100%;border-collapse:collapse;margin-top:10px;font-size:14px;}
th,td{text-align:left;padding:10px 12px;border-bottom:1px solid #e4e8f0;}
th{background:var(--navy);color:#fff;font-weight:600;}
tr:hover td{background:#f8faff;}
.login-wrap{max-width:420px;margin:70px auto;}
.login-wrap .logo{text-align:center;color:var(--navy);font-size:30px;font-weight:800;margin-bottom:6px;}
.login-wrap .logo span{color:var(--accent);}
.login-wrap .tagline{text-align:center;color:#777;margin-bottom:24px;font-size:14px;}
.customer-found-box{background:#f8faff;border:2px solid var(--accent);border-radius:8px;padding:25px;margin-top:20px;}
.customer-found-box h3{margin-top:0;color:var(--accent);font-size:14px;letter-spacing:1px;text-transform:uppercase;margin-bottom:15px;}
.customer-found-box .highlight{font-weight:700;color:var(--navy);font-size:18px;}
.action-buttons{display:flex;gap:15px;margin-top:20px;}
.action-buttons .btn{flex:1;text-align:center;padding:14px;}
.pending-box{background:#fff8e1;border:2px solid #ffc107;border-radius:8px;padding:20px;margin-bottom:20px;}
.pending-box h3{margin-top:0;color:#856404;}
.inline-edit{width:110px;padding:6px;border:1px solid #ffc107;border-radius:4px;font-size:14px;text-align:right;}
.hint-box{background:#fff3cd;border:1px solid #ffeeba;color:#856404;padding:15px;border-radius:6px;margin-top:15px;font-size:14px;}
.hint-box code{background:#fff;padding:2px 6px;border-radius:4px;border:1px solid #ddd;color:#d9534f;font-weight:bold;}
/* ★ NEW: transfer section */
.transfer-box{background:#eef4ff;border:2px solid var(--accent);border-radius:8px;padding:20px;margin-bottom:20px;}
.transfer-box h3{margin-top:0;color:var(--accent);}
.transfer-box .arrow{font-size:18px;color:var(--accent);}
</style>
</head>
<body>

<?php if (is_logged_in()): ?>
<div class="topbar">
  <div class="brand">NEXUS<span>BANK</span> <span style="font-size:14px;color:#ffd27f;">— Cashier</span></div>
  <div class="userinfo">
    <?= htmlspecialchars(current_user()['name']) ?> | <strong>Cashier</strong>
    <a href="<?= CASHIER_URL ?>logout.php">Logout</a>
  </div>
</div>
<?php endif; ?>

<div class="container">
<?php show_flash(); ?>

<?php if (!is_logged_in() || role() !== 'cashier'): ?>
  <div class="login-wrap">
    <div class="logo">NEXUS<span>BANK</span></div>
    <div class="tagline">Cashier Portal</div>
    <div class="card">
      <h2>Cashier Sign in</h2>
      <form method="post" action="<?= CASHIER_URL ?>login.php">
        <div class="form-group"><label>Email</label><input type="email" name="email" required></div>
        <div class="form-group"><label>Password</label><input type="password" name="password" required></div>
        <button class="btn full" type="submit">Sign In</button>
      </form>
    </div>
  </div>

<?php else: ?>

  <!-- ★ NEW: PENDING TRANSFERS -->
  <?php if (!empty($pendingTransfers)): ?>
    <div class="card transfer-box">
      <h3>💸 Pending Customer Transfers (<?= count($pendingTransfers) ?>)</h3>
      <p style="font-size:13px;color:#555;margin-bottom:10px;">
        These transfer requests were initiated by customeLKR Approve to move funds, or reject to cancel.
      </p>
      <table>
        <thead>
          <tr>
            <th>Transfer ID</th>
            <th>Date / Time</th>
            <th>Customer</th>
            <th>From → To</th>
            <th>Amount</th>
            <th>From Balance</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($pendingTransfers as $pt): ?>
          <tr>
            <td style="font-family:monospace;font-size:12px;"><?= htmlspecialchars($pt['TransferID']) ?></td>
            <td><?= htmlspecialchars($pt['InitiatedAt']) ?></td>
            <td><?= htmlspecialchars($pt['CustomerName']) ?></td>
            <td>
              <?= htmlspecialchars($pt['FromAccount']) ?>
              <span class="arrow">→</span>
              <?= htmlspecialchars($pt['ToAccount']) ?>
            </td>
            <td>LKR <?= number_format($pt['Amount'],2) ?></td>
            <td>LKR <?= number_format($pt['FromBalance'],2) ?></td>
            <td>
              <form method="post" action="<?= CASHIER_URL ?>transfer_action.php" style="display:inline;">
                <input type="hidden" name="transfer_id" value="<?= htmlspecialchars($pt['TransferID']) ?>">
                <button class="btn success small" name="action" value="approve">✓ Approve</button>
                <button class="btn danger small"  name="action" value="reject">✗ Reject</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <!-- PENDING ACCOUNT APPLICATIONS -->
  <?php if (!empty($pendingApps)): ?>
    <div class="card pending-box">
      <h3>Pending Account Applications</h3>
      <p style="font-size:13px;color:#856404;margin-bottom:10px;">Verify initial deposit, then approve or reject.</p>
      <table>
        <thead><tr><th>Name</th><th>Email</th><th>Type</th><th>Branch</th><th>Deposit LKR</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($pendingApps as $p): ?>
          <tr>
            <td><?= htmlspecialchars($p['Name']) ?></td>
            <td><?= htmlspecialchars($p['Email']) ?></td>
            <td><?= htmlspecialchars($p['AccountType']) ?></td>
            <td><?= htmlspecialchars($p['BranchName']) ?></td>
            <td>
              <form method="post" action="<?= CASHIER_URL ?>account_action.php" style="display:inline;">
                <input type="hidden" name="app_id" value="<?= (int)$p['ApplicationID'] ?>">
                <input type="number" name="final_deposit" class="inline-edit" step="0.01" min="0"
                       value="<?= htmlspecialchars($p['InitialDeposit']) ?>" required>
            </td>
            <td>
                <button class="btn success small" name="action" value="approve">Approve</button>
                <button class="btn danger small"  name="action" value="reject">Reject</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <!-- SEARCH -->
  <div class="card">
    <h2>Cashier Panel — Account Search</h2>
    <form method="get" action="">
      <div class="form-group" style="display:flex;gap:10px;margin-top:14px;">
        <input type="text" name="q" placeholder="Search by name or account number"
               value="<?= htmlspecialchars($searchQuery) ?>" style="flex:1;">
        <button class="btn accent" type="submit">Search</button>
      </div>
    </form>
    <?php if ($searchQuery === ''): ?>
      <div class="hint-box">Try searching a customer's name or an account number.</div>
    <?php endif; ?>
  </div>

  <?php if ($searchQuery !== '' && empty($searchResults)): ?>
    <div class="card"><p style="color:#777;">No accounts matched your search.</p></div>
  <?php endif; ?>

  <?php foreach ($searchResults as $a): ?>
    <div class="card customer-found-box">
      <h3>Customer Found</h3>
      <p>Name: <span class="highlight"><?= htmlspecialchars($a['Owner']) ?></span></p>
      <p>Account: <span class="highlight"><?= htmlspecialchars($a['AccountNumber']) ?></span></p>
      <p>Branch: <span class="highlight"><?= htmlspecialchars($a['BranchName']) ?></span></p>
      <p>Balance: <span class="highlight">LKR <?= number_format($a['Balance'],2) ?></span></p>
      <div class="action-buttons">
        <a href="?q=<?= urlencode($searchQuery) ?>&action=deposit&acc=<?= urlencode($a['AccountNumber']) ?>" class="btn success">DEPOSIT</a>
        <a href="?q=<?= urlencode($searchQuery) ?>&action=withdraw&acc=<?= urlencode($a['AccountNumber']) ?>" class="btn danger">WITHDRAW</a>
      </div>
    </div>

    <?php if ($action && $selected && $selected['AccountNumber'] === $a['AccountNumber']): ?>
      <div class="card">
        <h2>Transaction: <?= strtoupper(htmlspecialchars($action)) ?></h2>
        <form method="post" action="<?= CASHIER_URL ?>transaction.php">
          <input type="hidden" name="account_no" value="<?= htmlspecialchars($a['AccountNumber']) ?>">
          <input type="hidden" name="type" value="<?= htmlspecialchars($action) ?>">
          <div class="form-group">
            <label>Amount LKR</label>
            <input type="number" name="amount" step="0.01" min="0.01" required autofocus>
          </div>
          <p style="margin:15px 0;color:#555;">Current balance: <strong>LKR <?= number_format($a['Balance'],2) ?></strong></p>
          <a href="?q=<?= urlencode($searchQuery) ?>" class="btn outline">Cancel</a>
          <button class="btn <?= $action === 'deposit' ? 'success' : 'danger' ?>" type="submit">
            Confirm <?= strtoupper(htmlspecialchars($action)) ?>
          </button>
        </form>
      </div>
    <?php endif; ?>
  <?php endforeach; ?>

<?php endif; ?>
</div>
</body>
</html>