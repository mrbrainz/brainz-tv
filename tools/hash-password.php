<?php
// Prints an admin_password_hash for config.php. Run: php tools/hash-password.php
// Reads the password without echoing it (or from stdin when piped).
$tty = stream_isatty(STDIN);
if ($tty) {
    fwrite(STDERR, 'New admin password: ');
    shell_exec('stty -echo');
}
$password = rtrim((string) fgets(STDIN), "\r\n");
if ($tty) {
    shell_exec('stty echo');
    fwrite(STDERR, "\n");
}
if (strlen($password) < 8) {
    fwrite(STDERR, "Use at least 8 characters.\n");
    exit(1);
}
echo password_hash($password, PASSWORD_DEFAULT), "\n";
