<?php
// ============================================================
// NEXUS BANK - Shared config
// ============================================================
session_start();

define('BASE_URL',      '/nexusbank/');
define('CUSTOMER_URL',  BASE_URL);
define('MANAGER_URL',   BASE_URL . 'management/');
define('CASHIER_URL',   BASE_URL . 'cashiers/');

// ---------- DB CONNECTION ----------
$DB_HOST = 'localhost';
$DB_NAME = 'nexusbank';
$DB_USER = 'root';
$DB_PASS = '';

try {
    $pdo = new PDO(
        "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER, $DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    die("DB connection failed: " . $e->getMessage());
}

// ---------- HELPERS ----------
function redirect($url) { header("Location: $url"); exit; }

function is_logged_in() { return isset($_SESSION['user']); }
function current_user() { return $_SESSION['user'] ?? null; }
function role()         { return $_SESSION['user']['role'] ?? null; }

// Which portal is this file in?
function portal() {
    $path = $_SERVER['PHP_SELF'];
    if (strpos($path, '/management/') !== false) return 'manager';
    if (strpos($path, '/cashiers/')   !== false) return 'cashier';
    return 'customer';
}

// Force the current portal to match the logged-in role.
// If mismatch -> log out and let the user log in fresh for this portal.
function enforce_portal_role() {
    if (!is_logged_in()) return;

    $p = portal();       // portal of the current file
    $r = role();         // role of the logged-in user

    if ($p === $r) return;   // everything matches, proceed

    // Mismatch: clear session, show a friendly note, reload this page
    unset($_SESSION['user']);

    $label = ucfirst($p);    // "Customer" / "Manager" / "Cashier"
    set_flash('error', "You were logged in as " . ucfirst($r) . ". Please log in here as $label.");

    redirect($_SERVER['PHP_SELF']);
}

// ---------- FLASH MESSAGES ----------
function set_flash($type, $msg) { $_SESSION['flash'][$type] = $msg; }
function show_flash() {
    if (empty($_SESSION['flash'])) return;
    foreach ($_SESSION['flash'] as $type => $msg) {
        $cls = $type === 'success' ? 'alert-success' : 'alert-error';
        echo '<div class="alert ' . $cls . '">' . htmlspecialchars($msg) . '</div>';
    }
    unset($_SESSION['flash']);
}

// ============================================================
// ★ SEQUENTIAL ID GENERATORS
// ============================================================

/**
 * Returns the next available CustomerID in the form CUST001, CUST002, ...
 * Ignores legacy IDs like "CUS007FD2" — only CUST<digits> counts.
 */
function next_customer_id(PDO $pdo): string {
    $stmt = $pdo->query("
        SELECT CustomerID
        FROM customer
        WHERE CustomerID REGEXP '^CUST[0-9]+$'
        ORDER BY CAST(SUBSTRING(CustomerID, 5) AS UNSIGNED) DESC
        LIMIT 1
    ");
    $last = $stmt->fetchColumn();

    if (!$last) return 'CUST001';

    $num = intval(substr($last, 4)); // strip 'CUST'
    return 'CUST' . str_pad($num + 1, 3, '0', STR_PAD_LEFT);
}

// ============================================================
// ★ LOAN ELIGIBILITY RULES
// ============================================================

// Rule 1 — Minimum total balance required to unlock the loan section
define('LOAN_MIN_BALANCE', 50000);

// Rule 2 — Requested amount cannot exceed this multiple of monthly income
define('LOAN_MAX_SALARY_MULTIPLIER', 4);

/**
 * Check if a customer is eligible to apply for a loan.
 *
 * @param PDO    $pdo            DB connection
 * @param string $customerID     Customer ID
 * @param float  $requestedAmount Requested loan amount (0 = just checking the balance gate)
 * @param float  $monthlyIncome   Monthly income (0 = skip the salary rule)
 *
 * @return array [
 *     'eligible'       => bool,
 *     'reason'         => string,  // message if not eligible
 *     'total_balance'  => float,
 *     'max_by_salary'  => float,   // 4 × monthly income
 * ]
 */
function check_loan_eligibility(PDO $pdo, string $customerID, float $requestedAmount = 0, float $monthlyIncome = 0): array {
    // Total balance across all accounts
    $stmt = $pdo->prepare("SELECT IFNULL(SUM(Balance),0) FROM account WHERE CustomerID = ?");
    $stmt->execute([$customerID]);
    $total = floatval($stmt->fetchColumn());

    $maxBySalary = $monthlyIncome * LOAN_MAX_SALARY_MULTIPLIER;

    // Rule 1 — minimum total balance
    if ($total < LOAN_MIN_BALANCE) {
        return [
            'eligible'      => false,
            'reason'        => 'Your total account balance is LKR ' . number_format($total, 2) .
                               '. You need at least LKR ' . number_format(LOAN_MIN_BALANCE, 2) .
                               ' to apply for a loan.',
            'total_balance' => $total,
            'max_by_salary' => $maxBySalary,
        ];
    }

    // Rule 2 — requested amount cannot exceed 4× monthly income (only checked if amount + income given)
    if ($requestedAmount > 0 && $monthlyIncome > 0 && $requestedAmount > $maxBySalary) {
        return [
            'eligible'      => false,
            'reason'        => 'Your monthly salary is not enough. Based on a monthly income of LKR ' .
                               number_format($monthlyIncome, 2) . ', the maximum loan you can request is LKR ' .
                               number_format($maxBySalary, 2) . ' (4× your monthly income).',
            'total_balance' => $total,
            'max_by_salary' => $maxBySalary,
        ];
    }

    return [
        'eligible'      => true,
        'reason'        => '',
        'total_balance' => $total,
        'max_by_salary' => $maxBySalary,
    ];
}




/**
 * Returns the next available AccountNumber in the form ACC1001, ACC1002, ...
 * Ignores legacy IDs like "ACC-1001" — only ACC<digits> counts.
 */
function next_account_number(PDO $pdo): string {
    $stmt = $pdo->query("
        SELECT AccountNumber
        FROM account
        WHERE AccountNumber REGEXP '^ACC[0-9]+$'
        ORDER BY CAST(SUBSTRING(AccountNumber, 4) AS UNSIGNED) DESC
        LIMIT 1
    ");
    $last = $stmt->fetchColumn();

    if (!$last) return 'ACC1001';

    $num = intval(substr($last, 3)); // strip 'ACC'
    return 'ACC' . ($num + 1);
}