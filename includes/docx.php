<?php

declare(strict_types=1);

class DocxWriter
{
    public static function generatePurchaseOrder(array $order, array $items, array $supplier): string
    {
        return self::buildZip(self::buildFiles($order, $items, $supplier));
    }

    public static function streamPurchaseOrder(array $order, array $items, array $supplier): void
    {
        $filename = 'BC-' . $order['order_number'] . '.docx';
        $data = self::generatePurchaseOrder($order, $items, $supplier);
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($data));
        echo $data;
        exit;
    }

    // --- Invoice (sale) generation ---

    public static function generateInvoice(array $sale, array $items, array $customer): string
    {
        return self::buildZip(self::buildInvoiceFiles($sale, $items, $customer));
    }

    public static function streamInvoice(array $sale, array $items, array $customer): void
    {
        $filename = 'FACTURE-' . $sale['sale_number'] . '.docx';
        $data = self::generateInvoice($sale, $items, $customer);
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($data));
        echo $data;
        exit;
    }

    private static function buildInvoiceFiles(array $sale, array $items, array $customer): array
    {
        $rowXml = '';
        $total = 0;
        foreach ($items as $idx => $item) {
            $qty = (float)$item['quantity'];
            $price = (float)$item['unit_price'];
            $disc = (float)($item['discount_pct'] ?? 0);
            $subtotal = (float)($item['subtotal'] ?? ($qty * $price * (1 - $disc / 100)));
            $total += $subtotal;
            $name = self::esc($item['product_name'] ?? $item['name'] ?? 'Produit');
            $rowXml .= "<w:tr>
                <w:tc><w:p><w:r><w:t>" . ($idx + 1) . "</w:t></w:r></w:p></w:tc>
                <w:tc><w:p><w:r><w:t>{$name}</w:t></w:r></w:p></w:tc>
                <w:tc><w:p><w:r><w:t>" . number_format($qty, 2) . "</w:t></w:r></w:p></w:tc>
                <w:tc><w:p><w:r><w:t>" . number_format($price, 2) . "</w:t></w:r></w:p></w:tc>
                <w:tc><w:p><w:r><w:t>{$disc}%</w:t></w:r></w:p></w:tc>
                <w:tc><w:p><w:r><w:t>" . number_format($subtotal, 2) . "</w:t></w:r></w:p></w:tc>
            </w:tr>";
        }

        $subtotalAmount = (float)($sale['subtotal'] ?? $total);
        $discountAmount = (float)($sale['discount_amount'] ?? 0);
        $taxAmount = (float)($sale['tax_amount'] ?? 0);
        $netTotal = (float)($sale['total_amount'] ?? ($total - $discountAmount));
        $paidAmount = (float)($sale['paid_amount'] ?? $netTotal);
        $changeAmount = (float)($sale['change_amount'] ?? 0);
        $notes = self::esc($sale['notes'] ?? '');

        $date = date('d/m/Y H:i', strtotime($sale['sale_date'] ?? $sale['created_at']));
        $saleNum = self::esc($sale['sale_number']);
        $cashier = self::esc($sale['cashier'] ?? '');
        $customerName = self::esc($customer['first_name'] ?? $customer['name'] ?? 'Client libre');
        $customerPhone = self::esc($customer['phone'] ?? '');
        $paymentMethod = self::esc($sale['payment_method_name'] ?? '');

        $documentXml = <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
<w:body>
<w:p><w:pPr><w:jc w:val="center"/><w:rPr><w:b/><w:sz w:val="36"/></w:rPr></w:pPr><w:r><w:rPr><w:b/><w:sz w:val="36"/></w:rPr><w:t>FACTURE</w:t></w:r></w:p>
<w:p><w:pPr><w:jc w:val="center"/></w:pPr><w:r><w:rPr><w:i/><w:sz w:val="18"/></w:rPr><w:t>SuperMa - Système de Point de Vente</w:t></w:r></w:p>
<w:p><w:pPr><w:jc w:val="center"/><w:rPr><w:sz w:val="18"/></w:rPr></w:pPr><w:r><w:rPr><w:sz w:val="18"/></w:rPr><w:t>N° : {$saleNum}</w:t></w:r></w:p>
<w:p/>
<w:tbl>
<w:tblPr><w:tblW w:w="9000" w:type="dxa"/><w:tblBorders><w:top w:val="single" w:sz="4"/><w:bottom w:val="single" w:sz="4"/></w:tblBorders></w:tblPr>
<w:tr><w:tc><w:p><w:r><w:rPr><w:b/><w:sz w:val="20"/></w:rPr><w:t>CLIENT</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:rPr><w:b/><w:sz w:val="20"/></w:rPr><w:t>Date : {$date}</w:t></w:r></w:p></w:tc></w:tr>
<w:tr><w:tc><w:p><w:r><w:t>{$customerName}</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>Caissier : {$cashier}</w:t></w:r></w:p></w:tc></w:tr>
XML;

        if ($customerPhone) {
            $documentXml .= "<w:tr><w:tc><w:p><w:r><w:t>Tél : {$customerPhone}</w:t></w:r></w:p></w:tc><w:tc><w:p/></w:tc></w:tr>";
        }

        $documentXml .= "
<w:tr><w:tc><w:p><w:r><w:t>Paiement : {$paymentMethod}</w:t></w:r></w:p></w:tc><w:tc><w:p/></w:tc></w:tr>";

        $documentXml .= '</w:tbl><w:p/>';

        // Items table
        $documentXml .= <<<XML
<w:tbl>
<w:tblPr><w:tblW w:w="9000" w:type="dxa"/><w:tblBorders><w:top w:val="single" w:sz="4"/><w:bottom w:val="single" w:sz="4"/><w:insideH w:val="single" w:sz="4"/><w:insideV w:val="single" w:sz="4"/></w:tblBorders></w:tblPr>
<w:tr><w:tc><w:p><w:r><w:rPr><w:b/></w:rPr><w:t>#</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:rPr><w:b/></w:rPr><w:t>Produit</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:rPr><w:b/></w:rPr><w:t>Qté</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:rPr><w:b/></w:rPr><w:t>P.U.</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:rPr><w:b/></w:rPr><w:t>Rem%</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:rPr><w:b/></w:rPr><w:t>Total</w:t></w:r></w:p></w:tc></w:tr>
{$rowXml}
</w:tbl>
<w:p/>

<w:p><w:r><w:t>Sous-total : </w:t></w:r><w:r><w:rPr><w:b/></w:rPr><w:t>{$subtotalAmount} MAD</w:t></w:r></w:p>
<w:p><w:r><w:t>Remise : </w:t></w:r><w:r><w:rPr><w:b/></w:rPr><w:t>{$discountAmount} MAD</w:t></w:r></w:p>
<w:p><w:r><w:t>TVA : </w:t></w:r><w:r><w:rPr><w:b/></w:rPr><w:t>{$taxAmount} MAD</w:t></w:r></w:p>
<w:p><w:r><w:rPr><w:b/><w:sz w:val="28"/></w:rPr><w:t>TOTAL : {$netTotal} MAD</w:t></w:r></w:p>
<w:p><w:r><w:t>Payé : {$paidAmount} MAD</w:t></w:r></w:p>
<w:p><w:r><w:t>Monnaie rendue : {$changeAmount} MAD</w:t></w:r></w:p>
XML;

        if ($notes) {
            $documentXml .= "<w:p/><w:p><w:r><w:rPr><w:b/></w:rPr><w:t>Notes :</w:t></w:r></w:p><w:p><w:r><w:t>{$notes}</w:t></w:r></w:p>";
        }

        $documentXml .= '<w:p/><w:p><w:r><w:t>Signature du client : ___________________________</w:t></w:r></w:p>';

        $documentXml .= '</w:body></w:document>';

        return [
            '[Content_Types].xml' => self::contentTypesXml(),
            '_rels/.rels'         => self::rootRels(),
            'word/document.xml'   => $documentXml,
            'word/_rels/document.xml.rels' => self::docRels(),
            'word/styles.xml'     => self::stylesXml(),
            'docProps/core.xml'   => self::invoiceCoreXml($sale),
            'docProps/app.xml'    => self::invoiceAppXml(),
        ];
    }

    private static function invoiceCoreXml(array $sale): string
    {
        $title = 'Facture ' . ($sale['sale_number'] ?? '');
        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties">
<dc:title>{$title}</dc:title>
<dc:subject>Invoice</dc:subject>
<dc:creator>SuperMa POS</dc:creator>
<cp:lastModifiedBy>SuperMa POS</cp:lastModifiedBy>
</cp:coreProperties>
XML;
    }

    private static function invoiceAppXml(): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties">
