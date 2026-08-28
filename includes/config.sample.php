<?php
// -----------------------------------------------------------------------------
// SETUP: copy this file to "config.php" (same folder) and fill in your details.
//   The real config.php is NOT committed to GitHub (it holds your password).
//
//   This tool has its own database (entigy_recon) rather than sharing one -
//   it holds real bank and ledger data.
// -----------------------------------------------------------------------------
return [
    'db' => [
        'host'    => '127.0.0.1',        // Plesk MariaDB is usually localhost
        'name'    => 'entigy_recon',     // the database created for this tool
        'user'    => 'CHANGE_ME',        // database user
        'pass'    => 'CHANGE_ME',        // that user's password
        'charset' => 'utf8mb4',
    ],
    'app' => [
        'name' => 'Bank Reconciliation',
    ],
];
