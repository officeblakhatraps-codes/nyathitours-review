<?php
// Copy to nyathi-config.php on the server and fill in the HostAfrica database values.
// Keep nyathi-config.php private and out of version control.
return [
    'db_host' => 'localhost',
    'db_name' => 'YOUR_HOSTAFRICA_DATABASE',
    'db_user' => 'YOUR_HOSTAFRICA_DATABASE_USER',
    'db_password' => 'REPLACE_WITH_DATABASE_PASSWORD',
    // Generate a long random value for first-run account initialization.
    'installer_key' => 'REPLACE_WITH_A_RANDOM_SECRET_AT_LEAST_40_CHARACTERS',
    'base_url' => 'https://www.example.co.za',
    'mail_from' => 'bookings@example.co.za',
    // Absolute path to the website root. Uploaded media files use a media- filename prefix.
    'uploads_dir' => __DIR__,
    'max_upload_bytes' => 18874368,
];
