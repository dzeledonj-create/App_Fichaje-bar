<?php
// ============================================================
//  api/export_pdf.php — Exportación PDF con TCPDF
//  Se incluye desde index.php tras verificar autenticación
//
//  Instalar TCPDF:  composer require tecnickcom/tcpdf
// ============================================================

declare(strict_types=1);

use Controllers\AdminController;

$vendorAutoload = __DIR__ . '/../../vendor/autoload.php';
if (!file_exists($vendorAutoload)) {
    header('Content-Type: text/plain; charset=utf-8');
    http_response_code(500);
    echo 'Error: TCPDF no está instalado. Ejecuta composer require tecnickcom/tcpdf en el proyecto.';
        exit;
}

require_once $vendorAutoload;

$ctrl   = new AdminController(); 
$userId = !empty($_GET['user_id']) ? (int)$_GET['user_id']  : null;
$year   = !empty($_GET['year'])    ? (int)$_GET['year']     : null;
$month  = !empty($_GET['month'])   ? (int)$_GET['month']    : null;

$records = $ctrl->getRecords($userId, $year, $month);

// ── Nombre del fichero ────────────────────────────────────────
$suffix = 'completo';
if ($year && $month) {
    $suffix = sprintf('%04d-%02d', $year, $month);
} elseif ($year) {
    $suffix = (string)$year;
}
$filename = "fichajes_{$suffix}.pdf";

// ── Meses en español ──────────────────────────────────────────
$meses = [
    1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',
    7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'
];

$periodoLabel = 'Historial completo';
if ($year && $month) {
    $periodoLabel = $meses[$month] . ' ' . $year;
} elseif ($year) {
    $periodoLabel = 'Año ' . $year;
}

// ── Crear PDF con TCPDF ───────────────────────────────────────
$pdf = new \TCPDF('L', 'mm', 'A4', true, 'UTF-8', false); // Landscape

$pdf->SetCreator('Bar Fichaje');
$pdf->SetAuthor('Sistema de Fichaje');
$pdf->SetTitle('Registro de Fichajes – ' . $periodoLabel);
$pdf->SetMargins(10, 28, 10);
$pdf->SetHeaderMargin(5);
$pdf->SetFooterMargin(10);
$pdf->SetAutoPageBreak(true, 15);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(true);

// Footer personalizado
class FichajeFooter extends \TCPDF {
    public function Footer(): void {
        $this->SetY(-12);
        $this->SetFont('helvetica', 'I', 7);
        $this->SetTextColor(120, 120, 120);
        $this->Cell(0, 5, 'Página ' . $this->getAliasNumPage() . ' / ' . $this->getAliasNbPages()
            . '  ·  Generado el ' . date('d/m/Y H:i'), 0, 0, 'C');
    }
}

