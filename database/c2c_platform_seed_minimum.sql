-- C2C Platform Minimum Seed Data
-- Generated on 2026-05-22 13:35:09

INSERT INTO roles (role_name) VALUES
('Admin'),
('Seller'),
('Buyer'),
('Moderator')
ON DUPLICATE KEY UPDATE role_name = VALUES(role_name);

INSERT INTO categories (category_name) VALUES
('Electronics'),
('Clothing'),
('Furniture'),
('Books'),
('Other')
ON DUPLICATE KEY UPDATE category_name = VALUES(category_name);
