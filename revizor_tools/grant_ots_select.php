<?php
$m = new mysqli('localhost', 'root', '');
$m->query("GRANT SELECT ON ots.* TO 'revizor_rw'@'localhost'");
echo $m->error ?: "revizor_rw: SELECT on ots.* granted\n";
$m->query('FLUSH PRIVILEGES');
$m->close();
