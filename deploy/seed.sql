-- ============================================================
-- SuperMa - Données de démonstration
-- ============================================================

INSERT INTO categories (id, name, description, color, sort_order)
VALUES
    ('a1000000-0000-4000-8000-000000000001', 'Alimentation', 'Produits alimentaires', '#22c55e', 1),
    ('a1000000-0000-4000-8000-000000000002', 'Boissons', 'Boissons et jus', '#3b82f6', 2),
    ('a1000000-0000-4000-8000-000000000003', 'Épicerie', 'Produits d''épicerie', '#f59e0b', 3),
    ('a1000000-0000-4000-8000-000000000004', 'Hygiène', 'Produits d''hygiène', '#ec4899', 4)
ON CONFLICT DO NOTHING;

INSERT INTO suppliers (id, company_name, contact_name, email, phone, address, city, country)
VALUES
    ('b2000000-0000-4000-8000-000000000001', 'DistribStar', 'Karim Amrani', 'karim@distribstar.ma', '+212600000001', '123 Rue des Entreprises', 'Casablanca', 'Maroc'),
    ('b2000000-0000-4000-8000-000000000002', 'AgroPlus', 'Sofia El ouafi', 'sofia@agroplus.ma', '+212600000002', '45 Avenue Agricole', 'Marrakech', 'Maroc')
ON CONFLICT DO NOTHING;

INSERT INTO products (id, store_id, category_id, supplier_id, name, barcode, sku, unit, purchase_price, selling_price, tax_rate, stock_quantity, stock_min, description, is_active)
VALUES
    ('c3000000-0000-4000-8000-000000000001', (SELECT id FROM stores LIMIT 1), 'a1000000-0000-4000-8000-000000000001', 'b2000000-0000-4000-8000-000000000001', 'Café Arabica 250g', '6111111111111', 'CAF-001', 'piece', 25.00, 45.00, 20, 50, 10, 'Café pur arabica en grains 250g', TRUE),
    ('c3000000-0000-4000-8000-000000000002', (SELECT id FROM stores LIMIT 1), 'a1000000-0000-4000-8000-000000000001', 'b2000000-0000-4000-8000-000000000001', 'Huile d''olive 500ml', '6111111111112', 'HUI-001', 'piece', 35.00, 60.00, 20, 30, 5, 'Huile d''olive extra vierge 500ml', TRUE),
    ('c3000000-0000-4000-8000-000000000003', (SELECT id FROM stores LIMIT 1), 'a1000000-0000-4000-8000-000000000001', 'b2000000-0000-4000-8000-000000000001', 'Miel Naturel 350g', '6111111111113', 'MIE-001', 'piece', 40.00, 75.00, 20, 20, 5, 'Miel pur d''oranger 350g', TRUE),
    ('c3000000-0000-4000-8000-000000000004', (SELECT id FROM stores LIMIT 1), 'a1000000-0000-4000-8000-000000000002', 'b2000000-0000-4000-8000-000000000001', 'Jus d''Orange 1L', '6111111111114', 'JUS-001', 'litre', 8.00, 15.00, 20, 100, 20, 'Jus d''orange frais 100% pur jus', TRUE),
    ('c3000000-0000-4000-8000-000000000005', (SELECT id FROM stores LIMIT 1), 'a1000000-0000-4000-8000-000000000002', 'b2000000-0000-4000-8000-000000000001', 'Eau Minérale 1.5L', '6111111111115', 'EAU-001', 'litre', 3.00, 5.00, 20, 200, 50, 'Eau minérale naturelle 1.5L', TRUE),
    ('c3000000-0000-4000-8000-000000000006', (SELECT id FROM stores LIMIT 1), 'a1000000-0000-4000-8000-000000000003', 'b2000000-0000-4000-8000-000000000002', 'Couscous 1kg', '6111111111116', 'COU-001', 'kg', 10.00, 18.00, 20, 80, 15, 'Couscous de semoule de blé dur 1kg', TRUE),
    ('c3000000-0000-4000-8000-000000000007', (SELECT id FROM stores LIMIT 1), 'a1000000-0000-4000-8000-000000000003', 'b2000000-0000-4000-8000-000000000002', 'Haricots Verts 1kg', '6111111111117', 'HAR-001', 'kg', 12.00, 22.00, 20, 60, 10, 'Haricots verts extra fins surgelés', TRUE),
    ('c3000000-0000-4000-8000-000000000008', (SELECT id FROM stores LIMIT 1), 'a1000000-0000-4000-8000-000000000003', 'b2000000-0000-4000-8000-000000000002', 'Riz Basmati 1kg', '6111111111118', 'RIZ-001', 'kg', 15.00, 25.00, 20, 90, 20, 'Riz basmati extra long', TRUE),
    ('c3000000-0000-4000-8000-000000000009', (SELECT id FROM stores LIMIT 1), 'a1000000-0000-4000-8000-000000000004', 'b2000000-0000-4000-8000-000000000002', 'Savon Liquide 500ml', '6111111111119', 'SAV-001', 'piece', 7.00, 14.00, 20, 70, 15, 'Savon liquide mains pH neutre', TRUE),
    ('c3000000-0000-4000-8000-000000000010', (SELECT id FROM stores LIMIT 1), 'a1000000-0000-4000-8000-000000000004', 'b2000000-0000-4000-8000-000000000002', 'Dentifrice Menthe 100ml', '6111111111120', 'DEN-001', 'piece', 9.00, 18.00, 20, 120, 20, 'Dentifrice au fluor goût menthe', TRUE),
    ('c3000000-0000-4000-8000-000000000011', (SELECT id FROM stores LIMIT 1), 'a1000000-0000-4000-8000-000000000001', NULL, 'Beurre 250g', '6111111111121', 'BEU-001', 'piece', 15.00, 28.00, 20, 40, 10, 'Beurre de baratte doux 250g', TRUE),
    ('c3000000-0000-4000-8000-000000000012', (SELECT id FROM stores LIMIT 1), 'a1000000-0000-4000-8000-000000000001', NULL, 'Lait Demi-écrémé 1L', '6111111111122', 'LAI-001', 'litre', 6.00, 11.00, 20, 150, 30, 'Lait demi-écrémé UHT 1L', TRUE)
