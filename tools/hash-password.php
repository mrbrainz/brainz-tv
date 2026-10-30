<?php
// Prints an admin_password_hash for config.php. Run: php tools/hash-password.php
// Hides typing where PHP may run `stty`; shared hosts often disable shell_exec, so it then
// warns and reads visibly. To keep it hidden there, pipe it in instead:
//   read -rs PW && printf '%s\n' "$PW" | php tools/hash-password.php; unset PW
$tty = stream_isatty(STDIN);
$hide = $tty && function_exists('shell_exec');
if ($tty) {
    fwrite(STDERR, $hide ? 'New admin password: ' : "New admin password (typing will be visible on this host): ");
}
if ($hide) shell_exec('stty -echo');
$password = rtrim((string) fgets(STDIN), "\r\n");
if ($hide) {
    shell_exec('stty echo');
    fwrite(STDERR, "\n");
}
if (strlen($password) < 8) {
    fwrite(STDERR, "Use at least 8 characters.\n");
    exit(1);
}
echo password_hash($password, PASSWORD_DEFAULT), "\n";
