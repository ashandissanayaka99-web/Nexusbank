<?php
require_once __DIR__ . '/config.php';
if (role() !== 'customer') redirect(CUSTOMER_URL);

$amount         = floatval($_POST['amount'] ?? 0);
$loanType       = $_POST['loan_type'] ?? 'Personal';
$purpose        = trim($_POST['purpose'] ?? '');
$termMonths     = intval($_POST['term_months'] ?? 12);
$occupation     = trim($_POST['occupation'] ?? '');
$monthlyIncome  = floatval($_POST['monthly_income'] ?? 0);
$existingLoans  = trim($_POST['existing_loans'] ?? 'None');
$collateral     = trim($_POST['collateral'] ?? 'None');
$contactNumber  = trim($_POST['contact_number'] ?? '');
$me             = current_user()['id'];

// Basic required fields
if ($amount <= 0 || !$purpose || !$occupation || !$contactNumber || $monthlyIncome <= 0) {
    set_flash('error', 'Please fill in all required fields correctly.');
    redirect(CUSTOMER_URL . '?tab=loans');
}

// ★ Run BOTH eligibility rules server-side
$elig = check_loan_eligibility($pdo, $me, $amount, $monthlyIncome);

if (!$elig['eligible']) {
    // Route to a proper message depending on which rule failed
    if ($elig['total_balance'] < LOAN_MIN_BALANCE) {
        set_flash('error', $elig['reason']);
    } else {
        set_flash('error', 'Monthly salary is not enough. ' . $elig['reason']);
    }
    redirect(CUSTOMER_URL . '?tab=loans');
}

// Get branch
$stmt = $pdo->prepare("SELECT BranchID FROM account WHERE CustomerID = ? LIMIT 1");
$stmt->execute([$me]);
$branchID = $stmt->fetchColumn() ?: null;

try {
    $pdo->prepare("
        INSERT INTO pending_loan_application
        (CustomerID, LoanType, Purpose, Amount, TermMonths, Occupation,
         MonthlyIncome, ExistingLoans, Collateral, ContactNumber, BranchID)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        $me, $loanType, $purpose, $amount, $termMonths, $occupation,
        $monthlyIncome, $existingLoans, $collateral, $contactNumber, $branchID
    ]);

    set_flash('success', 'Loan application submitted! Waiting for manager approval.');
} catch (Exception $e) {
    set_flash('error', 'Application failed: ' . $e->getMessage());
}

redirect(CUSTOMER_URL . '?tab=loans');