<?php
require_once dirname(__DIR__) . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(MANAGER_URL);

$email    = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';

// Must be an employee AND in manager subtype
$stmt = $pdo->prepare("
    SELECT e.EmployeeID, e.Name, e.Email, e.password
    FROM employee e
    JOIN manager m ON m.EmployeeID = e.EmployeeID
    WHERE e.Email = ?
");
$stmt->execute([$email]);
$u = $stmt->fetch();

if (!$u || $u['password'] !== $password) {
    set_flash('error', 'Invalid manager credentials.');
    redirect(MANAGER_URL);
}

$_SESSION['user'] = [
    'id'    => $u['EmployeeID'],
    'name'  => $u['Name'],
    'email' => $u['Email'],
    'role'  => 'manager',
];
redirect(MANAGER_URL);