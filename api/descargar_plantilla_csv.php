<?php
require_once '../includes/config.php';
if (!isLoggedIn()) { http_response_code(403); exit(); }

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="plantilla_bom.csv"');

$out = fopen('php://output', 'w');
// BOM UTF-8 para Excel
fputs($out, "\xEF\xBB\xBF");
fputcsv($out, ['codigo', 'descripcion', 'cantidad', 'marca', 'modelo', 'observacion']);
fputcsv($out, ['MAT-001', 'Cable 14 AWG THHN Negro', '50', 'Condumex', 'THHN-14', 'Metros']);
fputcsv($out, ['', 'Interruptor termomagnetico 20A', '3', 'Cutler-Hammer', 'CH120', '']);
fclose($out);
