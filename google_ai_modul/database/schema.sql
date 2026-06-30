-- =============================================================================
-- Google AI Modul — Bank Reconciliation (Banki Egyeztetés)
-- Target: revizor_db
-- =============================================================================

-- 1. Banki kivonat tételek (CSV-ből importálva)
CREATE TABLE IF NOT EXISTS bank_statements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    statement_date DATE,                   -- "Kivonat dátuma"
    value_date DATE,                       -- "Értéknap"
    amount DECIMAL(15,2),                  -- "Összeg" (terhelés esetén negatív, jóváírásnál pozitív)
    beneficiary_name VARCHAR(255),         -- "Kedvezményezett neve"
    initiator_name VARCHAR(255),           -- "Kezdeményező neve"
    description TEXT,                      -- "Közlemény"
    bank_tx_id VARCHAR(100) UNIQUE,        -- "Tranzakcióazonosító" (duplikáció kiszűrésére)
    bank_account VARCHAR(50) DEFAULT NULL, -- "Számlaszám" (gyülekezet azonosításhoz)
    status VARCHAR(30) DEFAULT 'UNCHECKED', -- UNCHECKED, MATCHED, TIMING_DIFFERENCE, stb.
    comment TEXT DEFAULT NULL,             -- Manuális megjegyzés a revizortól
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 2. Sikeres párosítások kapcsolótáblája (1:1, 1:N és N:1 kapcsolatokhoz)
CREATE TABLE IF NOT EXISTS bank_reconciliation (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bank_statement_id INT NOT NULL,        -- FK -> bank_statements.id
    ots_record_id INT NOT NULL,            -- FK -> ots.TRANSACTIONS.RECORD_ID
    matched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (bank_statement_id) REFERENCES bank_statements(id) ON DELETE CASCADE
);

-- 3. Bankszámla-Gyülekezet összekötő tábla
-- (church_id logikai FK az ots.churches-hez, nincs DDL kényszer)
CREATE TABLE IF NOT EXISTS church_bank_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    church_id INT NOT NULL,                 -- Logikai FK -> ots.churches.id
    bank_account_number VARCHAR(50) UNIQUE  -- A gyülekezet saját bankszámlaszáma
);

-- 4. Tanítható szabálytábla (Revizor által bővíthető kulcsszavas párosításhoz)
CREATE TABLE IF NOT EXISTS audit_learning_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    church_id INT DEFAULT NULL,             -- Ha NULL, akkor globális szabály
    bank_keyword VARCHAR(100) NOT NULL,     -- Kulcsszó a banki közleményből
    target_person_id INT DEFAULT NULL,      -- CÉL PERSON_ID
    target_type INT DEFAULT NULL,           -- CÉL TYPE (pl. 20=kiadás, 7=speciális)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
