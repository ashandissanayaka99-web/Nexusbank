<?php
require_once __DIR__ . '/config.php';
unset($_SESSION['user']);
redirect(CUSTOMER_URL);