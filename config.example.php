<?php
/*
 * Apotheke configuration. Copy to config.php (git-ignored) and adjust.
 * Every value can also be set with an environment variable (shown on the right), which
 * wins over this file. Empty environment variables are ignored.
 */
return [
    'data_dir'     => __DIR__ . '/data',      // DATA_DIR      photos, thumbnails, sessions (and the SQLite file)
    'timezone'     => 'Europe/Athens',        // TZ            used for dates and statistics
    'app_password' => '',                     // APP_PASSWORD  empty = no login (LAN only)

    'db' => [
        'driver'      => 'sqlite',            // DB_DRIVER     sqlite | pgsql
        'sqlite_path' => '',                  // SQLITE_PATH   default: <data_dir>/catalog.sqlite

        // PostgreSQL (driver = pgsql)
        'host'     => '127.0.0.1',            // DB_HOST
        'port'     => 5432,                   // DB_PORT
        'name'     => 'apotheke',             // DB_NAME
        'user'     => 'apotheke',             // DB_USER
        'password' => '',                     // DB_PASSWORD
        'schema'   => '',                     // DB_SCHEMA     optional, created if missing
        'dsn'      => '',                     // DB_DSN        full PDO DSN, overrides host/port/name
    ],

    'uploads' => [
        'max_mb'    => 20,                    // MAX_UPLOAD_MB   (also raise upload_max_filesize/post_max_size)
        'full_max'  => 1600,                  // IMAGE_FULL_MAX  px, longest side of stored photos
        'thumb_max' => 480,                   // IMAGE_THUMB_MAX px, longest side of thumbnails
    ],

    'page_size'     => 60,                    // PAGE_SIZE      items per page
    'currency'      => 'EUR',                 // CURRENCY       shown next to item values (EUR, USD, £, …)
    'expiry_soon_days' => 30,                 // EXPIRY_SOON_DAYS  "Expiring soon" window in days (1–3650)
    'public_url'    => '',                    // PUBLIC_URL     base URL in QR labels, e.g. http://192.168.1.10:8080 (default: from the request)
    'seed_defaults' => true,                  // SEED_DEFAULTS  starter locations/categories on a new database
];
