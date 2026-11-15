<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/core.php';
if (getenv('BILLING_ADMIN_PASSWORD') !== false) {
    $pass=getenv('BILLING_ADMIN_PASSWORD');
} else {
    fwrite(STDOUT,"Choose your admin password (at least 12 characters): ");
    system('stty -echo'); try { $pass=rtrim((string)fgets(STDIN),"\r\n"); }
    finally { system('stty echo'); fwrite(STDOUT,PHP_EOL); }
}
if (strlen($pass)<12 || strlen($pass)>72) { fwrite(STDERR,"Use 12–72 bytes.\n"); exit(1); }
query('INSERT OR REPLACE INTO admin(id,password) VALUES(1,?)',[password_hash($pass,PASSWORD_DEFAULT)]);
echo "Admin password saved.\n";
