<?php
// -----------------------------------------------------------------------------
// SETUP: copy this file to "config.php" (same folder) and fill in your details.
//   The real config.php is NOT committed to GitHub (it holds your password).
//
//   Every table is named rec_something, so these six tables sit safely
//   alongside the Accountant Toolkit's own tables in the same database.
// -----------------------------------------------------------------------------
return [
    'db' => [
        'host'    => 'localhost',           // localhost, not 127.0.0.1 - see README
        'name'    => 'accountant_toolkit',  // shared with the Accountant Toolkit
        'user'    => 'toolkit_user',        // the Accountant Toolkit's database user
        'pass'    => 'CHANGE_ME',           // that user's password
        'charset' => 'utf8mb4',
    ],
    'app' => [
        'name' => 'Bank Reconciliation',
    ],

    // -------------------------------------------------------------------------
    // SEVERAL CLIENTS, one installation. Leave both of these out and the tool
    // works exactly as above: one database, its own users.
    //
    // 'central' is a small database holding the people and the list of clients.
    // 'clients' holds each client's database details, with a short key that the
    // Clients page refers to - a client's password is never kept in a database.
    // -------------------------------------------------------------------------
    // 'central' => [
    //     'host' => 'localhost', 'name' => 'entigy_central',
    //     'user' => 'central_user', 'pass' => 'CHANGE_ME', 'charset' => 'utf8mb4',
    // ],
    // 'clients' => [
    //     'entigy' => [
    //         'host' => 'localhost', 'name' => 'entigy_recon',
    //         'user' => 'entigy_recon_user', 'pass' => 'CHANGE_ME', 'charset' => 'utf8mb4',
    //     ],
    // ],
];