<Application>SuperMa POS</Application>
<Template>Invoice</Template>
<TotalTime>0</TotalTime>
</Properties>
XML;
    }

    private static function buildFiles(array $order, array $items, array $supplier): array
    {
        $rowXml = '';
        $total = 0;
        foreach ($items as $idx => $item) {
            $qty = (float)$item['ordered_qty'];
            $price = (float)$item['unit_price'];
            $disc = (float)($item['discount_pct'] ?? 0);
            $subtotal = $qty * $price * (1 - $disc / 100);
            $total += $subtotal;
            $name = self::esc($item['product_name'] ?? $item['name'] ?? 'Produit');
            $rowXml .= "<w:tr>
                <w:tc><w:p><w:r><w:t>" . ($idx + 1) . "</w:t></w:r></w:p></w:tc>
                <w:tc><w:p><w:r><w:t>{$name}</w:t></w:r></w:p></w:tc>
                <w:tc><w:p><w:r><w:t>" . number_format($qty, 2) . "</w:t></w:r></w:p></w:tc>
                <w:tc><w:p><w:r><w:t>" . number_format($price, 2) . "</w:t></w:r></w:p></w:tc>
                <w:tc><w:p><w:r><w:t>{$disc}%</w:t></w:r></w:p></w:tc>
                <w:tc><w:p><w:r><w:t>" . number_format($subtotal, 2) . "</w:t></w:r></w:p></w:tc>
            </w:tr>";
        }

        $discountAmount = (float)($order['discount_amount'] ?? 0);
        $taxAmount = (float)($order['tax_amount'] ?? 0);
        $netTotal = (float)($order['total_amount'] ?? $total);
        $notes = self::esc($order['notes'] ?? '');

        $date = date('d/m/Y', strtotime($order['order_date']));
        $orderNum = self::esc($order['order_number']);
        $supplierName = self::esc($supplier['company_name']);
        $contact = self::esc($supplier['contact_name'] ?? '');
        $phone = self::esc($supplier['phone'] ?? '');
        $email = self::esc($supplier['email'] ?? '');

        $contactLine = '';
        if ($contact) $contactLine .= "Contact : {$contact}\n";
        if ($phone) $contactLine .= "Tél : {$phone}\n";
        if ($email) $contactLine .= "Email : {$email}\n";

        $documentXml = <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
<w:body>
<w:p><w:pPr><w:jc w:val="center"/><w:rPr><w:b/><w:sz w:val="36"/></w:rPr></w:pPr><w:r><w:rPr><w:b/><w:sz w:val="36"/></w:rPr><w:t>BON DE COMMANDE</w:t></w:r></w:p>
<w:p><w:pPr><w:jc w:val="center"/></w:pPr><w:r><w:rPr><w:i/><w:sz w:val="18"/></w:rPr><w:t>SuperMa - Système de Point de Vente</w:t></w:r></w:p>
<w:p><w:pPr><w:jc w:val="center"/><w:rPr><w:sz w:val="18"/></w:rPr></w:pPr><w:r><w:rPr><w:sz w:val="18"/></w:rPr><w:t>N° : {$orderNum}</w:t></w:r></w:p>
<w:p/>
<w:tbl>
<w:tblPr><w:tblW w:w="9000" w:type="dxa"/><w:tblBorders><w:top w:val="single" w:sz="4"/><w:bottom w:val="single" w:sz="4"/></w:tblBorders></w:tblPr>
<w:tr><w:tc><w:p><w:r><w:rPr><w:b/><w:sz w:val="20"/></w:rPr><w:t>FOURNISSEUR</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:rPr><w:b/><w:sz w:val="20"/></w:rPr><w:t>Date : {$date}</w:t></w:r></w:p></w:tc></w:tr>
<w:tr><w:tc><w:p><w:r><w:t>{$supplierName}</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>N° {$orderNum}</w:t></w:r></w:p></w:tc></w:tr>
XML;

        if ($contact || $phone || $email) {
            $contactClean = str_replace("\n", "</w:t></w:r></w:p><w:p><w:r><w:t>", trim($contactLine));
            $documentXml .= "<w:tr><w:tc><w:p><w:r><w:t>{$contactClean}</w:t></w:r></w:p></w:tc><w:tc><w:p/></w:tc></w:tr>";
        }

        $documentXml .= '</w:tbl><w:p/>';

        // Items table
        $documentXml .= <<<XML
<w:tbl>
<w:tblPr><w:tblW w:w="9000" w:type="dxa"/><w:tblBorders><w:top w:val="single" w:sz="4"/><w:bottom w:val="single" w:sz="4"/><w:insideH w:val="single" w:sz="4"/><w:insideV w:val="single" w:sz="4"/></w:tblBorders></w:tblPr>
<w:tr><w:tc><w:p><w:r><w:rPr><w:b/></w:rPr><w:t>#</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:rPr><w:b/></w:rPr><w:t>Produit</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:rPr><w:b/></w:rPr><w:t>Qté</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:rPr><w:b/></w:rPr><w:t>P.U.</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:rPr><w:b/></w:rPr><w:t>Rem%</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:rPr><w:b/></w:rPr><w:t>Total</w:t></w:r></w:p></w:tc></w:tr>
{$rowXml}
</w:tbl>
<w:p/>

<w:p><w:r><w:t>Sous-total : </w:t></w:r><w:r><w:rPr><w:b/></w:rPr><w:t>{$total} MAD</w:t></w:r></w:p>
<w:p><w:r><w:t>Remise : </w:t></w:r><w:r><w:rPr><w:b/></w:rPr><w:t>{$discountAmount} MAD</w:t></w:r></w:p>
<w:p><w:r><w:t>TVA : </w:t></w:r><w:r><w:rPr><w:b/></w:rPr><w:t>{$taxAmount} MAD</w:t></w:r></w:p>
<w:p><w:r><w:rPr><w:b/><w:sz w:val="28"/></w:rPr><w:t>TOTAL : {$netTotal} MAD</w:t></w:r></w:p>
XML;

        if ($notes) {
            $documentXml .= "<w:p/><w:p><w:r><w:rPr><w:b/></w:rPr><w:t>Notes :</w:t></w:r></w:p><w:p><w:r><w:t>{$notes}</w:t></w:r></w:p>";
        }

        $documentXml .= '<w:p/><w:p><w:r><w:t>Signature et cachet du fournisseur : ___________________________</w:t></w:r></w:p>';
        $documentXml .= '<w:p><w:r><w:t>Date et signature (acheteur) : ________________________________</w:t></w:r></w:p>';

        $documentXml .= '</w:body></w:document>';

        return [
            '[Content_Types].xml' => self::contentTypesXml(),
            '_rels/.rels'         => self::rootRels(),
            'word/document.xml'   => $documentXml,
            'word/_rels/document.xml.rels' => self::docRels(),
            'word/styles.xml'     => self::stylesXml(),
            'docProps/core.xml'   => self::coreXml($order),
            'docProps/app.xml'    => self::appXml(),
        ];
    }

    private static function contentTypesXml(): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
