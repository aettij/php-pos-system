<?php

declare(strict_types=1);

class PDF
{
    private int $n = 0;
    private array $offsets = [];
    private float $x = 10;
    private float $y = 10;
    private float $lMargin = 10;
    private float $pageH = 297;
    private float $pageW = 210;
    private float $lineH = 5;
    private string $fontFamily = 'Helvetica';
    private string $fontStyle = '';
    private int $fontSize = 10;
    private array $pages = [];
    private int $currentPage = -1;
    private bool $inPage = false;
    private bool $compress = false;
    private string $pageContent = '';
    private string $fontName = 'Helvetica';
    private string $buffer = '';

    public function __construct()
    {
        $this->compress = function_exists('gzcompress');
        $this->addPage();
    }

    public function addPage(): void
    {
        if ($this->inPage) {
            $this->pages[$this->currentPage] = $this->pageContent;
        }
        $this->currentPage++;
        $this->pageContent = '';
        $this->inPage = true;
        $this->x = $this->lMargin;
        $this->y = 10;
    }

    public function setFont(string $family, string $style = '', int $size = 10): void
    {
        $this->fontFamily = $family;
        $this->fontStyle = $style;
        $this->fontSize = $size;
        $this->fontName = $family . ($style === 'B' ? '-Bold' : ($style === 'I' ? '-Oblique' : ''));
    }

    public function write(float $h, string $txt): void
    {
        $w = $this->pageW - $this->lMargin - 10;
        $this->cell($w, $h, $txt);
    }

    public function cell(float $w, float $h = 0, string $txt = '', int $align = 0, int $border = 0): void
    {
        if ($w == 0) $w = $this->pageW - $this->x - 10;
        $lines = $this->wrapText($txt, $w);
        $lineH = $h > 0 ? $h : $this->lineH;

        foreach ($lines as $i => $line) {
            if ($this->y + $lineH > $this->pageH - 20) {
                $this->addPage();
            }

            if ($border && $i === 0) {
                $this->rect($this->x, $this->y, $w, $lineH * count($lines));
            }

            $textW = $this->getStringWidth($line);
            $x = $this->x;
            if ($align === 1) {
                $x = $this->x + ($w - $textW) / 2;
            } elseif ($align === 2) {
                $x = $this->x + $w - $textW;
            }

            $this->outText($x, $this->y + 4, $line);
            $this->y += $lineH;
        }
    }

    public function multiCell(float $w, float $h, string $txt, int $border = 0, int $align = 0): void
    {
        $lines = $this->wrapText($txt, $w);
        foreach ($lines as $line) {
            if ($this->y + $h > $this->pageH - 20) $this->addPage();
            $textW = $this->getStringWidth($line);
            $x = $this->x;
            if ($align === 1) $x = $this->x + ($w - $textW) / 2;
            elseif ($align === 2) $x = $this->x + $w - $textW;
            $this->outText($x, $this->y + 4, $line);
            $this->y += $h;
        }
    }

    public function rect(float $x, float $y, float $w, float $h, string $style = 'D'): void
    {
        $op = $style === 'F' ? 'f' : ($style === 'DF' ? 'B' : 'S');
        $this->out(sprintf("%.2f %.2f %.2f %.2f re %s", $x, $y, $w, $h, $op));
    }

    public function line(float $x1, float $y1, float $x2, float $y2): void
    {
        $this->out(sprintf("%.2f %.2f m %.2f %.2f l S", $x1, $y1, $x2, $y2));
    }

    public function setXY(float $x, float $y): void
    {
        $this->x = $x;
        $this->y = $y;
    }

    public function getX(): float { return $this->x; }
    public function getY(): float { return $this->y; }
    public function setX(float $x): void { $this->x = $x; }
    public function setY(float $y): void { $this->y = $y; }

