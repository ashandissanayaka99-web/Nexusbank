<?php
require_once __DIR__ . '/config.php';
enforce_portal_role();

if (is_logged_in() && role() === 'customer') {
    $custId = current_user()['id'];

    // Fetch customer's accounts
    $stmt = $pdo->prepare("
        SELECT a.AccountNumber, a.Balance, a.OpenedDate,
               b.BranchName, b.BranchID
        FROM account a
        JOIN branch b ON b.BranchID = a.BranchID
        WHERE a.CustomerID = ?
        ORDER BY a.AccountNumber
    ");
    $stmt->execute([$custId]);
    $accounts = $stmt->fetchAll();

    $totalBalance = array_sum(array_column($accounts, 'Balance'));

    // Transaction history
    $history = [];
    if ($accounts) {
        $placeholders = implode(',', array_fill(0, count($accounts), '?'));
        $stmt = $pdo->prepare("
            SELECT TransactionID, Date, Amount, Type, AccountNumber, Description
            FROM `transaction`
            WHERE AccountNumber IN ($placeholders)
            ORDER BY Date DESC
            LIMIT 200
        ");
        $stmt->execute(array_column($accounts, 'AccountNumber'));
        foreach ($stmt->fetchAll() as $row) {
            $history[$row['AccountNumber']][] = $row;
        }
    }

    // Loans
    $stmt = $pdo->prepare("
        SELECT l.LoanID, l.LoanType, l.Amount, l.LoanRate,
               lp.Approval, lp.AppliedDate, lp.ApprovedDate
        FROM loan_process lp
        JOIN loan l ON l.LoanID = lp.LoanID
        WHERE lp.CustomerID = ?
        ORDER BY lp.AppliedDate DESC
    ");
    $stmt->execute([$custId]);
    $approvedLoans = $stmt->fetchAll();

    // Pending loans
    $stmt = $pdo->prepare("
        SELECT ApplicationID, LoanType, Purpose, Amount, TermMonths, Occupation,
               MonthlyIncome, ExistingLoans, Collateral, ContactNumber, AppliedDate
        FROM pending_loan_application
        WHERE CustomerID = ?
        ORDER BY AppliedDate DESC
    ");
    $stmt->execute([$custId]);
    $pendingLoans = $stmt->fetchAll();

    // ★ Loan section gate — check total balance
    $loanGate = check_loan_eligibility($pdo, $custId);  // 0 amount = just the balance gate
    $canApplyLoan = $loanGate['eligible'];

    // Active tab
    $tab = $_GET['tab'] ?? 'accounts';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Nexus Bank — Customer</title>
<style>
:root{--navy:#0a1f44;--navy-light:#16305e;--accent:#4a90e2;--white:#fff;--grey:#f4f6fa;--danger:#d9534f;--success:#28a745;}
*{box-sizing:border-box;margin:0;padding:0;font-family:'Segoe UI',Arial,sans-serif;}
body{background:var(--grey);color:#222;min-height:100vh;}
.topbar{background:var(--navy);color:var(--white);padding:14px 30px;display:flex;justify-content:space-between;align-items:center;box-shadow:0 2px 8px rgba(0,0,0,.15);}
.topbar .brand{font-size:22px;font-weight:700;letter-spacing:1px;}
.topbar .brand span{color:var(--accent);}
.topbar .userinfo{font-size:14px;}
.topbar .userinfo a{color:#ffd27f;text-decoration:none;margin-left:14px;}
.container{max-width:1100px;margin:30px auto;padding:0 20px;}
.card{background:var(--white);border-radius:10px;padding:25px 30px;box-shadow:0 2px 12px rgba(10,31,68,.08);margin-bottom:24px;}
.card h2{color:var(--navy);margin-bottom:16px;font-size:20px;border-bottom:2px solid var(--grey);padding-bottom:10px;}
.form-group{margin-bottom:16px;}
.form-group label{display:block;margin-bottom:6px;font-weight:600;color:var(--navy);font-size:14px;}
.form-group input,.form-group select{width:100%;padding:11px 12px;border:1px solid #cfd6e4;border-radius:6px;font-size:14px;}
.form-group input:focus,.form-group select:focus{outline:none;border-color:var(--accent);}
.btn{display:inline-block;background:var(--navy);color:var(--white);padding:11px 20px;border:none;border-radius:6px;font-size:15px;font-weight:600;cursor:pointer;text-decoration:none;}
.btn:hover{background:var(--navy-light);}
.btn.full{width:100%;}
.btn.accent{background:var(--accent);}
.btn.accent:hover{background:#357ab8;}
.btn.success{background:var(--success);}
.btn.danger{background:var(--danger);}
.btn.small{padding:6px 12px;font-size:13px;}
.btn.gray{background:#777;}
.alert{padding:12px 16px;border-radius:6px;margin-bottom:16px;font-size:14px;}
.alert-success{background:#e6f7ea;color:#1e7a34;border:1px solid #b8e6c2;}
.alert-error{background:#fdecec;color:#a02525;border:1px solid #f5c2c2;}
table{width:100%;border-collapse:collapse;margin-top:10px;font-size:14px;}
th,td{text-align:left;padding:10px 12px;border-bottom:1px solid #e4e8f0;}
th{background:var(--navy);color:var(--white);font-weight:600;}
tr:hover td{background:#f8faff;}
.badge{padding:4px 10px;border-radius:20px;font-size:12px;font-weight:600;}
.badge-pending{background:#fff3cd;color:#8a6d00;}
.badge-approved{background:#d4edda;color:#1e7a34;}
.badge-rejected{background:#f8d7da;color:#a02525;}
.login-wrap{max-width:420px;margin:70px auto;}
.login-wrap .logo{text-align:center;color:var(--navy);font-size:30px;font-weight:800;margin-bottom:6px;}
.login-wrap .logo span{color:var(--accent);}
.login-wrap .tagline{text-align:center;color:#777;margin-bottom:24px;font-size:14px;}
.credit{color:var(--success);font-weight:600;}
.debit{color:var(--danger);font-weight:600;}

.welcome-card{background:linear-gradient(135deg,var(--navy),var(--navy-light));color:#fff;border-radius:10px;padding:30px;margin-bottom:24px;box-shadow:0 4px 16px rgba(10,31,68,.2);}
.welcome-card h2{color:#fff;border:none;padding:0;margin-bottom:6px;font-size:22px;}
.welcome-card .sub{color:#a8b8d8;font-size:13px;margin-bottom:18px;}
.welcome-card .total{font-size:36px;font-weight:800;letter-spacing:1px;}
.welcome-card .total-label{font-size:12px;color:#a8b8d8;text-transform:uppercase;letter-spacing:2px;margin-bottom:4px;}

.tabs{display:flex;gap:10px;margin-bottom:24px;flex-wrap:wrap;}
.tabs a{flex:1;min-width:150px;text-align:center;padding:14px 20px;border-radius:8px;text-decoration:none;font-size:15px;font-weight:600;background:#fff;color:var(--navy);box-shadow:0 2px 8px rgba(10,31,68,.06);border:2px solid transparent;transition:.2s;}
.tabs a:hover{background:#eef4ff;}
.tabs a.active{background:var(--navy);color:#fff;border-color:var(--navy);}
.tabs a .icon{display:block;font-size:22px;margin-bottom:4px;}

.calc-wrap{display:grid;grid-template-columns:1fr 1fr;gap:15px;margin-bottom:15px;}
.calc-summary{background:#f8faff;border-left:4px solid var(--accent);padding:20px;border-radius:6px;margin-bottom:20px;}
.calc-summary div{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px dashed #cfd6e4;font-size:15px;}
.calc-summary div:last-child{border-bottom:none;font-weight:700;color:var(--navy);font-size:18px;margin-top:6px;padding-top:14px;border-top:2px solid #cfd6e4;}
.calc-summary .installment{color:var(--accent);font-weight:700;}
@media(max-width:640px){.calc-wrap{grid-template-columns:1fr;}}

.modal-overlay{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(10,31,68,0.7);z-index:1000;overflow-y:auto;}
.modal-box{background:#fff;max-width:720px;margin:40px auto;border-radius:10px;box-shadow:0 10px 30px rgba(0,0,0,0.3);}
.modal-header{background:var(--navy);color:#fff;padding:18px 25px;border-radius:10px 10px 0 0;display:flex;justify-content:space-between;align-items:center;}
.modal-header h3{font-size:18px;margin:0;}
.modal-header.danger{background:var(--danger);}
.modal-close{background:none;border:none;color:#fff;font-size:24px;cursor:pointer;line-height:1;}
.modal-body{padding:25px;}
.modal-footer{padding:18px 25px;border-top:1px solid #e4e8f0;display:flex;justify-content:flex-end;gap:12px;}
.form-section{background:#f8faff;border-left:4px solid var(--accent);padding:20px;border-radius:6px;margin-bottom:20px;}
.form-section h4{color:var(--navy);margin-bottom:15px;font-size:16px;}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:15px;}
.form-grid .full{grid-column:span 2;}
@media(max-width:640px){.form-grid{grid-template-columns:1fr;}.form-grid .full{grid-column:span 1;}}
.live-warning{background:#fdecec;border:1px solid #f5c2c2;color:#a02525;padding:10px 14px;border-radius:6px;font-size:13px;margin-top:8px;display:none;}
.live-warning.show{display:block;}

.acc-toggle{display:flex;justify-content:space-between;align-items:center;background:#f8faff;border:1px solid #cfd6e4;border-radius:8px;padding:12px 18px;margin-bottom:8px;cursor:pointer;}
.acc-toggle:hover{background:#eef4ff;}
.acc-toggle .left{font-weight:600;color:var(--navy);}
.acc-toggle .right{color:#555;font-size:14px;}
.acc-body{display:none;padding:0 8px 12px;border-left:3px solid var(--accent);margin:0 0 15px 10px;}
.acc-body.open{display:block;}

/* ★ Loan gate banner */
.gate-banner{background:#fdecec;border:2px solid var(--danger);color:#a02525;border-radius:10px;padding:25px;margin-bottom:24px;text-align:center;}
.gate-banner h2{color:#a02525;border:none;padding:0;margin-bottom:10px;font-size:20px;}
.gate-banner p{font-size:15px;margin-bottom:8px;}
.gate-banner .amount{font-size:22px;font-weight:800;color:var(--danger);}
</style>
</head>
<body>

<?php if (is_logged_in()): ?>
<div class="topbar">
  <div class="brand">NEXUS<span>BANK</span></div>
  <div class="userinfo">
    <?= htmlspecialchars(current_user()['name']) ?> | <strong>Customer</strong>
    <a href="<?= CUSTOMER_URL ?>logout.php">Logout</a>
  </div>
</div>
<?php endif; ?>

<div class="container">
<?php show_flash(); ?>

<?php if (!is_logged_in() || role() !== 'customer'): ?>
  <!-- LOGIN -->
  <div class="login-wrap">
    <div class="logo">NEXUS<span>BANK</span></div>
    <div class="tagline">Customer Online Banking</div>
    <div class="card">
      <h2>Sign in</h2>
      <form method="post" action="<?= CUSTOMER_URL ?>login.php">
        <div class="form-group"><label>Email address</label><input type="email" name="email" required></div>
        <div class="form-group"><label>Password</label><input type="password" name="password" required></div>
        <button class="btn full" type="submit">Sign In</button>
      </form>
      <p style="margin-top:16px;font-size:13px;color:#777;text-align:center;">
        Don't have an account? <a href="<?= CUSTOMER_URL ?>register.php" style="color:var(--accent);">Register</a>
      </p>
    </div>
  </div>

<?php else: ?>

  <div class="welcome-card">
    <h2>Welcome back, <?= htmlspecialchars(current_user()['name']) ?> 👋</h2>
    <div class="sub"><?= htmlspecialchars(current_user()['email']) ?></div>
    <div class="total-label">Total Balance</div>
    <div class="total">LKR <?= number_format($totalBalance, 2) ?></div>
  </div>

  <div class="tabs">
    <a href="?tab=accounts" class="<?= $tab==='accounts'?'active':'' ?>">
      <span class="icon">🏦</span> My Accounts
    </a>
    <a href="?tab=history" class="<?= $tab==='history'?'active':'' ?>">
      <span class="icon">📊</span> Transaction History
    </a>
    <a href="?tab=loans" class="<?= $tab==='loans'?'active':'' ?>">
      <span class="icon">💰</span> Loans
    </a>
  </div>

  <!-- ============== TAB: MY ACCOUNTS ============== -->
  <?php if ($tab === 'accounts'): ?>

    <div class="card">
      <h2>My Accounts</h2>
      <?php if (!$accounts): ?>
        <p style="color:#777;">No accounts yet.</p>
      <?php else: ?>
        <table>
          <thead><tr><th>Account No.</th><th>Branch</th><th>Opened</th><th>Balance</th></tr></thead>
          <tbody>
          <?php foreach ($accounts as $a): ?>
            <tr>
              <td><strong><?= htmlspecialchars($a['AccountNumber']) ?></strong></td>
              <td><?= htmlspecialchars($a['BranchName']) ?></td>
              <td><?= htmlspecialchars($a['OpenedDate']) ?></td>
              <td>LKR <?= number_format($a['Balance'],2) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <div class="card">
      <h2>Transfer Money</h2>
      <form method="post" action="<?= CUSTOMER_URL ?>transfer.php">
        <div class="form-group">
          <label>From account</label>
          <select name="from" required>
            <?php foreach ($accounts as $a): ?>
              <option value="<?= htmlspecialchars($a['AccountNumber']) ?>">
                <?= htmlspecialchars($a['AccountNumber']) ?> — LKR <?= number_format($a['Balance'],2) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>To account number</label>
          <input type="text" name="to" placeholder="e.g. ACC2001" required>
        </div>
        <div class="form-group">
          <label>Amount (LKR)</label>
          <input type="number" name="amount" step="0.01" min="0.01" required>
        </div>
        <div class="form-group">
          <label>Description (optional)</label>
          <input type="text" name="description" maxlength="255"
                 placeholder="e.g. Rent payment, Tuition fee, Gift to mom">
        </div>
        <button class="btn accent" type="submit">Transfer</button>
      </form>
    </div>

    <div class="card" style="text-align:center;padding:35px;">
      <h2 style="border:none;margin-bottom:6px;">Need a Loan?</h2>
      <p style="color:#555;margin-bottom:18px;">Head over to the Loans tab to check your eligibility.</p>
      <a href="?tab=loans" class="btn accent" style="padding:14px 40px;font-size:16px;">Go to Loans →</a>
    </div>

  <?php endif; ?>

  <!-- ============== TAB: TRANSACTION HISTORY ============== -->
  <?php if ($tab === 'history'): ?>
    <div class="card">
      <h2>Transaction History</h2>
      <?php if (!$accounts): ?>
        <p style="color:#777;">No accounts to show history for.</p>
      <?php else: ?>
        <?php foreach ($accounts as $a): ?>
          <?php $txns = $history[$a['AccountNumber']] ?? []; ?>
          <div class="acc-toggle" onclick="toggleAcc('h_<?= htmlspecialchars($a['AccountNumber']) ?>')">
            <span class="left"><?= htmlspecialchars($a['AccountNumber']) ?> — <?= htmlspecialchars($a['BranchName']) ?></span>
            <span class="right">
              Balance: LKR <?= number_format($a['Balance'],2) ?> &nbsp;·&nbsp;
              <?= count($txns) ?> transaction<?= count($txns)===1?'':'s' ?>
            </span>
          </div>
          <div class="acc-body" id="h_<?= htmlspecialchars($a['AccountNumber']) ?>">
            <?php if (!$txns): ?>
              <p style="color:#777;padding:12px;">No transactions yet.</p>
            <?php else: ?>
              <table>
                <thead><tr><th>Date</th><th>Type</th><th>Amount</th><th>Description</th><th>Transaction ID</th></tr></thead>
                <tbody>
                <?php foreach ($txns as $t): ?>
                  <tr>
                    <td><?= htmlspecialchars($t['Date']) ?></td>
                    <td><?= htmlspecialchars($t['Type']) ?></td>
                    <td class="<?= $t['Type']==='Deposit' ? 'credit' : 'debit' ?>">
                      <?= $t['Type']==='Deposit' ? '+' : '-' ?>LKR <?= number_format($t['Amount'],2) ?>
                    </td>
                    <td><?= htmlspecialchars($t['Description'] ?? '—') ?></td>
                    <td style="font-family:monospace;font-size:12px;"><?= htmlspecialchars($t['TransactionID']) ?></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <!-- ============== TAB: LOANS ============== -->
  <?php if ($tab === 'loans'): ?>

    <?php if (!$canApplyLoan): ?>
      <!-- ★ RULE 1 FAILED: Balance gate — no calculator, no form, only the popup -->
      <div class="gate-banner">
        <h2>⚠️ Loan Applications Locked</h2>
        <p>Your total account balance is</p>
        <div class="amount">LKR <?= number_format($totalBalance, 2) ?></div>
        <p style="margin-top:12px;">
          You need at least <strong>LKR <?= number_format(LOAN_MIN_BALANCE, 2) ?></strong>
          across all your accounts to apply for a loan.
        </p>
        <p style="margin-top:8px;color:#777;font-size:13px;">
          Deposit more funds into your accounts to unlock loan applications.
        </p>
      </div>

      <script>
        // ★ Show a popup message when the user lands on the Loans tab
        document.addEventListener('DOMContentLoaded', function () {
          var msg = "Your current total balance is LKR <?= number_format($totalBalance, 2) ?>.\n\n" +
                    "You need at least LKR <?= number_format(LOAN_MIN_BALANCE, 2) ?> in your accounts " +
                    "to apply for a loan.\n\n" +
                    "Please deposit more funds and try again.";
          alert(msg);
        });
      </script>
    <?php else: ?>

      <!-- ★ RULE 1 PASSED: Show calculator + application button -->
      <div class="card">
        <h2>💡 Loan Calculator</h2>
        <p style="color:#555;margin-bottom:18px;">
          Enter an amount and pick a term to preview your monthly installment (15% fixed annual rate).
        </p>

        <div class="calc-wrap">
          <div class="form-group">
            <label>Loan Amount (LKR)</label>
            <input type="number" id="calc_amount" min="100" step="100" value="100000" oninput="recalc()">
          </div>
          <div class="form-group">
            <label>Term (Months)</label>
            <select id="calc_months" onchange="recalc()">
              <option value="6">6 months</option>
              <option value="12" selected>12 months</option>
              <option value="18">18 months</option>
              <option value="24">24 months</option>
              <option value="30">30 months</option>
              <option value="36">36 months</option>
              <option value="48">48 months</option>
              <option value="60">60 months</option>
            </select>
          </div>
        </div>

        <div class="calc-summary" id="calc_summary"></div>

        <div style="text-align:center;margin-top:20px;">
          <button class="btn accent" style="padding:14px 40px;font-size:16px;" onclick="openLoanModal()">📝 Apply for a Loan</button>
        </div>
      </div>

      <div class="card">
        <h2>My Loan Applications</h2>
        <?php if (empty($approvedLoans) && empty($pendingLoans)): ?>
          <p style="color:#777;">No loan applications yet.</p>
        <?php else: ?>
          <table>
            <thead>
              <tr>
                <th>Loan ID</th>
                <th>Type</th>
                <th>Amount</th>
                <th>Term</th>
                <th>Purpose</th>
                <th>Applied</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($pendingLoans as $p): ?>
              <tr>
                <td style="color:#999;">—</td>
                <td><?= htmlspecialchars($p['LoanType']) ?></td>
                <td>LKR <?= number_format($p['Amount'],2) ?></td>
                <td><?= $p['TermMonths'] ?> months</td>
                <td><?= htmlspecialchars($p['Purpose']) ?></td>
                <td><?= htmlspecialchars($p['AppliedDate']) ?></td>
                <td><span class="badge badge-pending">Pending</span></td>
              </tr>
            <?php endforeach; ?>

            <?php foreach ($approvedLoans as $l): ?>
              <tr>
                <td><strong>LN<?= str_pad($l['LoanID'], 4, '0', STR_PAD_LEFT) ?></strong></td>
                <td><?= htmlspecialchars($l['LoanType']) ?></td>
                <td>LKR <?= number_format($l['Amount'],2) ?></td>
                <td>—</td>
                <td>—</td>
                <td><?= htmlspecialchars($l['AppliedDate']) ?></td>
                <td>
                  <span class="badge badge-<?= $l['Approval'] ? 'approved' : 'rejected' ?>">
                    <?= $l['Approval'] ? 'Approved' : 'Rejected' ?>
                  </span>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>

      <!-- ★ LOAN APPLICATION MODAL -->
      <div class="modal-overlay" id="loanModal">
        <div class="modal-box">
          <div class="modal-header">
            <h3>📋 Apply for a Loan</h3>
            <button class="modal-close" onclick="closeModal('loanModal')">&times;</button>
          </div>
          <form method="post" action="<?= CUSTOMER_URL ?>apply_loan.php" onsubmit="return validateLoanForm()">
            <div class="modal-body">

              <div class="form-section">
                <h4>💰 Loan Details</h4>
                <div class="form-grid">
                  <div class="form-group">
                    <label>Loan Amount (LKR) *</label>
                    <input type="number" name="amount" id="modal_amount" step="0.01" min="100" required oninput="checkSalaryRule()">
                  </div>
                  <div class="form-group">
                    <label>Loan Type *</label>
                    <select name="loan_type" required>
                      <option value="Personal">Personal Loan</option>
                      <option value="Home">Home Loan</option>
                      <option value="Car">Car Loan</option>
                      <option value="Education">Education Loan</option>
                    </select>
                  </div>
                  <div class="form-group">
                    <label>Purpose *</label>
                    <input type="text" name="purpose" required placeholder="e.g. Vehicle Purchase">
                  </div>
                  <div class="form-group">
                    <label>Term (months) *</label>
                    <input type="number" name="term_months" id="modal_months" min="1" required>
                  </div>
                </div>
              </div>

              <div class="form-section">
                <h4>👤 Employment & Income</h4>
                <div class="form-grid">
                  <div class="form-group">
                    <label>Occupation *</label>
                    <input type="text" name="occupation" required placeholder="e.g. Software Engineer">
                  </div>
                  <div class="form-group">
                    <label>Monthly Income (LKR) *</label>
                    <input type="number" name="monthly_income" id="modal_income" step="0.01" min="0" required oninput="checkSalaryRule()">
                  </div>
                  <div class="form-group">
                    <label>Existing Loans</label>
                    <input type="text" name="existing_loans" value="None">
                  </div>
                  <div class="form-group">
                    <label>Collateral</label>
                    <input type="text" name="collateral" value="None">
                  </div>
                </div>
                <!-- ★ Live salary rule warning -->
                <div class="live-warning" id="salaryWarning"></div>
              </div>

              <div class="form-section">
                <h4>📞 Contact</h4>
                <div class="form-grid">
                  <div class="form-group full">
                    <label>Contact Number *</label>
                    <input type="text" name="contact_number" required placeholder="e.g. 077-1234567">
                  </div>
                </div>
              </div>

            </div>
            <div class="modal-footer">
              <button type="button" class="btn gray" onclick="closeModal('loanModal')">Cancel</button>
              <button type="submit" class="btn accent" id="submitLoanBtn">Submit Application</button>
            </div>
          </form>
        </div>
      </div>

    <?php endif; ?>

  <?php endif; ?>

<?php endif; ?>
</div>

<script>
function toggleAcc(id) {
    var el = document.getElementById(id);
    if (el) el.classList.toggle('open');
}

function closeModal(id) { document.getElementById(id).style.display = 'none'; }
window.addEventListener('click', function(event) {
    var m = document.getElementById('loanModal');
    if (m && event.target === m) m.style.display = 'none';
});

function openLoanModal() {
    var amt = document.getElementById('calc_amount').value || 100000;
    var mon = document.getElementById('calc_months').value || 12;
    document.getElementById('modal_amount').value = amt;
    document.getElementById('modal_months').value = mon;
    checkSalaryRule();
    document.getElementById('loanModal').style.display = 'block';
}

// ★ Rule 2 — live salary check inside the modal
function checkSalaryRule() {
    var amount = parseFloat(document.getElementById('modal_amount').value) || 0;
    var income = parseFloat(document.getElementById('modal_income').value) || 0;

    var warn     = document.getElementById('salaryWarning');
    var submit   = document.getElementById('submitLoanBtn');
    var maxBySal = income * <?= LOAN_MAX_SALARY_MULTIPLIER ?>;

    if (income > 0 && amount > 0 && amount > maxBySal) {
        warn.classList.add('show');
        warn.innerHTML = '⚠️ <strong>Monthly salary is not enough.</strong> ' +
                         'Based on a monthly income of LKR ' + income.toLocaleString() + ', ' +
                         'the maximum loan you can request is LKR ' + maxBySal.toLocaleString() +
                         ' (<?= LOAN_MAX_SALARY_MULTIPLIER ?>× your income).';
        submit.disabled = true;
        submit.style.opacity = 0.5;
        submit.style.cursor = 'not-allowed';
    } else {
        warn.classList.remove('show');
        warn.innerHTML = '';
        submit.disabled = false;
        submit.style.opacity = 1;
        submit.style.cursor = 'pointer';
    }
}

// ★ Final guard before submission
function validateLoanForm() {
    var amount = parseFloat(document.getElementById('modal_amount').value) || 0;
    var income = parseFloat(document.getElementById('modal_income').value) || 0;
    var maxBySal = income * <?= LOAN_MAX_SALARY_MULTIPLIER ?>;

    if (income > 0 && amount > maxBySal) {
        alert('Monthly salary is not enough.\n\n' +
              'Based on a monthly income of LKR ' + income.toLocaleString() + ', ' +
              'the maximum loan you can request is LKR ' + maxBySal.toLocaleString() + '.');
        return false;
    }
    return true;
}

// Loan Calculator
function calcInstallment(principal, annualRatePct, months) {
    var r = (annualRatePct / 100) / 12;
    if (r === 0) return principal / months;
    return principal * r / (1 - Math.pow(1 + r, -months));
}
function fmt(x) {
    return 'LKR ' + x.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function recalc() {
    var amountEl = document.getElementById('calc_amount');
    if (!amountEl) return;
    var amount = parseFloat(amountEl.value) || 0;
    var months = parseInt(document.getElementById('calc_months').value) || 12;
    var rate   = 15;

    var inst     = calcInstallment(amount, rate, months);
    var total    = inst * months;
    var interest = total - amount;

    document.getElementById('calc_summary').innerHTML =
        '<div><span>Loan amount:</span><span>' + fmt(amount) + '</span></div>' +
        '<div><span>Annual interest rate:</span><span>' + rate + '%</span></div>' +
        '<div><span>Term:</span><span>' + months + ' months</span></div>' +
        '<div><span>Monthly installment:</span><span class="installment">' + fmt(inst) + '</span></div>' +
        '<div><span>Total payable:</span><span>' + fmt(total) + '</span></div>' +
        '<div><span>Total interest:</span><span>' + fmt(interest) + '</span></div>';
}

document.addEventListener('DOMContentLoaded', function() {
    if (document.getElementById('calc_amount')) recalc();
});
</script>
</body>
</html>