CREATE TABLE IF NOT EXISTS product_images (
    product_id INT UNSIGNED NOT NULL,
    position SMALLINT UNSIGNED NOT NULL,
    url VARCHAR(2048) NOT NULL,
    PRIMARY KEY (product_id, position),
    CONSTRAINT fk_product_images_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_0900_ai_ci
