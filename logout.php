<?php
require_once __DIR__ . '/includes/session_boot.php';
require_once 'config/db.php';
log_activity('LOGOUT', 'User logged out from system');
session_destroy();
header("Location: index.php?page=login");
exit();
