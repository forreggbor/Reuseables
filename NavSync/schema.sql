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
    cash_register_sync TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = the daily cash register (OPF) log sync runs for this company',
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
    direction     ENUM('INBOUND','OUTBOUND','BOTH','CASH_REGISTER') NOT NULL DEFAULT 'BOTH',
    fetched_count INT          NOT NULL DEFAULT 0,
    error         TEXT         NULL,

    INDEX idx_nav_sync_log_company (company_id),

    CONSTRAINT fk_nav_sync_log_company
        FOREIGN KEY (company_id) REFERENCES nav_companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nav_cash_registers (
    id                        INT PRIMARY KEY AUTO_INCREMENT,
    company_id                INT         NOT NULL,
    ap_number                 CHAR(9)     NOT NULL COMMENT 'AP number of the cash register, e.g. A12345678',
    last_communication_at     DATETIME    NULL COMMENT 'UTC, from queryCashRegisterStatus',
    last_file_at              DATETIME    NULL COMMENT 'UTC, from queryCashRegisterStatus',
    min_available_file_number INT         NOT NULL DEFAULT 0 COMMENT 'Oldest file number NAV still holds; 0 = none',
    max_available_file_number INT         NOT NULL DEFAULT 0 COMMENT 'Newest file number NAV holds; 0 = none',
    last_synced_file_number   INT         NOT NULL DEFAULT 0 COMMENT 'Highest file number downloaded and stored',
    created_at                DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_nav_cash_registers (company_id, ap_number),

    CONSTRAINT fk_nav_cash_registers_company
        FOREIGN KEY (company_id) REFERENCES nav_companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nav_cash_register_files (
    id                INT PRIMARY KEY AUTO_INCREMENT,
    register_id       INT          NOT NULL,
    file_number       INT          NOT NULL,
    stored_name       VARCHAR(60)  NOT NULL COMMENT 'File name on disk, built by the module (<AP>_<number>.p7b)',
    sha256            CHAR(64)     NOT NULL,
    validation_code   ENUM('OK','WARN','ERROR') NOT NULL COMMENT 'NAV fileValidationResultCode',
    validation_error  VARCHAR(100) NULL COMMENT 'NAV fileValidationErrorCode',
    parse_status      ENUM('PENDING','PARSED','ERROR','SKIPPED') NOT NULL DEFAULT 'PENDING',
    parse_error       TEXT         NULL,
    downloaded_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    parsed_at         DATETIME     NULL,

    UNIQUE KEY uq_nav_cash_register_files (register_id, file_number),
    INDEX idx_nav_cash_register_files_status (parse_status),

    CONSTRAINT fk_nav_cash_register_files_register
        FOREIGN KEY (register_id) REFERENCES nav_cash_registers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nav_cash_receipts (
    id                    INT PRIMARY KEY AUTO_INCREMENT,
    file_id               INT          NOT NULL,
    register_id           INT          NOT NULL,
    record_no             INT          NOT NULL COMMENT 'RSR: record number inside the log file',
    kind                  ENUM('RECEIPT','SIMPLIFIED_INVOICE') NOT NULL,
    practice              TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = practice mode record (NYT/EST), never counted',
    receipt_number        VARCHAR(20)  NOT NULL COMMENT 'NSZ: bbbb/ccccc',
    simplified_invoice_id VARCHAR(40)  NULL COMMENT 'IID',
    closed_at             DATETIME     NOT NULL COMMENT 'CTS, Europe/Budapest wall-clock time',
    gross_total           DECIMAL(18,2) NOT NULL DEFAULT 0 COMMENT 'SUM',
    rounding              DECIMAL(18,2) NOT NULL DEFAULT 0 COMMENT 'RND',
    interrupted           TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'CNC = 1, never counted',
    created_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_nav_cash_receipts (file_id, record_no),
    INDEX idx_nav_cash_receipts_register_closed (register_id, closed_at),

    CONSTRAINT fk_nav_cash_receipts_file
        FOREIGN KEY (file_id) REFERENCES nav_cash_register_files(id) ON DELETE CASCADE,
    CONSTRAINT fk_nav_cash_receipts_register
        FOREIGN KEY (register_id) REFERENCES nav_cash_registers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nav_cash_receipt_lines (
    id             INT PRIMARY KEY AUTO_INCREMENT,
    receipt_id     INT           NOT NULL,
    line_number    INT           NOT NULL,
    description    VARCHAR(200)  NOT NULL,
    article_no     VARCHAR(50)   NULL,
    unit_price     DECIMAL(18,4) NULL,
    quantity       DECIMAL(18,4) NULL,
    unit           VARCHAR(10)   NULL,
    item_type      VARCHAR(2)    NOT NULL DEFAULT '' COMMENT 'TY as sent: s, e, es, k, ks, f, fs, g, gs or empty',
    gross_amount   DECIMAL(18,2) NOT NULL DEFAULT 0 COMMENT 'SU, signed',
    vat_collector  CHAR(3)       NOT NULL COMMENT 'VC turnover collector, e.g. A01',
    vat_rate       VARCHAR(10)   NULL COMMENT 'NULL = not mapped from the log VAT table',
    created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_nav_cash_receipt_lines (receipt_id, line_number),
    INDEX idx_nav_cash_receipt_lines_unmapped (vat_rate),

    CONSTRAINT fk_nav_cash_receipt_lines_receipt
        FOREIGN KEY (receipt_id) REFERENCES nav_cash_receipts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nav_cash_receipt_vat (
    id          INT PRIMARY KEY AUTO_INCREMENT,
    receipt_id  INT           NOT NULL,
    vat_rate    VARCHAR(10)   NOT NULL,
    gross_huf   DECIMAL(18,2) NOT NULL DEFAULT 0,
    net_huf     DECIMAL(18,2) NOT NULL DEFAULT 0,
    vat_huf     DECIMAL(18,2) NOT NULL DEFAULT 0,

    UNIQUE KEY uq_nav_cash_receipt_vat (receipt_id, vat_rate),

    CONSTRAINT fk_nav_cash_receipt_vat_receipt
        FOREIGN KEY (receipt_id) REFERENCES nav_cash_receipts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
