<?php
require_once dirname(__DIR__) . '/config.php';
if (role() !== 'manager') redirect(MANAGER_URL);

$appId     = intval($_POST['application_id'] ?? 0);
$action    = $_POST['action'] ?? '';
$managerID = current_user()['id'];

if (!$appId || !in_array($action, ['approve','reject'])) {
    set_flash('error', 'Invalid request.');
    redirect(MANAGER_URL . '?tab=loans');
}

$stmt = $pdo->prepare("SELECT * FROM pending_loan_application WHERE ApplicationID = ?");
$stmt->execute([$appId]);
$app = $stmt->fetch();

if (!$app) {
    set_flash('error', 'Application not found.');
    redirect(MANAGER_URL . '?tab=loans');
}

// ── REJECT ──
if ($action === 'reject') {
    $pdo->prepare("DELETE FROM pending_loan_application WHERE ApplicationID = ?")->execute([$appId]);
    set_flash('success', "Loan application #{$appId} rejected.");
    redirect(MANAGER_URL . '?tab=loans');
}

// ── APPROVE ──
try {
    $pdo->beginTransaction();

    $rate = 15.00;

    // 1. Insert into loan — LoanID is AUTO_INCREMENT, so we omit it
    $pdo->prepare("
        INSERT INTO loan (LoanType, LoanRate, Amount, IssueDate, ManagerID, BranchID)
        VALUES (?, ?, ?, CURDATE(), ?, ?)
    ")->execute([$app['LoanType'], $rate, $app['Amount'], $managerID, $app['BranchID']]);

    // 2. Get the auto-generated LoanID
    $newLoanId = $pdo->lastInsertId();

    // 3. Link to customer
    $pdo->prepare("
        INSERT INTO loan_process (CustomerID, LoanID, Approval, AppliedDate, ApprovedDate)
        VALUES (?, ?, TRUE, ?, CURDATE())
    ")->execute([$app['CustomerID'], $newLoanId, $app['AppliedDate']]);

    // 4. Credit the customer's account
    $stmtAcc = $pdo->prepare("SELECT AccountNumber FROM account WHERE CustomerID = ? LIMIT 1");
    $stmtAcc->execute([$app['CustomerID']]);
    $targetAcc = $stmtAcc->fetchColumn();

    if ($targetAcc) {
        $pdo->prepare("UPDATE account SET Balance = Balance + ? WHERE AccountNumber = ?")
            ->execute([$app['Amount'], $targetAcc]);

        $txID = 'TX' . strtoupper(bin2hex(random_bytes(5)));
        $pdo->prepare("
            INSERT INTO `transaction` (TransactionID, Date, Amount, Type, AccountNumber, CustomerID, CashierID, Description)
            VALUES (?, NOW(), ?, 'Deposit', ?, ?, NULL, ?)
        ")->execute([$txID, $app['Amount'], $targetAcc, $app['CustomerID'], "Loan Disbursement (LN" . str_pad($newLoanId, 4, '0', STR_PAD_LEFT) . ")"]);
    }

    // 5. Delete pending
    $pdo->prepare("DELETE FROM pending_loan_application WHERE ApplicationID = ?")->execute([$appId]);

    $pdo->commit();
    set_flash('success', "Loan LN" . str_pad($newLoanId, 4, '0', STR_PAD_LEFT) . " approved & LKR  " . number_format($app['Amount'],2) . " credited to {$targetAcc}.");
} catch (Exception $e) {
    $pdo->rollBack();
    set_flash('error', 'Approval failed: ' . $e->getMessage());
}

redirect(MANAGER_URL . '?tab=loans');