<?php
/**
 * includes/report_export.php
 * Builds a downloadable PDF or Word (.docx) file for the employee report.
 * No extra libraries needed. Word uses PHP's built-in ZipArchive (zip extension);
 * if that extension is missing, it falls back to a .doc file.
 *
 * $d = [
 *   'title' => string, 'subtitle' => string,
 *   'details' => [[label, value], ...], 'schedule' => [line, ...],
 *   'summary' => string, 'head' => [col, ...], 'rows' => [[cell, ...], ...] (status is the LAST cell),
 *   'footer' => string,
 * ]
 */

function rx_x(string $s): string {
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function rx_n(float $v): string {
    return number_format($v, 2, '.', '');
}

/** Status colour as RGB hex (Present green, Late orange, Absent red - same as the printed report). */
function rx_status_hex(string $s): string {
    return $s === 'Late' ? 'E67E00' : ($s === 'Absent' ? 'CC0000' : ($s === 'Present' ? '1E8E3E' : '222222'));
}

/** Build a zip file in memory. $files = ['path/in/zip' => 'content']. Returns null if ZipArchive is missing. */
function rx_zip(array $files): ?string {
    if (!class_exists('ZipArchive')) return null;
    $tmp = tempnam(sys_get_temp_dir(), 'btx');
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) { @unlink($tmp); return null; }
    foreach ($files as $name => $content) $zip->addFromString($name, $content);
    $zip->close();
    $data = file_get_contents($tmp);
    @unlink($tmp);
    return $data === false ? null : $data;
}

/* ---------------------------------------------------------------- PDF */

function rx_pdf_text(string $s): string {
    $s = str_replace(["\r", "\n", "\t"], ' ', $s);
    $out = function_exists('iconv') ? @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $s) : false;
    if ($out === false) $out = preg_replace('/[^\x20-\x7E]/', '?', $s);
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], (string)$out);
}

function rx_pdf_fit(string $s, float $w, float $size): string {
    $max = (int)floor($w / ($size * 0.52));
    return strlen($s) > $max ? substr($s, 0, max(1, $max - 1)) . '.' : $s;
}

