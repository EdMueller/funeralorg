<?php
declare(strict_types=1);

/**
 * Minimaler XLSX-Writer (Office Open XML) über ZipArchive.
 * Eine Tabelle, fette Kopfzeile, fixierte erste Zeile, Autofilter, Spaltenbreiten.
 * Zahlen werden als Zahlen geschrieben, alles andere als Inline-Strings.
 */
final class Xlsx
{
    public static function send(string $filename, string $sheetName, array $header, array $rows): never
    {
        $tmpDir = DATA_DIR . '/tmp';
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0750, true);
        }
        $tmp = $tmpDir . '/' . bin2hex(random_bytes(8)) . '.xlsx';
        self::write($tmp, $sheetName, $header, $rows);

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($tmp));
        header('Cache-Control: no-store');
        readfile($tmp);
        @unlink($tmp);
        exit;
    }

    public static function write(string $path, string $sheetName, array $header, array $rows): void
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('XLSX konnte nicht erstellt werden');
        }
        $sheetName = mb_substr(preg_replace('/[\[\]\*\?\/\\\\:]/', '', $sheetName) ?: 'Tabelle', 0, 31);
        $cols = count($header);

        // Spaltenbreiten grob aus Inhalt ableiten
        $widths = array_map(fn($h) => max(8, mb_strlen((string)$h) + 2), $header);
        foreach (array_slice($rows, 0, 500) as $r) {
            foreach (array_values($r) as $i => $v) {
                $widths[$i] = min(60, max($widths[$i] ?? 8, mb_strlen((string)$v) + 2));
            }
        }

        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $sheet = $x . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<cols>';
        foreach ($widths as $i => $w) {
            $sheet .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
        }
        $sheet .= '</cols><sheetData>';
        $sheet .= self::row(1, $header, 1);
        $n = 2;
        foreach ($rows as $r) {
            $sheet .= self::row($n++, array_values($r), 0);
        }
        $lastCol = self::col($cols - 1);
        $sheet .= '</sheetData><autoFilter ref="A1:' . $lastCol . max(1, $n - 1) . '"/></worksheet>';

        $zip->addFromString('[Content_Types].xml', $x
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>');
        $zip->addFromString('_rels/.rels', $x
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/workbook.xml', $x
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . self::esc($sheetName) . '" sheetId="1" r:id="rId1"/></sheets>'
            . '<definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">\''
            . self::esc(str_replace("'", "''", $sheetName)) . '\'!$A$1:$' . $lastCol . '$' . max(1, $n - 1)
            . '</definedName></definedNames></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', $x
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/styles.xml', $x
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFE3E6E1"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>');
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->close();
    }

    private static function row(int $n, array $cells, int $style): string
    {
        $out = '<row r="' . $n . '">';
        foreach ($cells as $i => $v) {
            $ref = self::col($i) . $n;
            $s = $style ? ' s="' . $style . '"' : '';
            if ((is_int($v) || is_float($v)) && !$style) {
                $out .= '<c r="' . $ref . '"' . $s . '><v>' . $v . '</v></c>';
            } elseif ($v === null || $v === '') {
                continue;
            } else {
                $out .= '<c r="' . $ref . '"' . $s . ' t="inlineStr"><is><t xml:space="preserve">'
                    . self::esc((string)$v) . '</t></is></c>';
            }
        }
        return $out . '</row>';
    }

    private static function col(int $i): string
    {
        $s = '';
        $i++;
        while ($i > 0) {
            $m = ($i - 1) % 26;
            $s = chr(65 + $m) . $s;
            $i = intdiv($i - $m, 26);
        }
        return $s;
    }

    private static function esc(string $s): string
    {
        // Steuerzeichen entfernen, die XML ungültig machen
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s) ?? '';
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
