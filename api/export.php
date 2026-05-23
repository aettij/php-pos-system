<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/xlsx.php';

$user = Auth::requireAuth();
$db = Database::connect();

$type = $_GET['type'] ?? '';

$allowed = ['customers', 'suppliers', 'stores', 'users', 'products', 'categories', 'sales'];
if (!in_array($type, $allowed, true)) {
    jsonError('Invalid export type', 400);
}

$writer = new XlsxWriter();

switch ($type) {
    case 'customers':
        $stmt = $db->prepare('SELECT * FROM customers WHERE (store_id = :store_id OR store_id IS NULL) ORDER BY name');
        $stmt->execute([':store_id' => $user['store_id']]);
        $rows = $stmt->fetchAll();
        $writer->addSheet('Customers', [
            'id'         => 'ID',
            'name'       => 'Name',
            'email'      => 'Email',
            'phone'      => 'Phone',
            'address'    => 'Address',
            'created_at' => 'Created',
        ], $rows);
        break;

    case 'suppliers':
        $stmt = $db->prepare('SELECT * FROM suppliers WHERE (store_id = :store_id OR store_id IS NULL) ORDER BY company_name');
        $stmt->execute([':store_id' => $user['store_id']]);
        $rows = $stmt->fetchAll();
        $writer->addSheet('Suppliers', [
            'id'           => 'ID',
            'company_name' => 'Company',
            'contact_name' => 'Contact',
            'email'        => 'Email',
            'phone'        => 'Phone',
            'address'      => 'Address',
            'created_at'   => 'Created',
        ], $rows);
        break;

    case 'stores':
        $stmt = $db->prepare('SELECT * FROM stores ORDER BY name');
        $stmt->execute();
        $rows = $stmt->fetchAll();
        $writer->addSheet('Stores', [
            'id'         => 'ID',
            'name'       => 'Name',
            'address'    => 'Address',
            'phone'      => 'Phone',
            'email'      => 'Email',
            'is_active'  => 'Active',
            'created_at' => 'Created',
        ], $rows);
        break;

    case 'users':
        $stmt = $db->prepare('
            SELECT u.*, s.name AS store_name,
                   COALESCE(string_agg(r.name, \', \' ORDER BY r.name), \'\') AS role_names
            FROM users u
            LEFT JOIN stores s ON s.id = u.store_id
            LEFT JOIN user_roles ur ON ur.user_id = u.id
            LEFT JOIN roles r ON r.id = ur.role_id
            GROUP BY u.id, s.name
            ORDER BY u.username
        ');
        $stmt->execute();
        $rows = $stmt->fetchAll();
        $writer->addSheet('Users', [
            'id'           => 'ID',
            'username'     => 'Username',
            'email'        => 'Email',
            'display_name' => 'Display Name',
            'store_name'   => 'Store',
            'role_names'   => 'Roles',
            'is_active'    => 'Active',
            'created_at'   => 'Created',
        ], $rows);
        break;

    case 'products':
        $stmt = $db->prepare('
            SELECT p.*, c.name AS category_name, s.company_name AS supplier_name
            FROM products p
            LEFT JOIN categories c ON c.id = p.category_id
            LEFT JOIN suppliers s ON s.id = p.supplier_id
            WHERE (p.store_id = :store_id OR p.store_id IS NULL)
            ORDER BY p.name
        ');
        $stmt->execute([':store_id' => $user['store_id']]);
        $rows = $stmt->fetchAll();
        $writer->addSheet('Products', [
            'id'              => 'ID',
            'name'            => 'Name',
            'barcode'         => 'Barcode',
            'sku'             => 'SKU',
            'category_name'   => 'Category',
            'supplier_name'   => 'Supplier',
            'selling_price'   => 'Selling Price',
            'purchase_price'  => 'Purchase Price',
            'tax_rate'        => 'Tax Rate',
            'stock_quantity'  => 'Stock',
            'stock_min'       => 'Min Stock',
            'stock_max'       => 'Max Stock',
            'unit'            => 'Unit',
            'is_active'       => 'Active',
            'is_service'      => 'Service',
            'created_at'      => 'Created',
        ], $rows);
        break;

    case 'categories':
        $stmt = $db->prepare('SELECT * FROM categories WHERE (store_id = :store_id OR store_id IS NULL) ORDER BY name');
        $stmt->execute([':store_id' => $user['store_id']]);
        $rows = $stmt->fetchAll();
        $writer->addSheet('Categories', [
            'id'          => 'ID',
            'name'        => 'Name',
            'description' => 'Description',
            'created_at'  => 'Created',
        ], $rows);
        break;

    case 'sales':
        $stmt = $db->prepare("
            SELECT
                DATE(s.sale_date) AS sale_date,
                COUNT(*) AS sale_count,
                COALESCE(SUM(s.total_amount), 0) AS total_revenue,
                COALESCE(SUM(s.tax_amount), 0) AS total_tax,
                COUNT(CASE WHEN pm.code = 'CASH' THEN 1 END) AS cash_count,
                COALESCE(SUM(CASE WHEN pm.code = 'CASH' THEN s.total_amount ELSE 0 END), 0) AS cash_revenue,
                COUNT(CASE WHEN pm.code = 'CARD' THEN 1 END) AS card_count,
                COALESCE(SUM(CASE WHEN pm.code = 'CARD' THEN s.total_amount ELSE 0 END), 0) AS card_revenue,
                COUNT(CASE WHEN pm.code = 'MOBILE' THEN 1 END) AS mobile_count,
                COALESCE(SUM(CASE WHEN pm.code = 'MOBILE' THEN s.total_amount ELSE 0 END), 0) AS mobile_revenue,
                COUNT(CASE WHEN pm.code NOT IN ('CASH','CARD','MOBILE') THEN 1 END) AS other_count,
                COALESCE(SUM(CASE WHEN pm.code NOT IN ('CASH','CARD','MOBILE') THEN s.total_amount ELSE 0 END), 0) AS other_revenue
            FROM sales s
            LEFT JOIN payment_methods pm ON pm.id = s.payment_method_id
            WHERE (s.store_id = :store_id OR s.store_id IS NULL)
            GROUP BY DATE(s.sale_date)
            ORDER BY sale_date DESC
        ");
        $stmt->execute([':store_id' => $user['store_id']]);
        $rows = $stmt->fetchAll();
        $writer->addSheet('Sales by Day', [
            'sale_date'     => 'Date',
            'sale_count'    => 'Orders',
            'total_revenue' => 'Total Revenue',
            'total_tax'     => 'Total Tax',
            'cash_count'    => 'Cash Orders',
            'cash_revenue'  => 'Cash Revenue',
            'card_count'    => 'Card Orders',
            'card_revenue'  => 'Card Revenue',
            'mobile_count'  => 'Mobile Orders',
            'mobile_revenue' => 'Mobile Revenue',
        ], $rows);
        break;
}

$writer->output($type . '_' . date('Y-m-d') . '.xlsx');
