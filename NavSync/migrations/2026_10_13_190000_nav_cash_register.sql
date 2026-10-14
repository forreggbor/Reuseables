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

DROP PROCEDURE IF EXISTS nav_cash_register_migrate;
DELIMITER //
CREATE PROCEDURE nav_cash_register_migrate()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nav_companies' AND COLUMN_NAME = 'cash_register_sync') THEN
        ALTER TABLE nav_companies
            ADD COLUMN cash_register_sync TINYINT(1) NOT NULL DEFAULT 0
            COMMENT '1 = the daily cash register (OPF) log sync runs for this company' AFTER active;
    END IF;
END //
DELIMITER ;
CALL nav_cash_register_migrate();
DROP PROCEDURE nav_cash_register_migrate;

ALTER TABLE nav_sync_log
    MODIFY direction ENUM('INBOUND','OUTBOUND','BOTH','CASH_REGISTER') NOT NULL DEFAULT 'BOTH';
