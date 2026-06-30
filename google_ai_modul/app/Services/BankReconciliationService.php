<?php

class BankReconciliationService {
    private $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function runAutoMatching() {
        // Lekérjük az összes még nem ellenőrzött banki tételt
        $stmt = $this->pdo->query("SELECT * FROM bank_statements WHERE status = 'UNCHECKED'");
        $bankTransactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $dateWindows = [0, 3, 6, 12, 35, 60];

        foreach ($bankTransactions as $btx) {
            $matched = false;

            foreach ($dateWindows as $days) {
                // OTS Összeg számítása TYPE alapján:
                // Ha TYPE = 20 (kiadás) vagy 9 (levonás), akkor negatívként kezeljük az AMOUNT-ot
                $sql = "SELECT RECORD_ID, DATETIME
                        FROM ots.TRANSACTIONS
                        WHERE VIA_BANK <> 0
                        AND RECORD_ID NOT IN (SELECT ots_record_id FROM bank_reconciliation)
                        AND ABS(DATEDIFF(DATETIME, :bank_date)) <= :days
                        GROUP BY RECORD_ID
                        HAVING SUM(CASE WHEN TYPE IN (20, 9) THEN -1 * AMOUNT ELSE AMOUNT END) = :bank_amount
                        LIMIT 1";

                $query = $this->pdo->prepare($sql);
                $query->execute([
                    'bank_date'   => $btx['value_date'],
                    'days'        => $days,
                    'bank_amount' => $btx['amount']
                ]);

                $otsTx = $query->fetch(PDO::FETCH_ASSOC);

                if ($otsTx) {
                    $status = ($days === 0) ? 'MATCHED' : 'TIMING_DIFFERENCE';
                    $this->saveMatch($btx['id'], $otsTx['RECORD_ID'], $status);
                    $matched = true;
                    break; // Megvan a párja, léphetünk a következő banki tételre
                }
            }

            // 6. LÉPÉS: Szöveges hasonlóság alapú párosítás (Scoring)
            if (!$matched) {
                $this->tryTextScoreMatching($btx);
            }
        }
    }

    private function tryTextScoreMatching($btx) {
        // ±30 napos ablak a szöveges kereséshez
        $sql = "SELECT RECORD_ID, DATETIME,
                (SELECT NAME FROM ots.NAMES_OF_TRANSACTION WHERE id = NAME_ID) as note1
                FROM ots.TRANSACTIONS
                WHERE VIA_BANK <> 0
                AND RECORD_ID NOT IN (SELECT ots_record_id FROM bank_reconciliation)
                AND ABS(DATEDIFF(DATETIME, :bank_date)) <= 30
                GROUP BY RECORD_ID
                HAVING SUM(CASE WHEN TYPE IN (20, 9) THEN -1 * AMOUNT ELSE AMOUNT END) = :bank_amount";

        $query = $this->pdo->prepare($sql);
        $query->execute(['bank_date' => $btx['value_date'], 'bank_amount' => $btx['amount']]);
        $candidates = $query->fetchAll(PDO::FETCH_ASSOC);

        foreach ($candidates as $cand) {
            // Egyszerűsített szószámoló pontozás a banki közlemény és az OTS megjegyzés között
            $score = 0;
            $bankWords = explode(' ', strtolower($btx['description']));
            foreach ($bankWords as $word) {
                if (strlen($word) > 3 && strpos(strtolower($cand['note1']), $word) !== false) {
                    $score += 5; // Minden egyező kulcsszóért pont jár
                }
            }

            if ($score >= 10) {
                $this->saveMatch($btx['id'], $cand['RECORD_ID'], 'MATCHED');
                return true;
            }
        }
        return false;
    }

    private function saveMatch($bankStatementId, $otsRecordId, $status) {
        $this->pdo->beginTransaction();

        $stmt1 = $this->pdo->prepare("INSERT INTO bank_reconciliation (bank_statement_id, ots_record_id) VALUES (?, ?)");
        $stmt1->execute([$bankStatementId, $otsRecordId]);

        $stmt2 = $this->pdo->prepare("UPDATE bank_statements SET status = ? WHERE id = ?");
        $stmt2->execute([$status, $bankStatementId]);

        $this->pdo->commit();
    }
}
