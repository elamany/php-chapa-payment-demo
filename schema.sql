-- ============================================================
-- schema.sql — Chapa PHP Demo
-- MySQL 5.7+ / MariaDB 10.3+
-- Run: mysql -u root -p chapa_demo < schema.sql
-- ============================================================

CREATE TABLE IF NOT EXISTS donations (
    id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,

    -- references
    tx_ref        VARCHAR(64)   NOT NULL,                 -- ours (CHP-… / DON-…)
    chapa_ref     VARCHAR(255)  DEFAULT NULL,             -- Chapa's reference
    checkout_url  VARCHAR(500)  DEFAULT NULL,             -- hosted checkout URL

    -- payment
    amount        DECIMAL(12,2) NOT NULL,
    currency      CHAR(3)       NOT NULL DEFAULT 'ETB',

    -- customer
    email         VARCHAR(255)  NOT NULL,
    full_name     VARCHAR(150)  NOT NULL,
    is_anonymous  TINYINT(1)    NOT NULL DEFAULT 0,

    -- state
    status        ENUM('pending','paid','failed','cancelled')
                  NOT NULL DEFAULT 'pending',

    -- raw payloads / diagnostics
    raw_init      LONGTEXT      DEFAULT NULL,             -- Chapa /initialize response
    raw_verify    LONGTEXT      DEFAULT NULL,             -- Chapa /verify response
    error         TEXT          DEFAULT NULL,             -- last error message

    ip            VARCHAR(45)   DEFAULT NULL,             -- IPv4 / IPv6

    -- timestamps
    created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
                                ON UPDATE CURRENT_TIMESTAMP,
    paid_at       DATETIME      DEFAULT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_tx_ref    (tx_ref),
    KEY idx_chapa_ref       (chapa_ref),
    KEY idx_status          (status),
    KEY idx_email           (email),
    KEY idx_created         (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;