function rx_pdf(array $d): string {
    $W = 595; $H = 842; $M = 40;
    $colW = [28, 75, 105, 80, 80, 60, 80];
    $tableW = array_sum($colW);
    $pages = [];
    $buf = '';
    $y = 0;

    $txt = function (float $x, float $baseline, string $s, float $size, bool $bold = false, string $color = '0 g') use (&$buf) {
        $buf .= 'BT /' . ($bold ? 'F2' : 'F1') . ' ' . rx_n($size) . ' Tf ' . $color . ' '
              . rx_n($x) . ' ' . rx_n($baseline) . ' Td (' . rx_pdf_text($s) . ") Tj ET\n";
    };
    $newPage = function () use (&$pages, &$buf, &$y, $H, $M) {
        if ($buf !== '') $pages[] = $buf;
        $buf = '';
        $y = $H - $M;
    };
    $tableHead = function () use (&$buf, &$y, $M, $colW, $tableW, $d, $txt) {
        $buf .= '0.12 0.16 0.25 rg ' . rx_n($M) . ' ' . rx_n($y - 18) . ' ' . rx_n($tableW) . " 18 re f\n";
        $x = $M;
        foreach ($d['head'] as $i => $h) {
            $txt($x + 4, $y - 12, rx_pdf_fit((string)$h, $colW[$i] - 6, 9), 9, true, '1 g');
            $x += $colW[$i];
        }
        $y -= 18;
    };

    $newPage();
    $txt($M, $y - 14, (string)$d['title'], 16, true);
    $y -= 24;
    $txt($M, $y - 10, (string)$d['subtitle'], 10, false, '0.35 g');
    $y -= 26;

    $txt($M, $y - 11, 'Employee Details', 11, true);
    $y -= 20;
    foreach (array_chunk($d['details'], 2) as $pair) {
        foreach ($pair as $k => [$label, $value]) {
            $x = $M + $k * 260;
            $txt($x, $y - 8, strtoupper((string)$label), 7.5, false, '0.5 g');
            $txt($x, $y - 20, rx_pdf_fit((string)$value, 250, 10), 10, true);
        }
        $y -= 28;
    }
    if (!empty($d['schedule'])) {
        $txt($M, $y - 8, 'WORK SCHEDULE', 7.5, false, '0.5 g');
        $y -= 12;
        foreach ($d['schedule'] as $line) {
            $txt($M, $y - 10, rx_pdf_fit((string)$line, $W - 2 * $M, 10), 10, false);
            $y -= 13;
        }
    }
    $y -= 10;
    $txt($M, $y - 11, 'Attendance Details', 11, true);
    $y -= 16;
    $txt($M, $y - 10, (string)$d['summary'], 9, false, '0.3 g');
    $y -= 20;

    $tableHead();
    if (!$d['rows']) {
        $txt($M + 4, $y - 13, 'No attendance records for this range.', 10, false, '0.4 g');
        $y -= 18;
    }
    foreach ($d['rows'] as $row) {
        if ($y - 16 < $M + 20) {
            $newPage();
            $tableHead();
        }
        $x = $M;
        $last = count($row) - 1;
        foreach ($row as $i => $cell) {
            $color = '0.1 g';
            $bold = false;
            if ($i === $last) {
                $h = rx_status_hex((string)$cell);
                $color = rx_n(hexdec(substr($h, 0, 2)) / 255) . ' ' . rx_n(hexdec(substr($h, 2, 2)) / 255) . ' '
                       . rx_n(hexdec(substr($h, 4, 2)) / 255) . ' rg';
                $bold = true;
            }
            $txt($x + 4, $y - 11, rx_pdf_fit((string)$cell, $colW[$i] - 6, 9), 9, $bold, $color);
            $x += $colW[$i];
        }
        $buf .= '0.85 G 0.5 w ' . rx_n($M) . ' ' . rx_n($y - 16) . ' m ' . rx_n($M + $tableW) . ' ' . rx_n($y - 16) . " l S\n";
        $y -= 16;
    }
    $pages[] = $buf;

    // Footer with page numbers
    $n = count($pages);
    foreach ($pages as $i => $content) {
        $buf = '';
        $txt($M, 24, rx_pdf_fit((string)$d['footer'], 400, 8), 8, false, '0.5 g');
        $txt($W - $M - 60, 24, 'Page ' . ($i + 1) . ' of ' . $n, 8, false, '0.5 g');
        $pages[$i] = $content . $buf;
    }

    // Assemble the PDF file
    $objs = [];
    $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $kids = [];
    for ($i = 0; $i < $n; $i++) $kids[] = (5 + 2 * $i) . ' 0 R';
    $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $n . ' >>';
    $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
    $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
    foreach ($pages as $i => $content) {
        $objs[5 + 2 * $i] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . $W . ' ' . $H . '] '
                          . '/Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . (6 + 2 * $i) . ' 0 R >>';
        $objs[6 + 2 * $i] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "\nendstream";
    }
    ksort($objs);

    $out = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objs as $id => $body) {
        $offsets[$id] = strlen($out);
        $out .= $id . " 0 obj\n" . $body . "\nendobj\n";
    }
    $xref = strlen($out);
    $count = count($objs) + 1;
    $out .= "xref\n0 " . $count . "\n0000000000 65535 f \n";
    foreach ($offsets as $off) $out .= sprintf('%010d', $off) . " 00000 n \n";
    $out .= "trailer\n<< /Size " . $count . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
    return $out;
}

/* ---------------------------------------------------------------- Word */

function rx_w_par(string $text, array $o = []): string {
    $rpr = '';
    if (!empty($o['bold']))  $rpr .= '<w:b/>';
    if (!empty($o['color'])) $rpr .= '<w:color w:val="' . $o['color'] . '"/>';
    $rpr .= '<w:sz w:val="' . (int)($o['size'] ?? 21) . '"/>';
    $after = (int)($o['after'] ?? 60);
    return '<w:p><w:pPr><w:spacing w:before="0" w:after="' . $after . '"/></w:pPr>'
         . '<w:r><w:rPr>' . $rpr . '</w:rPr><w:t xml:space="preserve">' . rx_x($text) . '</w:t></w:r></w:p>';
}

