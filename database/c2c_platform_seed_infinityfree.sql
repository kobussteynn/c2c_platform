-- C2C Platform Seed Data (InfinityFree)
-- Core lookup rows required after schema import.

INSERT INTO roles (role_id, role_name) VALUES
(1, 'Admin'),
(2, 'Seller'),
(3, 'Buyer'),
(4, 'Moderator')
ON DUPLICATE KEY UPDATE role_name = VALUES(role_name);

INSERT INTO categories (category_id, category_name) VALUES
(1, 'Electronics'),
(2, 'Clothing'),
(3, 'Furniture'),
(4, 'Books'),
(5, 'Other')
ON DUPLICATE KEY UPDATE category_name = VALUES(category_name);
