<?php
// Dev-only: print MySQL DDL for all migrations without a server (php artisan tinker --execute "require 'scripts/dev/mysql-ddl.php';").
config(['database.default' => 'mysql', 'database.connections.mysql.host' => '127.0.0.1', 'database.connections.mysql.port' => 3999]);
$conn = Illuminate\Support\Facades\DB::connection('mysql');
// Pretend mode never executes SQL; a stand-in PDO avoids needing a MySQL server for version probes.
$conn->setPdo(new PDO('sqlite::memory:'))->setReadPdo(new PDO('sqlite::memory:'));
$out = [];
foreach (glob(database_path('migrations/*.php')) as $file) {
    $m = require $file;
    $queries = $conn->pretend(fn () => $m->up());
    foreach ($queries as $q) {
        $out[] = $q['query'].';';
    }
}
file_put_contents(sys_get_temp_dir().'/legacy-mysql.sql', implode("\n", $out));
echo count($out)." statements\n";
