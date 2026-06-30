<?php
$m = new mysqli('localhost', 'root', '');
$m->query("GRANT REFERENCES ON revizor_db.* TO 'revizor_rw'@'localhost'");
echo $m->error ?: "REFERENCES granted\n";
$m->query('FLUSH PRIVILEGES');
$m->close();
