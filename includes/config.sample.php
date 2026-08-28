<?php
// -----------------------------------------------------------------------------
// SETUP: copy this file to "config.php" (same folder) and fill in your details.
//   The real config.php is NOT committed to GitHub (it holds your password).
//
//   All tables are named rec_something, so it is safe to point this at a
//   database you already use - e.g. entigy_demo.
// -----------------------------------------------------------------------------
return [
    'db' => [
        'host'    => '127.0.0.1',        // Plesk MariaDB is usually localhost
        'name'    => 'entigy_recon',     // the database that holds the rec_ tables
        'user'    => 'CHANGE_ME',        // database user
        'pass'    => 'CHANGE_ME',        // that user's password
        'charset' => 'utf8mb4',
    ],
    'app' => [
        'name' => 'Bank Reconciliation',
    ],
];
