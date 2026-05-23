<?php

declare(strict_types=1);

class XlsxWriter
{
    private array $sheets = [];

    public function addSheet(string $name, array $headers, array $rows): void
    {
        $this->sheets[] = [
            'name'    => $name,
            'headers' => $headers,
            'rows'    => $rows,
        ];
    }

    public function toZip(): string
    {
        $files = [];
        $sheetIndex = 1;
        $sheetNames = [];
        $rels = [];

        foreach ($this->sheets as $sheet) {
            $xml = $this->buildSheetXml($sheet['headers'], $sheet['rows']);
            $files["xl/worksheets/sheet{$sheetIndex}.xml"] = $xml;
            $sheetNames[] = $sheet['name'];
            $rels[] = "worksheets/sheet{$sheetIndex}.xml";
            $sheetIndex++;
        }

        $files['[Content_Types].xml'] = $this->buildContentTypesXml(count($this->sheets));
        $files['_rels/.rels'] = $this->buildRootRels();
        $files['xl/workbook.xml'] = $this->buildWorkbookXml($sheetNames);
        $files['xl/_rels/workbook.xml.rels'] = $this->buildWorkbookRels(count($this->sheets));
        $files['xl/styles.xml'] = $this->buildStylesXml();
        $files['xl/sharedStrings.xml'] = $this->buildSharedStringsXml();

        return $this->buildZip($files);
    }

    public function output(string $filename): void
    {
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($this->toZip()));
        echo $this->toZip();
        exit;
    }

    private function cdata(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function columnLetter(int $col): string
    {
        $letter = '';
        while ($col > 0) {
            $mod = ($col - 1) % 26;
            $letter = chr(65 + $mod) . $letter;
            $col = (int)(($col - $mod) / 26);
        }
        return $letter;
    }

    private function buildSheetXml(array $headers, array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        $xml .= '<sheetData>';

        $rowNum = 1;
        $xml .= '<row r="' . $rowNum . '">';
        $colIdx = 0;
        foreach ($headers as $header) {
            $colIdx++;
            $col = $this->columnLetter($colIdx);
            $xml .= '<c r="' . $col . $rowNum . '" t="inlineStr"><is><t>' . $this->cdata((string)$header) . '</t></is></c>';
        }
        $xml .= '</row>';

        $headersKeys = array_keys($headers);
        foreach ($rows as $row) {
            $rowNum++;
            $xml .= '<row r="' . $rowNum . '">';
            $colIdx = 0;
            foreach ($headersKeys as $field) {
                $colIdx++;
                $col = $this->columnLetter($colIdx);
                $value = $row[$field] ?? '';
                if (is_numeric($value)) {
                    $xml .= '<c r="' . $col . $rowNum . '" t="n"><v>' . $value . '</v></c>';
                } else {
                    $xml .= '<c r="' . $col . $rowNum . '" t="inlineStr"><is><t>' . $this->cdata((string)$value) . '</t></is></c>';
                }
            }
            $xml .= '</row>';
        }

        $xml .= '</sheetData></worksheet>';
        return $xml;
    }

    private function buildContentTypesXml(int $sheetCount): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">';
        $xml .= '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>';
        $xml .= '<Default Extension="xml" ContentType="application/xml"/>';
        $xml .= '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
        for ($i = 1; $i <= $sheetCount; $i++) {
            $xml .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        $xml .= '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        $xml .= '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>';
        $xml .= '</Types>';
        return $xml;
    }

    private function buildRootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private function buildWorkbookXml(array $sheetNames): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
        $xml .= '<sheets>';
        foreach ($sheetNames as $idx => $name) {
            $id = $idx + 1;
            $xml .= '<sheet name="' . $this->cdata($name) . '" sheetId="' . $id . '" r:id="rId' . $id . '"/>';
        }
        $xml .= '</sheets></workbook>';
        return $xml;
    }

    private function buildWorkbookRels(int $sheetCount): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        for ($i = 1; $i <= $sheetCount; $i++) {
            $xml .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
        }
        $rId = $sheetCount + 1;
        $xml .= '<Relationship Id="rId' . $rId . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        $rId2 = $rId + 1;
        $xml .= '<Relationship Id="rId' . $rId2 . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>';
        $xml .= '</Relationships>';
        return $xml;
    }

    private function buildStylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2">'
            . '<font><b/><sz val="12"/><name val="Calibri"/></font>'
            . '<font><sz val="11"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="2">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '</fills>'
            . '<borders count="1">'
            . '<border><left/><right/><top/><bottom/><diagonal/></border>'
            . '</borders>'
            . '<cellStyleXfs count="1">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>'
            . '</cellStyleXfs>'
            . '<cellXfs count="2">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" applyFont="1"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" applyFont="1"/>'
            . '</cellXfs>'
            . '</styleSheet>';
    }

    private function buildSharedStringsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="0" uniqueCount="0"/>';
    }

    private function buildZip(array $files): string
    {
        $zipData = '';
        $centralDir = '';
        $offset = 0;

        foreach ($files as $name => $content) {
            $crc = crc32($content);
            $compressed = gzcompress($content, 9);
            $compressed = substr($compressed, 2, -4); // strip gzip header/footer, keep raw deflate

            $nameBytes = $name;
            $nameLen = strlen($nameBytes);

            // Local file header
            $localHeader = pack('VvvvvvVVVvv',
                0x04034b50,
                20,
                0,
                8,
                0,
                0,
                $crc,
                strlen($compressed),
                strlen($content),
                $nameLen,
                0
            ) . $nameBytes;

            $zipData .= $localHeader . $compressed;

            // Central directory entry
            $centralDir .= pack('VvvvvvvVVVvvvvvVV',
                0x02014b50,
                20,
                20,
                0,
                8,
                0,
                0,
                $crc,
                strlen($compressed),
                strlen($content),
                $nameLen,
                0,
                0,
                0,
                0,
                0,
                $offset
            ) . $nameBytes;

            $offset += strlen($localHeader) + strlen($compressed);
        }

        $centralDirSize = strlen($centralDir);

        // End of central directory
        $eocd = pack('VvvvvVVv',
            0x06054b50,
            0,
            0,
            count($files),
            count($files),
            $centralDirSize,
            $offset,
            0
        );

        return $zipData . $centralDir . $eocd;
    }
}
