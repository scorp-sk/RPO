-- Schema of the RPO live clone (MariaDB 10.11+)
-- Only the current name and registered address of each entity, looked up by IČO (company ID).

CREATE TABLE IF NOT EXISTS company (
    id              INT UNSIGNED NOT NULL,           -- RPO record id
    ico             VARCHAR(32)  NULL,               -- current IČO; last known one for terminated entities
    name            VARCHAR(1024) NULL,              -- current full name
    street          VARCHAR(255) NULL,
    reg_number      INT UNSIGNED NULL,               -- land-registry number (súpisné číslo)
    building_number VARCHAR(64)  NULL,               -- street number (orientačné číslo)
    postal_code     VARCHAR(16)  NULL,               -- postal code without spaces
    municipality    VARCHAR(255) NULL,
    establishment   DATE         NULL,
    termination     DATE         NULL,
    source_register_code VARCHAR(16)  NULL,          -- source register, codelist CL010112 (1 = business register, 2 = trade register, 18 = municipality…)
    registration_office  VARCHAR(255) NULL,          -- court / office where the entity is registered
    registration_number  VARCHAR(128) NULL,          -- registration number (e.g. "Sro/13889/L", "640-23223")
    PRIMARY KEY (id),
    KEY idx_ico (ico)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS import_log (
    file_key    VARCHAR(255)            NOT NULL,
    kind        ENUM('init', 'daily')   NOT NULL,
    export_date DATE                    NOT NULL,
    etag        VARCHAR(64)             NULL,
    records     INT UNSIGNED            NOT NULL DEFAULT 0,
    imported_at DATETIME                NOT NULL,
    PRIMARY KEY (file_key),
    KEY idx_kind_date (kind, export_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- API user access tokens. Only the SHA-256 hash of the token is stored.
CREATE TABLE IF NOT EXISTS api_token (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name         VARCHAR(255) NOT NULL,                                     -- who the token belongs to
    token_hash   CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    token_hint   VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, -- token prefix for identification
    created_at   DATETIME NOT NULL,
    last_used_at DATETIME NULL,
    revoked_at   DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_token_hash (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Number of requests per token per day
CREATE TABLE IF NOT EXISTS api_token_usage (
    token_id INT UNSIGNED NOT NULL,
    day      DATE         NOT NULL,
    requests INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (token_id, day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
