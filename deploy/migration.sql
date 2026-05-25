-- ============================================================
-- SuperMa POS - Performance indexes
-- Run after schema creation
-- ============================================================

-- pg_trgm extension for fast ILIKE '%term%' searches
CREATE EXTENSION IF NOT EXISTS pg_trgm;

-- Sales
CREATE INDEX IF NOT EXISTS idx_sales_store_date ON sales(store_id, sale_date DESC);
CREATE INDEX IF NOT EXISTS idx_sales_status ON sales(status);
CREATE INDEX IF NOT EXISTS idx_sales_customer ON sales(customer_id);
CREATE INDEX IF NOT EXISTS idx_sale_items_sale ON sale_items(sale_id);
CREATE INDEX IF NOT EXISTS idx_sale_items_product ON sale_items(product_id);

-- Products
CREATE INDEX IF NOT EXISTS idx_products_barcode ON products(barcode) WHERE barcode IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_products_category ON products(category_id);
CREATE INDEX IF NOT EXISTS idx_products_active ON products(is_active);
CREATE INDEX IF NOT EXISTS idx_products_stock ON products(stock_quantity) WHERE is_service = FALSE;
CREATE INDEX IF NOT EXISTS idx_products_name_trgm ON products USING gin (name gin_trgm_ops);
CREATE INDEX IF NOT EXISTS idx_products_barcode_trgm ON products USING gin (barcode gin_trgm_ops) WHERE barcode IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_products_sku_trgm ON products USING gin (sku gin_trgm_ops) WHERE sku IS NOT NULL;

-- Stock movements
CREATE INDEX IF NOT EXISTS idx_stock_movements_product ON stock_movements(product_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_stock_movements_store ON stock_movements(store_id);

-- Orders
CREATE INDEX IF NOT EXISTS idx_orders_supplier ON purchase_orders(supplier_id);
CREATE INDEX IF NOT EXISTS idx_orders_status ON purchase_orders(status);
CREATE INDEX IF NOT EXISTS idx_order_items_order ON purchase_order_items(purchase_order_id);
CREATE INDEX IF NOT EXISTS idx_orders_number_trgm ON purchase_orders USING gin (order_number gin_trgm_ops);

-- Users & auth
CREATE INDEX IF NOT EXISTS idx_users_email ON users(email);
CREATE INDEX IF NOT EXISTS idx_user_roles_user ON user_roles(user_id);

-- Customers / suppliers search
CREATE INDEX IF NOT EXISTS idx_customers_name ON customers(last_name, first_name);
CREATE INDEX IF NOT EXISTS idx_customers_search_trgm ON customers USING gin (first_name gin_trgm_ops, last_name gin_trgm_ops, email gin_trgm_ops, phone gin_trgm_ops);
CREATE INDEX IF NOT EXISTS idx_suppliers_company ON suppliers(company_name);
CREATE INDEX IF NOT EXISTS idx_suppliers_search_trgm ON suppliers USING gin (company_name gin_trgm_ops, contact_name gin_trgm_ops);

-- Sales search
CREATE INDEX IF NOT EXISTS idx_sales_number_trgm ON sales USING gin (sale_number gin_trgm_ops);

-- Stock status filter indexes
CREATE INDEX IF NOT EXISTS idx_products_stock_status ON products(store_id, stock_quantity, stock_min) WHERE is_active = TRUE AND is_service = FALSE;

-- Dashboard aggregation helper index
CREATE INDEX IF NOT EXISTS idx_sales_date_amount ON sales(sale_date, total_amount) WHERE status = 'completed';
