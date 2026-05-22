ALTER TABLE products
    ADD COLUMN IF NOT EXISTS pieces_per_box INT NOT NULL DEFAULT 1 AFTER quantity,
    ADD COLUMN IF NOT EXISTS boxes_per_case INT NOT NULL DEFAULT 1 AFTER pieces_per_box,
    ADD COLUMN IF NOT EXISTS box_price DECIMAL(10,2) NULL AFTER price,
    ADD COLUMN IF NOT EXISTS case_price DECIMAL(10,2) NULL AFTER box_price;

ALTER TABLE sale_items
    ADD COLUMN IF NOT EXISTS unit_type ENUM('piece', 'box', 'case') NOT NULL DEFAULT 'piece' AFTER quantity,
    ADD COLUMN IF NOT EXISTS unit_multiplier INT NOT NULL DEFAULT 1 AFTER unit_type;

DROP TRIGGER IF EXISTS trg_sale_items_after_insert;
DROP TRIGGER IF EXISTS trg_sale_items_after_delete;

DELIMITER //

CREATE TRIGGER trg_sale_items_after_insert
AFTER INSERT ON sale_items
FOR EACH ROW
BEGIN
    UPDATE products
    SET quantity = quantity - (NEW.quantity * COALESCE(NEW.unit_multiplier, 1))
    WHERE product_id = NEW.product_id;
END//

CREATE TRIGGER trg_sale_items_after_delete
AFTER DELETE ON sale_items
FOR EACH ROW
BEGIN
    UPDATE products
    SET quantity = quantity + (OLD.quantity * COALESCE(OLD.unit_multiplier, 1))
    WHERE product_id = OLD.product_id;
END//

DELIMITER ;
