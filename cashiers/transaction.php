<?php
require_once dirname(__DIR__) . '/config.php';
if (role() !== 'cashier') redirect(CASHIER_URL);

$accNo   = $_POST['account_no'] ?? '';
$type    = $_POST['type']       ?? '';
$amount  = floatval($_POST['amount'] ?? 0);
$cashier = current_user()['id'];

if (!in_array($type, ['deposit','withdraw']) || $amount <= 0 || !$accNo) {
    set_flash('error', 'Invalid transaction request.');
    redirect(CASHIER_URL);
}

$stmt = $pdo->prepare("SELECT * FROM account WHERE AccountNumber = ?");
$stmt->execute([$accNo]);
$acc = $stmt->fetch();
if (!$acc) { set_flash('error', 'Account not found.'); redirect(CASHIER_URL); }

if ($type === 'withdraw' && $acc['Balance'] < $amount) {
    set_flash('error', 'Insufficient funds. Current balance: $' . number_format($acc['Balance'],2));
    redirect(CASHIER_URL);
}

try {
    $pdo->beginTransaction();

    $delta = ($type === 'deposit') ? $amount : -$amount;
    $pdo->prepare("UPDATE account SET Balance = Balance + ? WHERE AccountNumber = ?")
        ->execute([$delta, $accNo]);

    $txType = ($type === 'deposit') ? 'Deposit' : 'Withdrawal';
    $txID   = 'TX' . strtoupper(bin2hex(random_bytes(5)));

    $pdo->prepare("
        INSERT INTO `transaction`
        (TransactionID, Date, Amount, Type, AccountNumber, CustomerID, CashierID)
        VALUES (?, NOW(), ?, ?, ?, ?, ?)
    ")->execute([$txID, $amount, $txType, $accNo, $acc['CustomerID'], $cashier]);

    // Log cashier's action
    $pdo->prepare("
        INSERT INTO cashier_accounts_update (CashierID, AccountNumber, UpdatedDate)
        VALUES (?, ?, NOW())
    ")->execute([$cashier, $accNo]);

    $pdo->commit();
    set_flash('success', ucfirst($type) . " of LKR $amount successful on $accNo.");
} catch (Exception $e) {
    $pdo->rollBack();
    set_flash('error', 'Transaction failed: ' . $e->getMessage());
}

redirect(CASHIER_URL . '?q=' . urlencode($accNo));