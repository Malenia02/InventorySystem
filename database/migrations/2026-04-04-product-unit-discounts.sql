ALTER TABLE products
    ADD COLUMN IF NOT EXISTS box_sale_price DECIMAL(10,2) NULL AFTER sale_price,
    ADD COLUMN IF NOT EXISTS case_sale_price DECIMAL(10,2) NULL AFTER box_sale_price;
