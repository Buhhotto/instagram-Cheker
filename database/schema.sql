-- =====================================================================
-- متجر المنتجات الزراعية - مخطط قاعدة البيانات (MySQL 8+)
-- Agricultural E-commerce Store - Database Schema
-- Engine: InnoDB | Charset: utf8mb4 (يدعم العربية بالكامل)
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS `agri_store`
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `agri_store`;

-- ---------------------------------------------------------------------
-- 1) جدول الأدوار (Roles) - لفصل الصلاحيات عن جدول المستخدمين
-- ---------------------------------------------------------------------
CREATE TABLE `roles` (
    `id`          TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`        VARCHAR(50) NOT NULL UNIQUE,      -- admin, manager, customer
    `description` VARCHAR(255) DEFAULT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `roles` (`id`, `name`, `description`) VALUES
    (1, 'admin',    'مدير النظام - صلاحيات كاملة'),
    (2, 'manager',  'مدير مخزون / طلبات'),
    (3, 'customer', 'عميل / مشتري');

-- ---------------------------------------------------------------------
-- 2) جدول المستخدمين (Users)
-- ---------------------------------------------------------------------
CREATE TABLE `users` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `role_id`         TINYINT UNSIGNED NOT NULL DEFAULT 3,
    `full_name`       VARCHAR(150) NOT NULL,
    `email`           VARCHAR(191) NOT NULL,
    `phone`           VARCHAR(30)  DEFAULT NULL,
    `password_hash`   VARCHAR(255) NOT NULL,           -- password_hash() BCRYPT
    `remember_token`  VARCHAR(100) DEFAULT NULL,
    `is_active`       TINYINT(1) NOT NULL DEFAULT 1,
    `email_verified_at` DATETIME DEFAULT NULL,
    `failed_login_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `locked_until`    DATETIME DEFAULT NULL,           -- قفل مؤقت بعد محاولات فاشلة
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_users_email` (`email`),
    KEY `idx_users_role` (`role_id`),
    CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- عناوين الشحن المرتبطة بالمستخدم (عدة عناوين لكل عميل)
CREATE TABLE `addresses` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`     BIGINT UNSIGNED NOT NULL,
    `label`       VARCHAR(50) DEFAULT 'المنزل',
    `city`        VARCHAR(100) NOT NULL,
    `district`    VARCHAR(100) DEFAULT NULL,
    `street`      VARCHAR(255) NOT NULL,
    `postal_code` VARCHAR(20) DEFAULT NULL,
    `is_default`  TINYINT(1) NOT NULL DEFAULT 0,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_addresses_user` (`user_id`),
    CONSTRAINT `fk_addresses_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3) جدول التصنيفات (Categories) - هرمي (يدعم تصنيفات فرعية)
-- ---------------------------------------------------------------------
CREATE TABLE `categories` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `parent_id`  INT UNSIGNED DEFAULT NULL,
    `name`       VARCHAR(150) NOT NULL,
    `slug`       VARCHAR(160) NOT NULL,
    `season`     ENUM('all','winter','summer','spring','autumn') NOT NULL DEFAULT 'all',
    `image_path` VARCHAR(255) DEFAULT NULL,
    `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_categories_slug` (`slug`),
    KEY `idx_categories_parent` (`parent_id`),
    CONSTRAINT `fk_categories_parent` FOREIGN KEY (`parent_id`) REFERENCES `categories`(`id`)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4) جدول وحدات القياس (Units) - كيلو / صندوق / طن / كيس ...
