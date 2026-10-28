-- 1. SYSTEM STATUS & CONFIGURATION TABLES
CREATE TABLE roles (
    id SERIAL PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    slug VARCHAR(50) UNIQUE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE order_statuses (
    id SERIAL PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    slug VARCHAR(50) UNIQUE NOT NULL,
    badge_color VARCHAR(20) DEFAULT '#3B82F6',
    sort_order INT DEFAULT 0
);

CREATE TABLE payment_statuses (
    id SERIAL PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    slug VARCHAR(50) UNIQUE NOT NULL,
    badge_color VARCHAR(20) DEFAULT '#6B7280',
    is_terminal BOOLEAN DEFAULT FALSE
);

CREATE TABLE fulfillment_statuses (
    id SERIAL PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    slug VARCHAR(50) UNIQUE NOT NULL,
    badge_color VARCHAR(20) DEFAULT '#10B981',
    sort_order INT DEFAULT 0
);

CREATE TABLE sales_channels (
    id SERIAL PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    slug VARCHAR(50) UNIQUE NOT NULL, -- 'retail', 'wholesale', 'both'
    is_active BOOLEAN DEFAULT TRUE
);

CREATE TABLE units (
    id SERIAL PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    symbol VARCHAR(10) NOT NULL
);

-- 2. USERS, SELLERS & CUSTOMER ACCOUNTS
CREATE TABLE users (
    id BIGSERIAL PRIMARY KEY,
    role_id INT NOT NULL REFERENCES roles(id),
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    phone VARCHAR(32) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE seller_profiles (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT UNIQUE NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    store_name VARCHAR(255) NOT NULL,
    store_slug VARCHAR(255) UNIQUE NOT NULL,
    trade_license_number VARCHAR(128) NULL,
    tax_id_number VARCHAR(128) NULL,
    commission_rate_percentage DECIMAL(5,2) DEFAULT 10.00,
    is_approved BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE customer_addresses (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    address_title VARCHAR(50),
    recipient_name VARCHAR(255) NOT NULL,
    recipient_phone VARCHAR(32) NOT NULL,
    street_address TEXT NOT NULL,
    city VARCHAR(100) NOT NULL,
    postal_code VARCHAR(20) NOT NULL,
    is_default BOOLEAN DEFAULT FALSE
);

-- 3. DYNAMIC CATEGORIES, BRANDS & ATTRIBUTES
CREATE TABLE categories (
    id BIGSERIAL PRIMARY KEY,
    parent_id BIGINT NULL REFERENCES categories(id) ON DELETE SET NULL,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(255) UNIQUE NOT NULL,
    icon_url TEXT NULL,
    banner_url TEXT NULL,
    sort_order INT DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE
);

CREATE TABLE brands (
    id BIGSERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(255) UNIQUE NOT NULL,
    logo_url TEXT NULL,
    is_active BOOLEAN DEFAULT TRUE
);

CREATE TABLE attributes (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) UNIQUE NOT NULL
);

CREATE TABLE attribute_values (
    id SERIAL PRIMARY KEY,
    attribute_id INT NOT NULL REFERENCES attributes(id) ON DELETE CASCADE,
    value VARCHAR(255) NOT NULL,
    color_code VARCHAR(20) NULL,
    UNIQUE(attribute_id, value)
);

-- 4. PRODUCTS, VARIATIONS & TIER PRICING
CREATE TABLE products (
    id BIGSERIAL PRIMARY KEY,
    seller_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    category_id BIGINT NOT NULL REFERENCES categories(id),
    brand_id BIGINT NULL REFERENCES brands(id) ON DELETE SET NULL,
    sales_channel_id INT NOT NULL REFERENCES sales_channels(id),
    unit_id INT NOT NULL REFERENCES units(id),
    title VARCHAR(255) NOT NULL,
    slug VARCHAR(255) UNIQUE NOT NULL,
    short_description TEXT,
    full_description TEXT,
    buy_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    has_variations BOOLEAN DEFAULT FALSE,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE product_images (
    id BIGSERIAL PRIMARY KEY,
    product_id BIGINT NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    image_url TEXT NOT NULL,
    is_primary BOOLEAN DEFAULT FALSE,
    sort_order INT DEFAULT 0
);

CREATE TABLE product_variations (
    id BIGSERIAL PRIMARY KEY,
    product_id BIGINT NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    sku VARCHAR(100) UNIQUE NOT NULL,
    buy_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    stock_quantity INT NOT NULL DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE
);

CREATE TABLE variation_attribute_values (
    variation_id BIGINT NOT NULL REFERENCES product_variations(id) ON DELETE CASCADE,
    attribute_value_id INT NOT NULL REFERENCES attribute_values(id) ON DELETE CASCADE,
    PRIMARY KEY(variation_id, attribute_value_id)
);

CREATE TABLE product_prices (
    id BIGSERIAL PRIMARY KEY,
    product_id BIGINT NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    variation_id BIGINT NULL REFERENCES product_variations(id) ON DELETE CASCADE,
    sales_channel_id INT NOT NULL REFERENCES sales_channels(id),
    min_quantity INT NOT NULL DEFAULT 1,
    max_quantity INT NULL,
    unit_price DECIMAL(12,2) NOT NULL
);

-- 5. ORDERS, SUB-ORDERS & FULFILLMENT
CREATE TABLE payment_methods (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(50) UNIQUE NOT NULL,
    is_active BOOLEAN DEFAULT TRUE
);

CREATE TABLE orders (
    id BIGSERIAL PRIMARY KEY,
    order_number VARCHAR(64) UNIQUE NOT NULL,
    customer_id BIGINT NOT NULL REFERENCES users(id),
    order_status_id INT NOT NULL REFERENCES order_statuses(id),
    payment_status_id INT NOT NULL REFERENCES payment_statuses(id),
    payment_method_id INT NOT NULL REFERENCES payment_methods(id),
    shipping_address JSONB NOT NULL,
    total_amount DECIMAL(12,2) NOT NULL,
    tax_amount DECIMAL(12,2) DEFAULT 0.00,
    shipping_amount DECIMAL(12,2) DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE sub_orders (
    id BIGSERIAL PRIMARY KEY,
    parent_order_id BIGINT NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
    seller_id BIGINT NOT NULL REFERENCES users(id),
    sub_order_number VARCHAR(64) UNIQUE NOT NULL,
    fulfillment_status_id INT NOT NULL REFERENCES fulfillment_statuses(id),
    courier_name VARCHAR(100) NULL,
    tracking_number VARCHAR(128) NULL,
    subtotal DECIMAL(12,2) NOT NULL,
    commission_amount DECIMAL(12,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE order_items (
    id BIGSERIAL PRIMARY KEY,
    sub_order_id BIGINT NOT NULL REFERENCES sub_orders(id) ON DELETE CASCADE,
    product_id BIGINT NOT NULL REFERENCES products(id),
    variation_id BIGINT NULL REFERENCES product_variations(id),
    sales_channel_id INT NOT NULL REFERENCES sales_channels(id),
    unit_price DECIMAL(12,2) NOT NULL,
    quantity INT NOT NULL,
    total_price DECIMAL(12,2) NOT NULL
);

CREATE TABLE order_tracking_logs (
    id BIGSERIAL PRIMARY KEY,
    sub_order_id BIGINT NOT NULL REFERENCES sub_orders(id) ON DELETE CASCADE,
    fulfillment_status_id INT NOT NULL REFERENCES fulfillment_statuses(id),
    title VARCHAR(255) NOT NULL,
    description TEXT,
    hub_location VARCHAR(255),
    logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 6. ESCROW & SELLER WALLET LEDGER
CREATE TABLE seller_wallets (
    id BIGSERIAL PRIMARY KEY,
    seller_id BIGINT UNIQUE NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    available_balance DECIMAL(12,2) DEFAULT 0.00,
    escrow_held_balance DECIMAL(12,2) DEFAULT 0.00,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE wallet_transactions (
    id BIGSERIAL PRIMARY KEY,
    seller_wallet_id BIGINT NOT NULL REFERENCES seller_wallets(id) ON DELETE CASCADE,
    sub_order_id BIGINT NULL REFERENCES sub_orders(id) ON DELETE SET NULL,
    transaction_type VARCHAR(50) NOT NULL, -- 'escrow_hold', 'escrow_release', 'payout'
    amount DECIMAL(12,2) NOT NULL,
    running_balance DECIMAL(12,2) NOT NULL,
    reference_note TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);