$pdf = new FichajeFooter('L', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('Bar Fichaje');
$pdf->SetMargins(10, 28, 10);
$pdf->SetHeaderMargin(5);
$pdf->SetFooterMargin(12);
$pdf->SetAutoPageBreak(true, 18);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(true);
$pdf->AddPage();

// ── Cabecera del documento ────────────────────────────────────
$pdf->SetFont('helvetica', 'B', 16);
$pdf->SetTextColor(20, 20, 20);
$pdf->Cell(0, 10, 'REGISTRO DE FICHAJES', 0, 1, 'C');

$pdf->SetFont('helvetica', '', 10);
$pdf->SetTextColor(80, 80, 80);
$pdf->Cell(0, 6, 'Período: ' . $periodoLabel . '  ·  Total registros: ' . count($records), 0, 1, 'C');
$pdf->Ln(4);

// ── Cabecera de tabla ─────────────────────────────────────────
$cols = [
    ['label' => 'Nombre',         'w' => 35],
    ['label' => 'Apellidos',      'w' => 40],
    ['label' => 'DNI / NIE',      'w' => 22],
    ['label' => 'Hora Entrada',   'w' => 40],
    ['label' => 'Hora Salida',    'w' => 40],
    ['label' => 'H. Trabajadas',  'w' => 22],
    ['label' => 'Firma Entrada',  'w' => 55],
    ['label' => 'Firma Salida',   'w' => 55],
];

$pdf->SetFillColor(30, 30, 30);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetFont('helvetica', 'B', 8);
$pdf->SetLineWidth(0.1);

foreach ($cols as $col) {
    $pdf->Cell($col['w'], 8, $col['label'], 1, 0, 'C', true);
}
$pdf->Ln();

// ── Filas de datos ────────────────────────────────────────────
$pdf->SetFont('helvetica', '', 7.5);
$fillToggle = false;

foreach ($records as $r) {
    $fillColor = $fillToggle ? [245, 245, 245] : [255, 255, 255];
    $pdf->SetFillColor(...$fillColor);
    $pdf->SetTextColor(30, 30, 30);

    // Calcular altura de fila (para celdas multi-línea)
    $rowH = 7;

    $firmaEntrada = trim(
        ($r['firma_entrada_nombre'] ?? '') . ' ' .
        ($r['firma_entrada_apellidos'] ?? '')
    ) . "\n" . ($r['firma_entrada_dni'] ?? '');

    $firmaSalida = ($r['firma_salida_nombre'] ?? '-') !== '-'
        ? trim(
            ($r['firma_salida_nombre'] ?? '') . ' ' .
            ($r['firma_salida_apellidos'] ?? '')
        ) . "\n" . ($r['firma_salida_dni'] ?? '')
        : '-';

    $rowData = [
        ['val' => $r['usuario_nombre'],    'w' => 35, 'align' => 'L'],
        ['val' => $r['usuario_apellidos'], 'w' => 40, 'align' => 'L'],
        ['val' => $r['usuario_dni'],       'w' => 22, 'align' => 'C'],
        ['val' => $r['hora_entrada'],      'w' => 40, 'align' => 'C'],
        ['val' => $r['hora_salida'],       'w' => 40, 'align' => 'C'],
        ['val' => $r['horas_trabajadas'],  'w' => 22, 'align' => 'C'],
        ['val' => $firmaEntrada,           'w' => 55, 'align' => 'L'],
        ['val' => $firmaSalida,            'w' => 55, 'align' => 'L'],
    ];

    $xStart = $pdf->GetX();
    $yStart = $pdf->GetY();

    foreach ($rowData as $cell) {
        $pdf->MultiCell(
            $cell['w'], $rowH, $cell['val'],
            1, $cell['align'], true, 0,
            '', '', true, 0, false, true, $rowH, 'M'
        );
    }
    $pdf->Ln();
    $fillToggle = !$fillToggle;
}

// ── Totales por empleado (si hay filtro de período) ───────────
if ($year || $month) {
    // Calcular totales por empleado desde los registros
    $totales = [];
    foreach ($records as $r) {
        $uid = $r['usuario_id'];
        if (!isset($totales[$uid])) {
            $totales[$uid] = [
                'nombre'    => $r['usuario_nombre'] . ' ' . $r['usuario_apellidos'],
                'dni'       => $r['usuario_dni'],
                'turnos'    => 0,
                'minutos'   => 0,
            ];
        }
        $totales[$uid]['turnos']++;
        // Extraer minutos de "Xh YYm"
        if (preg_match('/(\d+)h\s+(\d+)m/', $r['horas_trabajadas'], $hm)) {
            $totales[$uid]['minutos'] += (int)$hm[1] * 60 + (int)$hm[2];
        }
    }

    if (!empty($totales)) {
        $pdf->Ln(6);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetTextColor(20, 20, 20);
        $pdf->Cell(0, 7, 'RESUMEN POR EMPLEADO — ' . strtoupper($periodoLabel), 0, 1, 'L');

        $pdf->SetFillColor(30, 30, 30);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', 'B', 8);
        foreach ([['Empleado', 100], ['DNI/NIE', 30], ['Turnos', 20], ['Total Horas', 30]] as [$lbl, $w]) {
            $pdf->Cell($w, 7, $lbl, 1, 0, 'C', true);
        }
        $pdf->Ln();

        $pdf->SetFont('helvetica', '', 8);
        $fillToggle = false;
        foreach ($totales as $t) {
            $pdf->SetFillColor($fillToggle ? 245 : 255, $fillToggle ? 245 : 255, $fillToggle ? 245 : 255);
            $pdf->SetTextColor(30, 30, 30);
            $horas = sprintf('%dh %02dm', intdiv($t['minutos'], 60), $t['minutos'] % 60);
            $pdf->Cell(100, 6, $t['nombre'], 1, 0, 'L', true);
            $pdf->Cell(30,  6, $t['dni'],    1, 0, 'C', true);
            $pdf->Cell(20,  6, (string)$t['turnos'], 1, 0, 'C', true);
            $pdf->Cell(30,  6, $horas,        1, 0, 'C', true);
            $pdf->Ln();
            $fillToggle = !$fillToggle;
        }
    }
}

// ── Emitir PDF ────────────────────────────────────────────────
$pdf->Output($filename, 'D'); // 'D' = forzar descarga