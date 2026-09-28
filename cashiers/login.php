<?php
require_once dirname(__DIR__) . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(CASHIER_URL);

$email    = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';

$stmt = $pdo->prepare("
    SELECT e.EmployeeID, e.Name, e.Email, e.password
    FROM employee e
    JOIN cashier c ON c.EmployeeID = e.EmployeeID
    WHERE e.Email = ?
");
$stmt->execute([$email]);
$u = $stmt->fetch();

if (!$u || $u['password'] !== $password) {
    set_flash('error', 'Invalid cashier credentials.');
    redirect(CASHIER_URL);
}

$_SESSION['user'] = [
    'id'    => $u['EmployeeID'],
    'name'  => $u['Name'],
    'email' => $u['Email'],
    'role'  => 'cashier',
];
redirect(CASHIER_URL);