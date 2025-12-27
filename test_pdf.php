<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/tcpdf/tcpdf.php';

$pdf = new tcpdf();
$pdf->AddPage();
$pdf->SetFont('helvetica', '', 14);
$pdf->Write(0, 'TCPDF is now working!');
$pdf->Output('test.pdf', 'I');
