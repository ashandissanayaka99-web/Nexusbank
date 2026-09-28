<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(CUSTOMER_URL);

$email    = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';

$stmt = $pdo->prepare("SELECT CustomerID, Name, Email, password FROM customer WHERE Email = ?");
$stmt->execute([$email]);
$c = $stmt->fetch();

if (!$c || $c['password'] !== $password) {
    set_flash('error', 'Invalid email or password.');
    redirect(CUSTOMER_URL);
}

$_SESSION['user'] = [
    'id'    => $c['CustomerID'],
    'name'  => $c['Name'],
    'email' => $c['Email'],
    'role'  => 'customer',
];
redirect(CUSTOMER_URL);