<Default Extension="xml" ContentType="application/xml"/>
<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>
<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>
<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>
</Types>
XML;
    }

    private static function rootRels(): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
</Relationships>
XML;
    }

    private static function docRels(): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>
XML;
    }

    private static function stylesXml(): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
<w:style w:type="paragraph" w:default="1" w:styleId="Normal">
<w:name w:val="Normal"/>
<w:pPr><w:spacing w:after="120" w:line="276" w:lineRule="auto"/></w:pPr>
<w:rPr><w:sz w:val="22"/><w:rFonts w:ascii="Arial" w:hAnsi="Arial"/></w:rPr>
</w:style>
<w:style w:type="table" w:default="1" w:styleId="Table">
<w:name w:val="Normal Table"/>
<w:tblPr><w:tblStyle w:val="Table"/><w:tblW w:w="0" w:type="auto"/></w:tblPr>
</w:style>
</w:styles>
XML;
    }

    private static function coreXml(array $order): string
    {
        $title = 'Bon de commande ' . ($order['order_number'] ?? '');
        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties">
<dc:title>{$title}</dc:title>
<dc:subject>Purchase Order</dc:subject>
<dc:creator>SuperMa POS</dc:creator>
<cp:lastModifiedBy>SuperMa POS</cp:lastModifiedBy>
</cp:coreProperties>
XML;
    }

    private static function appXml(): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties">
<Application>SuperMa POS</Application>
<Template>PurchaseOrder</Template>
<TotalTime>0</TotalTime>
</Properties>
XML;
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private static function buildZip(array $files): string
    {
        $zipData = '';
        $centralDir = '';
        $offset = 0;

        foreach ($files as $name => $content) {
            $compressed = gzdeflate($content, 9);
            $crc = crc32($content);
            $nameBytes = $name;
            $nameLen = strlen($nameBytes);

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
