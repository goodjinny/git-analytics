<?php
declare(strict_types=1);

/**
 * Pure-PHP XLSX writer (no Composer / no PhpSpreadsheet).
 *
 * Produces a single .xlsx workbook with one sheet per table. XLSX is an OOXML
 * zip containing:
 *   - [Content_Types].xml
 *   - _rels/.rels
 *   - xl/workbook.xml
 *   - xl/_rels/workbook.xml.rels
 *   - xl/styles.xml
 *   - xl/worksheets/sheetN.xml  (one per table)
 *
 * Strings are emitted as inline strings (t="inlineStr") to avoid the
 * extra sharedStrings.xml part — simpler and still fully spec-compliant.
 *
 * Styling kept minimal:
 *   - styleId=1 → bold (used for headers / totals row)
 *
 * Requires the `zip` PHP extension.
 */
final class XlsxExporter
{
    /**
     * Write a workbook containing one sheet per supplied table.
     *
     * @param array<int, array{
     *     name:string,
     *     table:array{
     *         title:string, headers:string[], rows:array,
     *         numeric:bool[], totals:?array
     *     }
     * }> $sheets  list of [name, table]
     */
    public function write(array $sheets, string $targetPath): void
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('PHP ext-zip is required for XLSX export.');
        }
        if (empty($sheets)) {
            throw new InvalidArgumentException('XLSX export requires at least one sheet.');
        }

        $dir = dirname($targetPath);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create XLSX directory: {$dir}");
        }

        // Sanitize and uniquify sheet names (Excel: max 31 chars; no \/?*[]: )
        $usedNames = [];
        foreach ($sheets as $i => $s) {
            $sheets[$i]['name'] = $this->uniqueSheetName($s['name'], $usedNames);
        }

        if (file_exists($targetPath) && !unlink($targetPath)) {
            throw new RuntimeException("Cannot remove existing XLSX file: {$targetPath}");
        }

        $zip = new ZipArchive();
        if ($zip->open($targetPath, ZipArchive::CREATE) !== true) {
            throw new RuntimeException("Cannot create XLSX archive: {$targetPath}");
        }

        try {
            $zip->addFromString('[Content_Types].xml', $this->contentTypesXml(count($sheets)));
            $zip->addFromString('_rels/.rels', $this->rootRelsXml());
            $zip->addFromString('xl/workbook.xml', $this->workbookXml($sheets));
            $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelsXml(count($sheets)));
            $zip->addFromString('xl/styles.xml', $this->stylesXml());

            foreach ($sheets as $i => $s) {
                $zip->addFromString(
                    sprintf('xl/worksheets/sheet%d.xml', $i + 1),
                    $this->sheetXml($s['table'])
                );
            }
        } finally {
            $zip->close();
        }
    }

    // ------------------------------------------------------------------
    // OOXML part builders
    // ------------------------------------------------------------------

    private function contentTypesXml(int $sheetCount): string
    {
        $overrides = '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        for ($i = 1; $i <= $sheetCount; $i++) {
            $overrides .= sprintf(
                '<Override PartName="/xl/worksheets/sheet%d.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>',
                $i
            );
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . $overrides
            . '</Types>';
    }

    private function rootRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    /**
     * @param array<int, array{name:string, table:array}> $sheets
     */
    private function workbookXml(array $sheets): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>';

        foreach ($sheets as $i => $s) {
            $xml .= sprintf(
                '<sheet name="%s" sheetId="%d" r:id="rId%d"/>',
                $this->xmlAttr($s['name']),
                $i + 1,
                $i + 1
            );
        }

        $xml .= '</sheets></workbook>';
        return $xml;
    }

    private function workbookRelsXml(int $sheetCount): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

        for ($i = 1; $i <= $sheetCount; $i++) {
            $xml .= sprintf(
                '<Relationship Id="rId%d" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet%d.xml"/>',
                $i,
                $i
            );
        }

        $xml .= '</Relationships>';
        return $xml;
    }

    /**
     * Minimal styles: only one custom format — bold (cellXfs[1]).
     * cellXfs[0] is the default empty style.
     */
    private function stylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
            . '<borders count="1"><border/></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    /**
     * Build one worksheet XML. Layout:
     *   row 1            — title (bold)
     *   row 2            — blank
     *   row 3            — headers (bold)
     *   rows 4..(3+N)    — data
     *   row 3+N+2        — totals (bold) if present
     */
    private function sheetXml(array $table): string
    {
        $titleText = (string) ($table['title'] ?? '');
        $headers   = (array)  ($table['headers'] ?? []);
        $rows      = (array)  ($table['rows'] ?? []);
        $numeric   = (array)  ($table['numeric'] ?? []);
        $totals    = $table['totals'] ?? null;

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetData>';

        $rowNum = 1;

        // Title
        $xml   .= $this->buildRow($rowNum++, [$titleText], [false], styleId: 1);
        // Blank line
        $rowNum++;

        // Headers
        $xml .= $this->buildRow($rowNum++, $headers, array_fill(0, count($headers), false), styleId: 1);

        // Data
        foreach ($rows as $row) {
            $xml .= $this->buildRow($rowNum++, $row, $numeric);
        }

        // Totals (after a blank line)
        if (is_array($totals) && !empty($totals)) {
            $rowNum++; // blank
            $xml .= $this->buildRow($rowNum++, $totals, $numeric, styleId: 1);
        }

        $xml .= '</sheetData></worksheet>';
        return $xml;
    }

    /**
     * Build a single <row> with cells. Numeric cells are emitted as <v>;
     * everything else as inlineStr.
     *
     * @param array<int, scalar|null> $cells
     * @param array<int, bool>        $numericMask
     */
    private function buildRow(int $rowNum, array $cells, array $numericMask, int $styleId = 0): string
    {
        $xml = sprintf('<row r="%d">', $rowNum);
        $col = 0;
        foreach ($cells as $cell) {
            $col++;
            $ref      = $this->cellRef($col, $rowNum);
            $isNumber = (bool) ($numericMask[$col - 1] ?? false);

            $styleAttr = $styleId !== 0 ? sprintf(' s="%d"', $styleId) : '';

            if ($isNumber && is_numeric($cell)) {
                // Numeric cell — emit raw value
                $xml .= sprintf(
                    '<c r="%s"%s><v>%s</v></c>',
                    $ref,
                    $styleAttr,
                    $cell + 0 // normalise to int|float
                );
            } else {
                // Inline string (handles empty cell as "")
                $text = $cell === null ? '' : (string) $cell;
                if ($text === '') {
                    // Empty cell — emit as empty inlineStr to preserve column count
                    $xml .= sprintf('<c r="%s"%s t="inlineStr"><is><t></t></is></c>', $ref, $styleAttr);
                } else {
                    $xml .= sprintf(
                        '<c r="%s"%s t="inlineStr"><is><t xml:space="preserve">%s</t></is></c>',
                        $ref,
                        $styleAttr,
                        $this->xmlText($text)
                    );
                }
            }
        }
        $xml .= '</row>';
        return $xml;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Convert 1-based column index → letters (A, B, ..., Z, AA, AB, ..., ZZ, ...).
     */
    private function cellRef(int $col, int $row): string
    {
        $letters = '';
        while ($col > 0) {
            $col--;
            $letters = chr(65 + ($col % 26)) . $letters;
            $col = intdiv($col, 26);
        }
        return $letters . $row;
    }

    /**
     * Excel sheet name constraints: max 31 chars, can't contain \/?*[]:
     * Empty becomes "Sheet". Duplicates get " (2)", " (3)", ...
     */
    private function uniqueSheetName(string $name, array &$used): string
    {
        $clean = preg_replace('/[\\/\\\\?\\*\\[\\]:]/', '_', $name) ?? '';
        $clean = trim($clean);
        if ($clean === '') {
            $clean = 'Sheet';
        }
        if (mb_strlen($clean) > 31) {
            $clean = mb_substr($clean, 0, 31);
        }

        $candidate = $clean;
        $n         = 2;
        while (isset($used[mb_strtolower($candidate)])) {
            $suffix    = ' (' . $n . ')';
            $maxBase   = 31 - mb_strlen($suffix);
            $candidate = mb_substr($clean, 0, $maxBase) . $suffix;
            $n++;
        }
        $used[mb_strtolower($candidate)] = true;
        return $candidate;
    }

    private function xmlAttr(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function xmlText(string $s): string
    {
        // Strip control chars not allowed in XML 1.0 (except \t \n \r)
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s) ?? '';
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
