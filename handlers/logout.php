<?php
require_once __DIR__ . '/../includes/session_boot.php';
session_destroy();
header("Location: ../index.php?page=login");
exit();
?>
