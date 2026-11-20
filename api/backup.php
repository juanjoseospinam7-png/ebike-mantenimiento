<?php
session_start();
require_once __DIR__ . '/../config/helpers.php';
require_admin();

$pdo = db();

header('Content-Type: application/sql; charset=utf-8');
header('Content-Disposition: attachment; filename="backup_ebike_taller_' . date('Y-m-d_H-i-s') . '.sql"');

echo "-- ====================================================================\n";
echo "-- COPIA DE SEGURIDAD DE LA BASE DE DATOS EBIKE_TALLER\n";
echo "-- Generado por: " . e($_SESSION['ebike_user']['nombre'] ?? 'Admin') . "\n";
echo "-- Fecha y Hora: " . date('Y-m-d H:i:s') . "\n";
echo "-- ====================================================================\n\n";

echo "SET FOREIGN_KEY_CHECKS = 0;\n\n";

$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

foreach ($tables as $t) {
    // Estructura
    $create = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM);
    echo "DROP TABLE IF EXISTS `$t`;\n";
    echo $create[1] . ";\n\n";

    // Datos
    $rows = $pdo->query("SELECT * FROM `$t`")->fetchAll(PDO::FETCH_ASSOC);
    if ($rows) {
        $cols = array_keys($rows[0]);
        $colList = implode('`, `', $cols);
        echo "INSERT INTO `$t` (`$colList`) VALUES\n";
        $valStrings = [];
        foreach ($rows as $row) {
            $vals = array_map(function ($val) use ($pdo) {
                if ($val === null) return 'NULL';
                return $pdo->quote((string)$val);
            }, array_values($row));
            $valStrings[] = "(" . implode(', ', $vals) . ")";
        }
        echo implode(",\n", $valStrings) . ";\n\n";
    }
}

echo "SET FOREIGN_KEY_CHECKS = 1;\n";
exit;
