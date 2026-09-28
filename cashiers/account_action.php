<?php
require_once dirname(__DIR__) . '/config.php';
if (role() !== 'cashier') redirect(CASHIER_URL);

$appId   = intval($_POST['app_id'] ?? 0);
$action  = $_POST['action'] ?? '';
$deposit = floatval($_POST['final_deposit'] ?? 0);
$cashier = current_user()['id'];

if (!$appId || !in_array($action, ['approve','reject'])) {
    set_flash('error', 'Invalid request.');
    redirect(CASHIER_URL);
}

$stmt = $pdo->prepare("SELECT * FROM pending_account_application WHERE ApplicationID = ?");
$stmt->execute([$appId]);
$app = $stmt->fetch();

if (!$app) { set_flash('error', 'Application not found.'); redirect(CASHIER_URL); }

// ---------- REJECT ----------
if ($action === 'reject') {
    $pdo->prepare("DELETE FROM pending_account_application WHERE ApplicationID = ?")
        ->execute([$appId]);
    set_flash('success', "Application for {$app['Name']} rejected.");
    redirect(CASHIER_URL);
}

// ---------- APPROVE ----------
try {
    $pdo->beginTransaction();

    // ★ 1. Sequential CustomerID (CUST001, CUST002, ...)
    $customerID = next_customer_id($pdo);

    $pdo->prepare("
        INSERT INTO customer (CustomerID, Name, Address, Email, DOB, password)
        VALUES (?, ?, ?, ?, ?, ?)
    ")->execute([
        $customerID,
        $app['Name'],
        $app['Address'],
        $app['Email'],
        $app['DOB'],
        $app['Password']
    ]);

    // ★ 2. Sequential AccountNumber (ACC1001, ACC1002, ...)
    $accNo = next_account_number($pdo);

    $pdo->prepare("
        INSERT INTO account (AccountNumber, BranchID, Balance, OpenedDate, CustomerID)
        VALUES (?, ?, ?, CURDATE(), ?)
    ")->execute([$accNo, $app['BranchID'], $deposit, $customerID]);

    // ---- Remove pending application ----
    $pdo->prepare("DELETE FROM pending_account_application WHERE ApplicationID = ?")
        ->execute([$appId]);

    $pdo->commit();
    set_flash('success', "✅ Approved {$app['Name']}. New account: $accNo (Customer ID: $customerID).");
} catch (Exception $e) {
    $pdo->rollBack();
    set_flash('error', 'Approval failed: ' . $e->getMessage());
}

redirect(CASHIER_URL);