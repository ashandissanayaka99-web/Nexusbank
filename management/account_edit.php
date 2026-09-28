<?php
require_once dirname(__DIR__) . '/config.php';
if (role() !== 'manager') redirect(MANAGER_URL);

$customerID = $_POST['customer_id'] ?? '';
$name       = trim($_POST['name']    ?? '');
$email      = trim($_POST['email']   ?? '');
$address    = trim($_POST['address'] ?? '');

// ★ Multivalued contacts
$contacts = $_POST['contacts'] ?? [];
if (!is_array($contacts)) $contacts = [$contacts];
$contacts = array_values(array_unique(array_filter(
    array_map('trim', $contacts),
    fn($c) => $c !== ''
)));

if (!$customerID || !$name || !$email) {
    set_flash('error', 'Name and Email are required.');
    redirect(MANAGER_URL . '?tab=accounts');
}

// Email uniqueness
$stmt = $pdo->prepare("SELECT CustomerID FROM customer WHERE Email = ? AND CustomerID <> ?");
$stmt->execute([$email, $customerID]);
if ($stmt->fetch()) {
    set_flash('error', 'Email is already used by another customer.');
    redirect(MANAGER_URL . '?tab=accounts');
}

try {
    $pdo->beginTransaction();

    // 1. Update customer (Name, Email, Address only — NOT account/balance)
    $pdo->prepare("
        UPDATE customer SET Name = ?, Email = ?, Address = ?
        WHERE CustomerID = ?
    ")->execute([$name, $email, $address ?: null, $customerID]);

    // 2. Refresh the multivalued contacts: delete + re-insert
    $pdo->prepare("DELETE FROM customer_contact WHERE CustomerID = ?")->execute([$customerID]);

    if ($contacts) {
        $insert = $pdo->prepare("INSERT INTO customer_contact (CustomerID, Contact) VALUES (?, ?)");
        foreach ($contacts as $c) {
            $insert->execute([$customerID, $c]);
        }
    }

    $pdo->commit();
    set_flash('success', "Customer {$customerID} updated. " . count($contacts) . " contact(s) saved.");
} catch (Exception $e) {
    $pdo->rollBack();
    set_flash('error', 'Update failed: ' . $e->getMessage());
}

redirect(MANAGER_URL . '?tab=accounts');