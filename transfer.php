<?php
require_once __DIR__ . '/config.php';
if (role() !== 'customer') redirect(CUSTOMER_URL);

$from        = $_POST['from']        ?? '';
$to          = trim($_POST['to']     ?? '');
$amount      = floatval($_POST['amount'] ?? 0);
$description = trim($_POST['description'] ?? '');
$me          = current_user()['id'];

// Validate source
$stmt = $pdo->prepare("SELECT * FROM account WHERE AccountNumber = ? AND CustomerID = ?");
$stmt->execute([$from, $me]);
$src = $stmt->fetch();

if (!$src)                     { set_flash('error', 'Invalid source account.');           redirect(CUSTOMER_URL); }
if ($to === $from)             { set_flash('error', 'Cannot transfer to same account.');  redirect(CUSTOMER_URL); }
if ($amount <= 0)              { set_flash('error', 'Amount must be greater than zero.'); redirect(CUSTOMER_URL); }
if ($src['Balance'] < $amount) { set_flash('error', 'Insufficient balance.');             redirect(CUSTOMER_URL); }

// Validate destination
$stmt = $pdo->prepare("SELECT * FROM account WHERE AccountNumber = ?");
$stmt->execute([$to]);
$dst = $stmt->fetch();
if (!$dst) { set_flash('error', 'Destination account not found.'); redirect(CUSTOMER_URL); }

// Insert into pending_transfer (no balance change yet)
try {
    $transferID = 'TR' . strtoupper(bin2hex(random_bytes(5)));

    $pdo->prepare("
        INSERT INTO pending_transfer (TransferID, FromAccount, ToAccount, Amount, Description, CustomerID)
        VALUES (?, ?, ?, ?, ?, ?)
    ")->execute([
        $transferID,
        $from,
        $to,
        $amount,
        $description ?: null,   // store NULL if empty
        $me
    ]);

    set_flash('success', "Transfer request submitted (ID: $transferID). Awaiting cashier approval.");
} catch (Exception $e) {
    set_flash('error', 'Transfer request failed: ' . $e->getMessage());
}

redirect(CUSTOMER_URL);