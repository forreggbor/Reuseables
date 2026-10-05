-- NavSync Database Schema
-- NAV Online Számla API v3.0 local invoice store
-- Compatible with MariaDB 10.5+

CREATE TABLE IF NOT EXISTS nav_companies (
    id            INT PRIMARY KEY AUTO_INCREMENT,
    name          VARCHAR(100)  NOT NULL COMMENT 'Display name',
    tax_number    CHAR(8)       NOT NULL COMMENT 'First 8 digits of tax number',
    nav_login     VARCHAR(50)   NOT NULL COMMENT 'NAV API technical user login',
    nav_password  VARCHAR(255)  NOT NULL COMMENT 'SHA-512 hash of NAV password',
    nav_sign_key  TEXT          NOT NULL COMMENT 'XML signing key, AES-256-GCM encrypted',
    active        TINYINT(1)    NOT NULL DEFAULT 1,
    last_sync_at  DATETIME      NULL     COMMENT 'insDate window end of last successful sync',
    created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_nav_companies_login (nav_login)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nav_invoices (
    id                    INT PRIMARY KEY AUTO_INCREMENT,
    company_id            INT          NOT NULL,
    direction             ENUM('INBOUND','OUTBOUND') NOT NULL,
    invoice_number        VARCHAR(100) NOT NULL,
    issue_date            DATE         NOT NULL,
    completion_date       DATE         NULL    COMMENT 'Teljesítési dátum — ÁFA base',
    payment_date          DATE         NULL    COMMENT 'Fizetési határidő',
    supplier_name         VARCHAR(255) NOT NULL,
    supplier_tax_number   VARCHAR(20)  NULL,
    supplier_bank_account VARCHAR(34)  NULL    COMMENT 'From queryInvoiceData',
    customer_name         VARCHAR(255) NULL,
    customer_tax_number   VARCHAR(20)  NULL,
    customer_country      VARCHAR(60)  NULL,
    customer_postcode     VARCHAR(20)  NULL,
    customer_city         VARCHAR(100) NULL,
    customer_street       VARCHAR(190) NULL,
    currency              CHAR(3)      NOT NULL DEFAULT 'HUF',
    net_amount            DECIMAL(18,2) NOT NULL DEFAULT 0,
    vat_amount            DECIMAL(18,2) NOT NULL DEFAULT 0,
    gross_amount          DECIMAL(18,2) NOT NULL DEFAULT 0,
    net_amount_huf        DECIMAL(18,2) NOT NULL DEFAULT 0,
    vat_amount_huf        DECIMAL(18,2) NOT NULL DEFAULT 0,
    gross_amount_huf      DECIMAL(18,2) NOT NULL DEFAULT 0,
    paid                  TINYINT(1)   NOT NULL DEFAULT 0,
    paid_at               DATE         NULL    COMMENT 'Manually recorded payment date',
    nav_insert_date       DATETIME     NULL    COMMENT 'NAV insDate — when NAV received this invoice',
    detail_fetched        TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = queryInvoiceData completed',
    invoice_operation     ENUM('CREATE','MODIFY','STORNO') NULL COMMENT 'NAV invoiceOperation of the digest; NULL = not seen yet',
    advance_type          ENUM('NONE','ADVANCE','FINAL') NULL COMMENT 'Read from the lines (advanceIndicator); NULL = not read yet',
    referenced_invoice_number VARCHAR(100) NULL COMMENT 'Original invoice of a correction or storno, else the advance invoice a final invoice settles',
    created_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_nav_invoices (company_id, direction, invoice_number),
    INDEX idx_nav_invoices_company   (company_id),
    INDEX idx_nav_invoices_direction (direction),
    INDEX idx_nav_invoices_completion (completion_date),
    INDEX idx_nav_invoices_paid      (paid),
    INDEX idx_nav_invoices_unfetched (detail_fetched),

    CONSTRAINT fk_nav_invoices_company
        FOREIGN KEY (company_id) REFERENCES nav_companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nav_invoice_lines (
    id                  INT PRIMARY KEY AUTO_INCREMENT,
    invoice_id          INT           NOT NULL,
    line_number         INT           NOT NULL,
    line_description    VARCHAR(512)  NULL,
    nature_indicator    VARCHAR(10)   NULL COMMENT 'PRODUCT / SERVICE / OTHER',
    quantity            DECIMAL(22,10) NULL,
    unit_of_measure     VARCHAR(20)   NULL,
    unit_of_measure_own VARCHAR(50)   NULL,
    unit_price          DECIMAL(18,4) NULL,
    unit_price_huf      DECIMAL(18,4) NULL,
    net_amount          DECIMAL(18,2) NULL,
    net_amount_huf      DECIMAL(18,2) NULL,
    vat_rate            VARCHAR(10)   NULL COMMENT '27, 5, 0, AAM, TAM, etc.',
    vat_amount          DECIMAL(18,2) NULL,
    vat_amount_huf      DECIMAL(18,2) NULL,
    gross_amount        DECIMAL(18,2) NULL,
    gross_amount_huf    DECIMAL(18,2) NULL,
    created_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_nav_lines_invoice (invoice_id),
    INDEX idx_nav_lines_vat_rate (vat_rate),

    CONSTRAINT fk_nav_lines_invoice
        FOREIGN KEY (invoice_id) REFERENCES nav_invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nav_invoice_vat (
    id             INT PRIMARY KEY AUTO_INCREMENT,
    invoice_id     INT           NOT NULL,
    vat_rate       VARCHAR(10)   NOT NULL COMMENT '27, 5, 0, AAM, TAM, ATK, RC, etc.',
    net_amount_huf DECIMAL(18,2) NOT NULL DEFAULT 0,
    vat_amount_huf DECIMAL(18,2) NOT NULL DEFAULT 0 COMMENT 'From the invoice VAT summary (always present, unlike per-line VAT)',
    created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_nav_invoice_vat (invoice_id, vat_rate),

    CONSTRAINT fk_nav_invoice_vat_invoice
        FOREIGN KEY (invoice_id) REFERENCES nav_invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nav_sync_log (
    id            INT PRIMARY KEY AUTO_INCREMENT,
    company_id    INT          NOT NULL,
    started_at    DATETIME     NOT NULL,
    finished_at   DATETIME     NULL,
    direction     ENUM('INBOUND','OUTBOUND','BOTH') NOT NULL DEFAULT 'BOTH',
    fetched_count INT          NOT NULL DEFAULT 0,
    error         TEXT         NULL,

    INDEX idx_nav_sync_log_company (company_id),

    CONSTRAINT fk_nav_sync_log_company
        FOREIGN KEY (company_id) REFERENCES nav_companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