ON CONFLICT DO NOTHING;

INSERT INTO customers (id, store_id, first_name, last_name, email, phone, address, city, loyalty_points, total_spent)
VALUES
    ('d4000000-0000-4000-8000-000000000001', (SELECT id FROM stores LIMIT 1), 'Ahmed', 'Tazi', 'ahmed@email.ma', '+212600000010', '12 Rue de la Liberté', 'Casablanca', 150, 3200.00),
    ('d4000000-0000-4000-8000-000000000002', (SELECT id FROM stores LIMIT 1), 'Fatima', 'Benali', 'fatima@email.ma', '+212600000011', '8 Avenue Hassan II', 'Rabat', 230, 5100.00),
    ('d4000000-0000-4000-8000-000000000003', (SELECT id FROM stores LIMIT 1), 'Youssef', 'Idrissi', 'youssef@email.ma', '+212600000012', '5 Rue de la Plage', 'Tanger', 80, 1800.00),
    ('d4000000-0000-4000-8000-000000000004', (SELECT id FROM stores LIMIT 1), 'Khadija', 'Ouazzani', 'khadija@email.ma', '+212600000013', '33 Boulevard Mohammed V', 'Marrakech', 310, 7200.00),
    ('d4000000-0000-4000-8000-000000000005', (SELECT id FROM stores LIMIT 1), 'Hassan', 'Mansouri', 'hassan@email.ma', '+212600000014', '17 Rue Oued Laou', 'Fès', 45, 950.00)
ON CONFLICT DO NOTHING;
