<?php
require_once dirname(__DIR__) . '/config.php';
if (role() !== 'cashier') redirect(CASHIER_URL);

$transferID = $_POST['transfer_id'] ?? '';
$action     = $_POST['action']      ?? '';
$cashier    = current_user()['id'];

if (!$transferID || !in_array($action, ['approve','reject'])) {
    set_flash('error', 'Invalid request.');
    redirect(CASHIER_URL);
}

// Fetch the pending transfer
$stmt = $pdo->prepare("SELECT * FROM pending_transfer WHERE TransferID = ?");
$stmt->execute([$transferID]);
$t = $stmt->fetch();

if (!$t) {
    set_flash('error', 'Transfer not found.');
    redirect(CASHIER_URL);
}

// ── REJECT ──
if ($action === 'reject') {
    $pdo->prepare("DELETE FROM pending_transfer WHERE TransferID = ?")->execute([$transferID]);
    set_flash('success', "Transfer {$transferID} rejected.");
    redirect(CASHIER_URL);
}

// ── APPROVE ──
// Re-check balance
$stmt = $pdo->prepare("SELECT Balance, CustomerID FROM account WHERE AccountNumber = ?");
$stmt->execute([$t['FromAccount']]);
$src = $stmt->fetch();

if (!$src) {
    set_flash('error', 'Source account no longer exists.');
    redirect(CASHIER_URL);
}
if ($src['Balance'] < $t['Amount']) {
    set_flash('error', 'Insufficient funds. Current balance: LKR ' . number_format($src['Balance'],2));
    redirect(CASHIER_URL);
}

try {
    $pdo->beginTransaction();

    // 1. Move funds
    $pdo->prepare("UPDATE account SET Balance = Balance - ? WHERE AccountNumber = ?")
        ->execute([$t['Amount'], $t['FromAccount']]);
    $pdo->prepare("UPDATE account SET Balance = Balance + ? WHERE AccountNumber = ?")
        ->execute([$t['Amount'], $t['ToAccount']]);

    // 2. Get destination customer
    $stmtDst = $pdo->prepare("SELECT CustomerID FROM account WHERE AccountNumber = ?");
    $stmtDst->execute([$t['ToAccount']]);
    $dstCust = $stmtDst->fetchColumn();

    // 3. Build description text
    //    If customer typed a description, use it.
    //    Otherwise, fall back to the auto-generated one.
    $customerDesc = trim($t['Description'] ?? '');

    if ($customerDesc !== '') {
        $descOut = $customerDesc;
        $descIn  = $customerDesc;
    } else {
        $descOut = "Transfer to {$t['ToAccount']}";
        $descIn  = "Transfer from {$t['FromAccount']}";
    }

    // 4. Log both sides in `transaction`
    $txOut = 'TX' . strtoupper(bin2hex(random_bytes(5)));
    $txIn  = 'TX' . strtoupper(bin2hex(random_bytes(5)));

    $pdo->prepare("
        INSERT INTO `transaction`
        (TransactionID, Date, Amount, Type, AccountNumber, CustomerID, CashierID, Description)
        VALUES (?, NOW(), ?, 'Withdrawal', ?, ?, ?, ?)
    ")->execute([$txOut, $t['Amount'], $t['FromAccount'], $t['CustomerID'], $cashier, $descOut]);

    $pdo->prepare("
        INSERT INTO `transaction`
        (TransactionID, Date, Amount, Type, AccountNumber, CustomerID, CashierID, Description)
        VALUES (?, NOW(), ?, 'Deposit', ?, ?, ?, ?)
    ")->execute([$txIn, $t['Amount'], $t['ToAccount'], $dstCust, $cashier, $descIn]);

    // 5. Delete the pending row
    $pdo->prepare("DELETE FROM pending_transfer WHERE TransferID = ?")->execute([$transferID]);

    $pdo->commit();
    set_flash('success', "Transfer {$transferID} approved. LKR " . number_format($t['Amount'],2) . " moved.");
} catch (Exception $e) {
    $pdo->rollBack();
    set_flash('error', 'Approval failed: ' . $e->getMessage());
}

redirect(CASHIER_URL);