-- ---------------------------------------------------------------------
CREATE TABLE `units` (
    `id`     SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`   VARCHAR(50) NOT NULL,        -- كيلوغرام، صندوق، طن، كيس، لتر
    `symbol` VARCHAR(10) NOT NULL,        -- كجم، صندوق، طن...
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_units_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `units` (`name`,`symbol`) VALUES
 ('كيلوغرام','كجم'), ('طن','طن'), ('صندوق','صندوق'), ('كيس','كيس'),
 ('لتر','لتر'), ('قطعة','قطعة'), ('حزمة','حزمة');

-- ---------------------------------------------------------------------
-- 5) جدول المنتجات (Products)
-- ---------------------------------------------------------------------
CREATE TABLE `products` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `category_id`    INT UNSIGNED NOT NULL,
    `unit_id`        SMALLINT UNSIGNED NOT NULL,
    `sku`            VARCHAR(64) NOT NULL,
    `name`           VARCHAR(200) NOT NULL,
    `slug`           VARCHAR(220) NOT NULL,
    `description`    TEXT DEFAULT NULL,
    `price`          DECIMAL(12,2) NOT NULL,        -- سعر الوحدة
    `sale_price`     DECIMAL(12,2) DEFAULT NULL,     -- سعر العرض إن وجد
    `stock_quantity` DECIMAL(12,3) NOT NULL DEFAULT 0, -- يدعم كسور (0.5 طن مثلاً)
    `min_order_qty`  DECIMAL(12,3) NOT NULL DEFAULT 1,
    `season`         ENUM('all','winter','summer','spring','autumn') NOT NULL DEFAULT 'all',
    `origin`         VARCHAR(120) DEFAULT NULL,      -- بلد/منطقة المنشأ
    `is_active`      TINYINT(1) NOT NULL DEFAULT 1,
    `is_featured`    TINYINT(1) NOT NULL DEFAULT 0,
    `views_count`    INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_products_sku` (`sku`),
    UNIQUE KEY `uq_products_slug` (`slug`),
    KEY `idx_products_category` (`category_id`),
    KEY `idx_products_unit` (`unit_id`),
    KEY `idx_products_active_featured` (`is_active`,`is_featured`),
    KEY `idx_products_season` (`season`),
    FULLTEXT KEY `ft_products_name_desc` (`name`,`description`),
    CONSTRAINT `fk_products_category` FOREIGN KEY (`category_id`) REFERENCES `categories`(`id`)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `fk_products_unit` FOREIGN KEY (`unit_id`) REFERENCES `units`(`id`)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `chk_products_price` CHECK (`price` >= 0),
    CONSTRAINT `chk_products_stock` CHECK (`stock_quantity` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- صور المنتجات (متعددة الصور لكل منتج)
CREATE TABLE `product_images` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `product_id` BIGINT UNSIGNED NOT NULL,
    `file_path`  VARCHAR(255) NOT NULL,       -- المسار المخزَّن بعد التحقق من الامتداد/النوع
    `alt_text`   VARCHAR(150) DEFAULT NULL,
    `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
    `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_product_images_product` (`product_id`),
    CONSTRAINT `fk_product_images_product` FOREIGN KEY (`product_id`) REFERENCES `products`(`id`)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- سجل تعديلات المخزون/الأسعار (تدقيق - Audit trail)
CREATE TABLE `product_stock_logs` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `product_id`  BIGINT UNSIGNED NOT NULL,
    `changed_by`  BIGINT UNSIGNED DEFAULT NULL,   -- user_id للمشرف
    `change_type` ENUM('stock_in','stock_out','price_update','adjustment') NOT NULL,
    `quantity_delta` DECIMAL(12,3) DEFAULT NULL,
    `old_price`   DECIMAL(12,2) DEFAULT NULL,
    `new_price`   DECIMAL(12,2) DEFAULT NULL,
    `note`        VARCHAR(255) DEFAULT NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_stock_logs_product` (`product_id`),
    KEY `idx_stock_logs_user` (`changed_by`),
    CONSTRAINT `fk_stock_logs_product` FOREIGN KEY (`product_id`) REFERENCES `products`(`id`)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_stock_logs_user` FOREIGN KEY (`changed_by`) REFERENCES `users`(`id`)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 6) جدول الطلبات (Orders) وتفاصيلها (Order Items)
-- ---------------------------------------------------------------------
CREATE TABLE `orders` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `order_number`     VARCHAR(30) NOT NULL,        -- رقم مرجعي فريد يعرض للعميل
    `user_id`          BIGINT UNSIGNED NOT NULL,
    `address_id`       BIGINT UNSIGNED DEFAULT NULL,
    `status`           ENUM('pending','confirmed','processing','shipped','delivered','cancelled','refunded')
                        NOT NULL DEFAULT 'pending',
    `subtotal`         DECIMAL(12,2) NOT NULL DEFAULT 0,
    `shipping_fee`     DECIMAL(12,2) NOT NULL DEFAULT 0,
    `discount_total`   DECIMAL(12,2) NOT NULL DEFAULT 0,
    `tax_total`         DECIMAL(12,2) NOT NULL DEFAULT 0,
    `grand_total`      DECIMAL(12,2) NOT NULL DEFAULT 0,
    `currency`         CHAR(3) NOT NULL DEFAULT 'SAR',
    `shipping_status`  ENUM('not_shipped','preparing','in_transit','delivered','returned')
                        NOT NULL DEFAULT 'not_shipped',
    `tracking_number`  VARCHAR(100) DEFAULT NULL,
    `customer_note`    VARCHAR(500) DEFAULT NULL,
    `admin_note`       VARCHAR(500) DEFAULT NULL,
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_orders_number` (`order_number`),
    KEY `idx_orders_user` (`user_id`),
    KEY `idx_orders_status` (`status`),
    KEY `idx_orders_created` (`created_at`),
    CONSTRAINT `fk_orders_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `fk_orders_address` FOREIGN KEY (`address_id`) REFERENCES `addresses`(`id`)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `order_items` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `order_id`     BIGINT UNSIGNED NOT NULL,
    `product_id`   BIGINT UNSIGNED NOT NULL,
    `product_name` VARCHAR(200) NOT NULL,      -- نسخ اسم المنتج وقت الشراء (Snapshot)
    `unit_name`    VARCHAR(50) NOT NULL,       -- نسخ اسم الوحدة وقت الشراء
    `unit_price`   DECIMAL(12,2) NOT NULL,     -- سعر الوحدة وقت الشراء
    `quantity`     DECIMAL(12,3) NOT NULL,
    `line_total`   DECIMAL(12,2) NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_order_items_order` (`order_id`),
    KEY `idx_order_items_product` (`product_id`),
    CONSTRAINT `fk_order_items_order` FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_order_items_product` FOREIGN KEY (`product_id`) REFERENCES `products`(`id`)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `chk_order_items_qty` CHECK (`quantity` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 7) جدول المعاملات المالية (Payments)
-- ---------------------------------------------------------------------
CREATE TABLE `payments` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `order_id`       BIGINT UNSIGNED NOT NULL,
    `method`         ENUM('cash_on_delivery','bank_transfer','credit_card','mada','stc_pay','other')
                      NOT NULL DEFAULT 'cash_on_delivery',
    `status`         ENUM('pending','authorized','paid','failed','refunded','cancelled')
                      NOT NULL DEFAULT 'pending',
    `amount`         DECIMAL(12,2) NOT NULL,
    `currency`       CHAR(3) NOT NULL DEFAULT 'SAR',
    `gateway`        VARCHAR(50) DEFAULT NULL,        -- اسم بوابة الدفع (إن وجدت)
    `gateway_txn_id` VARCHAR(150) DEFAULT NULL,       -- المعرف المرجعي من بوابة الدفع
    `paid_at`        DATETIME DEFAULT NULL,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_payments_order` (`order_id`),
    KEY `idx_payments_status` (`status`),
    UNIQUE KEY `uq_payments_gateway_txn` (`gateway`,`gateway_txn_id`),
    CONSTRAINT `fk_payments_order` FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 8) سلة التسوق (Cart) - محفوظة بقاعدة البيانات (للمستخدم المسجل)
--    سلة الزائر غير المسجل تدار عبر الجلسة (Session) في طبقة PHP
-- ---------------------------------------------------------------------
CREATE TABLE `carts` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    BIGINT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_carts_user` (`user_id`),
    CONSTRAINT `fk_carts_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `cart_items` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `cart_id`    BIGINT UNSIGNED NOT NULL,
    `product_id` BIGINT UNSIGNED NOT NULL,
    `quantity`   DECIMAL(12,3) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_cart_product` (`cart_id`,`product_id`),
    KEY `idx_cart_items_product` (`product_id`),
    CONSTRAINT `fk_cart_items_cart` FOREIGN KEY (`cart_id`) REFERENCES `carts`(`id`)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_cart_items_product` FOREIGN KEY (`product_id`) REFERENCES `products`(`id`)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `chk_cart_items_qty` CHECK (`quantity` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 9) جلسات المستخدمين المخزنة بقاعدة البيانات (اختياري - لدعم تسجيل
--    الخروج من جميع الأجهزة وتتبع الجلسات النشطة بأمان)
-- ---------------------------------------------------------------------
CREATE TABLE `user_sessions` (
    `id`            CHAR(64) NOT NULL,          -- session_id مُجزّأ (hash) لا يُخزَّن نصًا صريحًا
    `user_id`       BIGINT UNSIGNED NOT NULL,
    `ip_address`    VARBINARY(16) DEFAULT NULL,
    `user_agent`    VARCHAR(255) DEFAULT NULL,
    `last_activity` DATETIME NOT NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_sessions_user` (`user_id`),
    CONSTRAINT `fk_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 10) سجل تدقيق عام لعمليات الإدارة (Admin Audit Log)
-- ---------------------------------------------------------------------
CREATE TABLE `audit_logs` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    BIGINT UNSIGNED DEFAULT NULL,
    `action`     VARCHAR(100) NOT NULL,       -- login, login_failed, order_status_change ...
    `entity_type` VARCHAR(60) DEFAULT NULL,
    `entity_id`  BIGINT UNSIGNED DEFAULT NULL,
    `ip_address` VARBINARY(16) DEFAULT NULL,
    `details`    JSON DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_audit_user` (`user_id`),
    KEY `idx_audit_action` (`action`),
    CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
