<?php
// Copy to config.php (same folder, outside public/) and fill in. config.php is gitignored.
return [
    // Admin password hash. Generate with: php tools/hash-password.php
    'admin_password_hash' => '',

    // Secret token for the OBS overlay URL (?view=overlay&token=...). Generate with: php -r 'echo bin2hex(random_bytes(16)), "\n";'
    'overlay_token' => '',

    // Overlay volume, 0-100. 0 = muted (default, so videos don't clash with the radio mix).
    'overlay_volume' => 0,

    // Seconds each video shows before the queue rotates to the next one.
    'rotation_seconds' => 180,

    // Most videos allowed in the queue at once.
    'max_queue_length' => 20,

    // Most queued videos one listener can have at once.
    'per_user_limit' => 2,

    // Seconds a listener must wait between requests (0 = no wait).
    'submit_cooldown_seconds' => 60,

    // Minutes before a video that just played can be requested again (0 = any time).
    'replay_cooldown_minutes' => 60,

    // Writable folder for the JSON data files. Keep it outside public/.
    'data_dir' => __DIR__ . '/data',
];