    public function getStringWidth(string $s): float
    {
        $cw = [
            ' '=>250,'!'=>333,'"'=>408,'#'=>500,'$'=>500,'%'=>833,'&'=>778,'\''=>333,
            '('=>333,')'=>333,'*'=>500,'+'=>570,','=>250,'-'=>333,'.'=>250,'/'=>278,
            '0'=>500,'1'=>500,'2'=>500,'3'=>500,'4'=>500,'5'=>500,'6'=>500,'7'=>500,
            '8'=>500,'9'=>500,':'=>278,';'=>278,'<'=>564,'='=>564,'>'=>564,'?'=>444,
            '@'=>921,'A'=>722,'B'=>667,'C'=>667,'D'=>722,'E'=>611,'F'=>556,'G'=>722,
            'H'=>722,'I'=>333,'J'=>389,'K'=>722,'L'=>611,'M'=>889,'N'=>722,'O'=>722,
            'P'=>556,'Q'=>722,'R'=>667,'S'=>556,'T'=>611,'U'=>722,'V'=>722,'W'=>944,
            'X'=>722,'Y'=>722,'Z'=>611,'['=>333,'\\'=>278,']'=>333,'^'=>469,'_'=>500,
            '`'=>333,'a'=>500,'b'=>500,'c'=>444,'d'=>500,'e'=>500,'f'=>278,'g'=>500,
            'h'=>500,'i'=>278,'j'=>278,'k'=>500,'l'=>278,'m'=>778,'n'=>500,'o'=>500,
            'p'=>500,'q'=>500,'r'=>333,'s'=>389,'t'=>278,'u'=>500,'v'=>500,'w'=>722,
            'x'=>500,'y'=>500,'z'=>444,'{'=>480,'|'=>200,'}'=>480,'~'=>541,
        ];
        $w = 0;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            $w += $cw[$c] ?? 500;
        }
        return $w * ($this->fontSize / 1000);
    }

    private function wrapText(string $txt, float $w): array
    {
        $words = explode(' ', $txt);
        $lines = [];
        $current = '';
        $sep = '';
        foreach ($words as $word) {
            $test = $current . $sep . $word;
            if ($this->getStringWidth($test) > $w && $current !== '') {
                $lines[] = $current;
                $current = $word;
            } else {
                $current = $test;
            }
            $sep = ' ';
        }
        if ($current !== '') $lines[] = $current;
        return $lines;
    }

    private function outText(float $x, float $y, string $txt): void
    {
        $txt = $this->escape($txt);
        $this->out(sprintf("BT /F1 %.2f Tf %.2f %.2f Td (%s) Tj ET", $this->fontSize, $x, $this->pageH - $y, $txt));
    }

    private function escape(string $s): string
    {
        $s = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
        return $s;
    }

    private function out(string $s): void
    {
        $this->pageContent .= $s . "\n";
    }

    private function buf(string $s): void
    {
        $this->buffer .= $s . "\n";
    }

    private function newObj(): int
    {
        $this->n++;
        $this->offsets[$this->n] = strlen($this->buffer);
        $this->buf(sprintf("%d 0 obj", $this->n));
        return $this->n;
    }

    private function endObj(): void
    {
        $this->buf("endobj");
    }

    public function output(string $filename = ''): string
    {
        if ($this->inPage) {
            $this->pages[$this->currentPage] = $this->pageContent;
        }

        $this->n = 0;
        $this->offsets = [];

        // Header
        $this->buffer = "%PDF-1.4\n";

        // --- Font object ---
        $this->newObj();
        $this->buf("<< /Type /Font /Subtype /Type1 /BaseFont /" . $this->fontName . " /Encoding /WinAnsiEncoding >>");
        $bFont = $this->n;
        $this->endObj();

        // --- Font Descriptor ---
        $this->newObj();
        $this->buf("<< /Type /FontDescriptor /FontName /" . $this->fontName . " /Flags 32 /FontBBox [-50 -210 1000 770] /ItalicAngle 0 /Ascent 770 /Descent -210 /CapHeight 720 /AvgWidth 500 /MaxWidth 1000 /MissingWidth 250 >>");
        $bFontDesc = $this->n;
        $this->endObj();

        // --- Pages ---
        $pageObjs = [];
        foreach ($this->pages as $i => $content) {
            // Page content stream
            $this->newObj();
            $stream = $content;
            if ($this->compress) {
                $stream = gzcompress($stream);
            }
            $filter = $this->compress ? " /Filter /FlateDecode" : "";
            $this->buf(sprintf("<< /Length %d%s >>", strlen($stream), $filter));
            $this->buf("stream");
            $this->buf($stream);
            $this->buf("endstream");
            $this->endObj();
            $contentObj = $this->n;

            // Page object
            $this->newObj();
            $this->buf(sprintf(
                "<< /Type /Page /Parent %d 0 R /MediaBox [0 0 %.2f %.2f] /Contents %d 0 R /Resources << /Font << /F1 %d 0 R >> >> >>",
                0, $this->pageW, $this->pageH, $contentObj, $bFont
            ));
            $this->endObj();
            $pageObjs[] = $this->n;
        }

        // Page tree root
        $this->newObj();
        $kids = implode(' 0 R ', $pageObjs) . ' 0 R';
        $this->buf(sprintf("<< /Type /Pages /Kids [%s] /Count %d >>", $kids, count($pageObjs)));
        $this->endObj();
        $pagesRoot = $this->n;

        // Catalog
        $this->newObj();
        $this->buf(sprintf("<< /Type /Catalog /Pages %d 0 R >>", $pagesRoot));
        $this->endObj();
        $catalog = $this->n;

        // Cross-reference table
        $this->buf("xref");
        $this->buf(sprintf("0 %d", $this->n + 1));
        $this->buf("0000000000 65535 f ");
        $offsetPos = strlen($this->buffer);
        foreach ($this->offsets as $off) {
            $this->buf(sprintf("%010d 00000 n", $off));
        }

        // Trailer
        $this->buf("trailer");
        $this->buf(sprintf("<< /Size %d /Root %d 0 R >>", $this->n + 1, $catalog));
        $this->buf("startxref");
        $this->buf((string)$offsetPos);
        $this->buf("%%EOF");

        if ($filename) {
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($this->buffer));
            echo $this->buffer;
            exit;
        }

        return $this->buffer;
    }

    public function ln(float $h = 0): void
    {
        $this->y += $h > 0 ? $h : $this->lineH;
    }

    // Generate a purchase order PDF
    public static function generatePurchaseOrder(array $order, array $items, array $supplier): string
    {
        $pdf = new self();
        $pdf->setFont('Helvetica', 'B', 18);
        $pdf->cell(0, 12, "BON DE COMMANDE", 0, 1);
        $pdf->setFont('Helvetica', '', 8);
        $pdf->cell(0, 4, "SuperMa - Systeme de Point de Vente", 0, 1);
        $pdf->cell(0, 4, "N°: " . $order['order_number'], 0, 1);
        $pdf->ln(5);

        $pdf->setFont('Helvetica', 'B', 10);
        $pdf->cell(95, 5, "FOURNISSEUR:", 0, 0);
        $pdf->cell(95, 5, "DATE: " . date('d/m/Y', strtotime($order['order_date'])), 0, 1);
        $pdf->setFont('Helvetica', '', 10);
        $pdf->cell(95, 5, $supplier['company_name'], 0, 0);
        $pdf->cell(95, 5, "N° " . $order['order_number'], 0, 1);
        if (!empty($supplier['contact_name'])) {
            $pdf->cell(95, 5, "Contact: " . $supplier['contact_name'], 0, 0);
        }
        if (!empty($supplier['phone'])) {
            $pdf->cell(95, 5, "Tel: " . $supplier['phone'], 0, 1);
        }
        if (!empty($supplier['email'])) {
            $pdf->cell(95, 5, "Email: " . $supplier['email'], 0, 1);
        }
        $pdf->ln(5);

        $colW = [8, 80, 20, 25, 15, 25, 20];
        $headers = ['#', 'Produit', 'Qté', 'P.U. (MAD)', 'Rem.%', 'Total (MAD)', 'TVA%'];

        $pdf->setFont('Helvetica', 'B', 9);
        $x0 = $pdf->getX();
        $y0 = $pdf->getY();
        foreach ($headers as $i => $h) {
            $pdf->rect($x0 + array_sum(array_slice($colW, 0, $i)), $y0, $colW[$i], 6);
            $pdf->setXY($x0 + array_sum(array_slice($colW, 0, $i)) + 1, $y0);
            $pdf->cell($colW[$i] - 2, 6, $h, 0, 0);
        }
        $pdf->setXY($x0, $y0 + 6);

        $pdf->setFont('Helvetica', '', 9);
        $total = 0;
        foreach ($items as $idx => $item) {
            $qty = (float)$item['ordered_qty'];
            $price = (float)$item['unit_price'];
            $disc = (float)($item['discount_pct'] ?? 0);
            $subtotal = $qty * $price * (1 - $disc / 100);
            $total += $subtotal;

            $row = [
                (string)($idx + 1),
                $item['product_name'] ?? $item['name'] ?? 'Produit',
                number_format($qty, 2),
                number_format($price, 2),
                $disc . '%',
                number_format($subtotal, 2),
                ($item['tax_rate'] ?? 20) . '%',
            ];

            if ($pdf->getY() + 6 > $pdf->pageH - 25) {
                $pdf->addPage();
                $x0 = $pdf->getX();
                $y0 = $pdf->getY();
                $pdf->setFont('Helvetica', 'B', 9);
                foreach ($headers as $i => $h) {
                    $pdf->rect($x0 + array_sum(array_slice($colW, 0, $i)), $y0, $colW[$i], 6);
                    $pdf->setXY($x0 + array_sum(array_slice($colW, 0, $i)) + 1, $y0);
                    $pdf->cell($colW[$i] - 2, 6, $h, 0, 0);
                }
                $pdf->setXY($x0, $y0 + 6);
                $pdf->setFont('Helvetica', '', 9);
            }

            foreach ($row as $i => $v) {
                $x = $x0 + array_sum(array_slice($colW, 0, $i));
                $pdf->rect($x, $pdf->getY(), $colW[$i], 6);
                $pdf->setXY($x + 1, $pdf->getY());
                $pdf->cell($colW[$i] - 2, 6, $v, 0, ($i === 0 || $i === 4 || $i === 6) ? 0 : 2);
            }
            $pdf->y += 6;
        }

        $discountAmount = (float)($order['discount_amount'] ?? 0);
        $taxAmount = (float)($order['tax_amount'] ?? 0);
        $netTotal = (float)($order['total_amount'] ?? $total);

        $summaryX = $x0 + 100;
        $pdf->setXY($summaryX, $pdf->getY() + 3);
        $pdf->setFont('Helvetica', '', 9);
        $summaryLines = [
            ['Sous-total', number_format($total, 2)],
            ['Remise', number_format($discountAmount, 2)],
            ['TVA', number_format($taxAmount, 2)],
        ];
        foreach ($summaryLines as $sl) {
            $pdf->setX($summaryX);
            $pdf->cell(60, 5, $sl[0], 0, 0);
            $pdf->cell(30, 5, $sl[1] . ' MAD', 0, 2);
        }
        $pdf->setFont('Helvetica', 'B', 11);
        $pdf->setX($summaryX);
        $pdf->cell(60, 7, 'TOTAL', 0, 0);
        $pdf->cell(30, 7, number_format($netTotal, 2) . ' MAD', 0, 1);

        if (!empty($order['notes'])) {
            $pdf->ln(5);
            $pdf->setFont('Helvetica', 'B', 9);
            $pdf->cell(0, 5, 'Notes:', 0, 1);
            $pdf->setFont('Helvetica', '', 9);
            $pdf->multiCell(0, 5, $order['notes']);
        }

        $pdf->ln(10);
        $pdf->line(10, $pdf->getY(), 90, $pdf->getY());
        $pdf->setY($pdf->getY() + 1);
        $pdf->setFont('Helvetica', '', 8);
        $pdf->cell(80, 4, 'Signature et cachet du fournisseur', 0, 0);

        $pdf->line(110, $pdf->getY() - 1, 190, $pdf->getY() - 1);
        $pdf->setY($pdf->getY() + 1);
        $pdf->cell(80, 4, 'Date et signature (acheteur)', 0, 1);

        return $pdf->output();
    }

    public static function streamPurchaseOrder(array $order, array $items, array $supplier): void
    {
        $filename = 'BC-' . $order['order_number'] . '.pdf';
        self::generatePurchaseOrder($order, $items, $supplier);
    }
}
