<?php
require_once dirname(__DIR__) . '/config.php';
unset($_SESSION['user']);
redirect(CASHIER_URL);