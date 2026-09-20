<?php
// Signing out. A page of its own so the link is plain, and so the log records it.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

log_out();
header('Location: login.php');
exit;