function rx_docx(array $d): ?string {
    $widths = [500, 1500, 2000, 1500, 1500, 1300, 1806];
    $body  = rx_w_par((string)$d['title'], ['bold' => true, 'size' => 34, 'after' => 40]);
    $body .= rx_w_par((string)$d['subtitle'], ['color' => '555555', 'after' => 200]);
    $body .= rx_w_par('Employee Details', ['bold' => true, 'size' => 24, 'after' => 80]);
    foreach ($d['details'] as [$label, $value]) {
        $body .= rx_w_par($label . ': ' . $value, ['after' => 30]);
    }
    if (!empty($d['schedule'])) {
        $body .= rx_w_par('Work Schedule:', ['after' => 30]);
        foreach ($d['schedule'] as $line) $body .= rx_w_par('    ' . $line, ['after' => 30]);
    }
    $body .= rx_w_par('Attendance Details', ['bold' => true, 'size' => 24, 'after' => 60]);
    $body .= rx_w_par((string)$d['summary'], ['color' => '444444', 'after' => 120]);

    $border = '<w:tblBorders>';
    foreach (['top', 'left', 'bottom', 'right', 'insideH', 'insideV'] as $b) {
        $border .= '<w:' . $b . ' w:val="single" w:sz="4" w:space="0" w:color="C8CDD6"/>';
    }
    $border .= '</w:tblBorders>';
    $tbl = '<w:tbl><w:tblPr><w:tblW w:w="' . array_sum($widths) . '" w:type="dxa"/>' . $border . '</w:tblPr><w:tblGrid>';
    foreach ($widths as $w) $tbl .= '<w:gridCol w:w="' . $w . '"/>';
    $tbl .= '</w:tblGrid>';

    $tbl .= '<w:tr><w:trPr><w:tblHeader/></w:trPr>';
    foreach ($d['head'] as $i => $h) {
        $tbl .= '<w:tc><w:tcPr><w:tcW w:w="' . $widths[$i] . '" w:type="dxa"/><w:shd w:val="clear" w:color="auto" w:fill="1F2940"/></w:tcPr>'
              . rx_w_par((string)$h, ['bold' => true, 'color' => 'FFFFFF', 'size' => 19, 'after' => 0]) . '</w:tc>';
    }
    $tbl .= '</w:tr>';
    foreach ($d['rows'] as $row) {
        $last = count($row) - 1;
        $tbl .= '<w:tr>';
        foreach ($row as $i => $cell) {
            $o = ['size' => 19, 'after' => 0];
            if ($i === $last) { $o['bold'] = true; $o['color'] = rx_status_hex((string)$cell); }
            $tbl .= '<w:tc><w:tcPr><w:tcW w:w="' . $widths[$i] . '" w:type="dxa"/></w:tcPr>' . rx_w_par((string)$cell, $o) . '</w:tc>';
        }
        $tbl .= '</w:tr>';
    }
    if (!$d['rows']) {
        $tbl .= '<w:tr><w:tc><w:tcPr><w:tcW w:w="' . array_sum($widths) . '" w:type="dxa"/><w:gridSpan w:val="' . count($widths) . '"/></w:tcPr>'
              . rx_w_par('No attendance records for this range.', ['color' => '666666', 'after' => 0]) . '</w:tc></w:tr>';
    }
    $tbl .= '</w:tbl>';
    $body .= $tbl . rx_w_par('', ['after' => 0]) . rx_w_par((string)$d['footer'], ['color' => '777777', 'size' => 16, 'after' => 0]);

    $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>' . $body
        . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="900" w:right="900" w:bottom="900" w:left="900" w:header="708" w:footer="708" w:gutter="0"/></w:sectPr>'
        . '</w:body></w:document>';

    return rx_zip([
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '</Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '</Relationships>',
        'word/document.xml' => $document,
    ]);
}

/** Fallback when the zip extension is missing: a Word-readable HTML file (.doc). */
function rx_doc_html(array $d): string {
    $h = '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word" xmlns="http://www.w3.org/TR/REC-html40">'
       . '<head><meta charset="utf-8"><title>' . rx_x((string)$d['title']) . '</title></head><body style="font-family:Arial,sans-serif;font-size:11pt;">'
       . '<h2>' . rx_x((string)$d['title']) . '</h2><p>' . rx_x((string)$d['subtitle']) . '</p><h3>Employee Details</h3>';
    foreach ($d['details'] as [$label, $value]) $h .= '<p style="margin:0;"><b>' . rx_x((string)$label) . ':</b> ' . rx_x((string)$value) . '</p>';
    if (!empty($d['schedule'])) $h .= '<p style="margin:0;"><b>Work Schedule:</b> ' . rx_x(implode(' | ', $d['schedule'])) . '</p>';
    $h .= '<h3>Attendance Details</h3><p>' . rx_x((string)$d['summary']) . '</p><table border="1" cellspacing="0" cellpadding="4" style="border-collapse:collapse;"><tr>';
    foreach ($d['head'] as $c) $h .= '<th bgcolor="#1F2940"><font color="#FFFFFF">' . rx_x((string)$c) . '</font></th>';
    $h .= '</tr>';
    foreach ($d['rows'] as $row) {
        $h .= '<tr>';
        $last = count($row) - 1;
        foreach ($row as $i => $c) {
            $h .= '<td>' . ($i === $last ? '<b><font color="#' . rx_status_hex((string)$c) . '">' . rx_x((string)$c) . '</font></b>' : rx_x((string)$c)) . '</td>';
        }
        $h .= '</tr>';
    }
    return $h . '</table><p style="color:#777;font-size:8pt;">' . rx_x((string)$d['footer']) . '</p></body></html>';
}
