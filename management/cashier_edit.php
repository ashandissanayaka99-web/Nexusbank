<?php
require_once dirname(__DIR__) . '/config.php';
if (role() !== 'manager') redirect(MANAGER_URL);

$empID  = $_POST['employee_id'] ?? '';
$name   = trim($_POST['name']  ?? '');
$email  = trim($_POST['email'] ?? '');
$salary = floatval($_POST['salary'] ?? 0);
$hours  = floatval($_POST['working_hours'] ?? 0);
$years  = intval($_POST['years_experience'] ?? 0);
$branch = $_POST['branch_id'] ?? null;
if ($branch === '') $branch = null;

if (!$empID || !$name || !$email) {
    set_flash('error', 'Name and Email are required.');
    redirect(MANAGER_URL . '?tab=cashiers');
}

// Must actually be a cashier
$stmt = $pdo->prepare("SELECT 1 FROM cashier WHERE EmployeeID = ?");
$stmt->execute([$empID]);
if (!$stmt->fetch()) {
    set_flash('error', 'Employee is not a cashier.');
    redirect(MANAGER_URL . '?tab=cashiers');
}

// Email uniqueness
$stmt = $pdo->prepare("SELECT EmployeeID FROM employee WHERE Email = ? AND EmployeeID <> ?");
$stmt->execute([$email, $empID]);
if ($stmt->fetch()) {
    set_flash('error', 'Email is already used by another employee.');
    redirect(MANAGER_URL . '?tab=cashiers');
}

try {
    $stmt = $pdo->prepare("
        UPDATE employee
        SET Name = ?, Email = ?, Salary = ?, WorkingHours = ?, YearsExperience = ?, BranchID = ?
        WHERE EmployeeID = ?
    ");
    $stmt->execute([$name, $email, $salary, $hours, $years, $branch, $empID]);

    set_flash('success', "Cashier {$empID} updated successfully.");
} catch (Exception $e) {
    set_flash('error', 'Update failed: ' . $e->getMessage());
}

redirect(MANAGER_URL . '?tab=cashiers');