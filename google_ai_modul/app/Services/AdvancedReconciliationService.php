<?php

class AdvancedReconciliationService {
    private $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function reconcileTransaction($btx, $currentChurchBankAccount) {
        // --- 0. LÉPÉS: CHURCH_ID kötelező azonosítása a számlaszám alapján ---
        $churchId = $this->getChurchIdByAccount($currentChurchBankAccount);
        if (!$churchId) {
            return false; // Ha a bankszámla nincs rendszerben, biztonsági okokból átugorjuk
        }

        // --- 1. LÉPÉS: Tanítható kulcsszavas szabályok ellenőrzése ---
        if ($this->matchByLearnedRules($btx, $churchId)) return true;

        // --- 2. LÉPÉS: Progresszív Dátumablak (0 - 60 nap) + CHURCH_ID kényszerítés ---
        $windows = [0, 3, 6, 12, 35, 60];
        foreach ($windows as $days) {
            if ($this->matchExactOneToOne($btx, $days, $churchId)) return true;
        }

        // --- 3. LÉPÉS: "Végső esély" Extrém Csúszásokra (61 - 90 nap) ---
        if ($this->matchExtremeDelay($btx, 90)) return true;

        // --- 4. LÉPÉS: Automata Összevonások Keresése (N:1 - Több OTS -> 1 Banki) ---
        if ($this->matchManyOtsToOneBank($btx)) return true;

        return false;
    }

    /**
     * Megkeresi a bankszámlához tartozó fix Gyülekezet ID-t
     */
    private function getChurchIdByAccount($accountNumber) {
        $stmt = $this->pdo->prepare("SELECT church_id FROM church_bank_accounts WHERE bank_account_number = ? LIMIT 1");
        $stmt->execute([$accountNumber]);
        return $stmt->fetchColumn();
    }

    /**
     * Intelligens párosítás a revizor által tanított szabálytábla alapján
     */
    private function matchByLearnedRules($btx, $churchId) {
        // Lekérjük az erre a gyülekezetre vonatkozó VAGY globális szabályokat
        $stmt = $this->pdo->prepare("
            SELECT * FROM audit_learning_rules
            WHERE (church_id IS NULL OR church_id = :church_id)
        ");
        $stmt->execute(['church_id' => $churchId]);
        $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rules as $rule) {
            $keyword = strtolower($rule['bank_keyword']);
            // A banki közleményt és a küldő nevét is ellenőrizzük
            $bankText = strtolower($btx['description'] . ' ' . $btx['initiator_name']);

            // Ha a banki adatokban megtalálható a megtanított kulcsszó (pl. "mvm")
            if (strpos($bankText, $keyword) !== false) {

                // Keresünk egy olyan OTS tételt, ami:
                // - Az adott gyülekezethez tartozik (CHURCH_ID)
                // - Egyezik az összege előjelhelyesen
                // - ±60 napon belül van
                // - És megegyezik a tanult partner (PERSON_ID) vagy típus (TYPE)
                $sql = "SELECT RECORD_ID FROM ots.TRANSACTIONS
                        WHERE CHURCH_ID = :church_id
                        AND VIA_BANK <> 0
                        AND RECORD_ID NOT IN (SELECT ots_record_id FROM bank_reconciliation)
                        AND ABS(DATEDIFF(DATETIME, :bank_date)) <= 60
                        " . ($rule['target_person_id'] ? "AND PERSON_ID = :person_id " : "") . "
                        " . ($rule['target_type'] ? "AND TYPE = :type " : "") . "
                        GROUP BY RECORD_ID
                        HAVING SUM(CASE WHEN TYPE IN (20, 9) THEN -1 * AMOUNT ELSE AMOUNT END) = :amount
                        LIMIT 1";

                $query = $this->pdo->prepare($sql);

                $params = [
                    'church_id' => $churchId,
                    'bank_date' => $btx['value_date'],
                    'amount'    => $btx['amount']
                ];
                if ($rule['target_person_id']) $params['person_id'] = $rule['target_person_id'];
                if ($rule['target_type']) $params['type'] = $rule['target_type'];

                $query->execute($params);
                $otsTx = $query->fetch(PDO::FETCH_ASSOC);

                if ($otsTx) {
                    $this->saveMatch($btx['id'], $otsTx['RECORD_ID'], 'MATCHED');
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Standard 1:1 egyezés előjelhelyesen, CHURCH_ID szűréssel
     */
    private function matchExactOneToOne($btx, $days, $churchId) {
        $sql = "SELECT RECORD_ID FROM ots.TRANSACTIONS
                WHERE CHURCH_ID = :church_id
                AND VIA_BANK <> 0
                AND RECORD_ID NOT IN (SELECT ots_record_id FROM bank_reconciliation)
                AND ABS(DATEDIFF(DATETIME, :bank_date)) <= :days
                GROUP BY RECORD_ID
                HAVING SUM(CASE WHEN TYPE IN (20, 9) THEN -1 * AMOUNT ELSE AMOUNT END) = :amount
                LIMIT 1";

        $query = $this->pdo->prepare($sql);
        $query->execute([
            'church_id' => $churchId,
            'bank_date' => $btx['value_date'],
            'days'      => $days,
            'amount'    => $btx['amount']
        ]);
        $res = $query->fetch(PDO::FETCH_ASSOC);

        if ($res) {
            $status = ($days === 0) ? 'MATCHED' : 'TIMING_DIFFERENCE';
            $this->saveMatch($btx['id'], $res['RECORD_ID'], $status);
            return true;
        }
        return false;
    }

    /**
     * Extrém 90 napos csúszás kezelése extra biztonsági szűrővel
     */
    private function matchExtremeDelay($btx, $maxDays) {
        $sql = "SELECT T.RECORD_ID, T.CHURCH_ID
                FROM ots.TRANSACTIONS T
                WHERE T.VIA_BANK <> 0
                AND T.RECORD_ID NOT IN (SELECT ots_record_id FROM bank_reconciliation)
                AND ABS(DATEDIFF(T.DATETIME, :bank_date)) <= :max_days
                GROUP BY T.RECORD_ID
                HAVING SUM(CASE WHEN T.TYPE IN (20, 9) THEN -1 * T.AMOUNT ELSE T.AMOUNT END) = :amount";

        $query = $this->pdo->prepare($sql);
        $query->execute(['bank_date' => $btx['value_date'], 'max_days' => $maxDays, 'amount' => $btx['amount']]);
        $candidates = $query->fetchAll(PDO::FETCH_ASSOC);

        foreach ($candidates as $cand) {
            if (strpos($btx['description'], (string)$cand['CHURCH_ID']) !== false) {
                $this->saveMatch($btx['id'], $cand['RECORD_ID'], 'TIMING_DIFFERENCE');
                return true;
            }
        }
        return false;
    }

    /**
     * Több OTS tétel -> 1 Banki tétel (Összevont rezsi vagy befizetés)
     */
    private function matchManyOtsToOneBank($btx) {
        $sql = "SELECT CHURCH_ID, GROUP_CONCAT(RECORD_ID) as merged_ids,
                SUM(CASE WHEN TYPE IN (20, 9) THEN -1 * AMOUNT ELSE AMOUNT END) as sum_amount
                FROM ots.TRANSACTIONS
                WHERE VIA_BANK <> 0
                AND RECORD_ID NOT IN (SELECT ots_record_id FROM bank_reconciliation)
                AND ABS(DATEDIFF(DATETIME, :bank_date)) <= 15
                GROUP BY CHURCH_ID, DATE(DATETIME)
                HAVING sum_amount = :amount
                LIMIT 1";

        $query = $this->pdo->prepare($sql);
        $query->execute(['bank_date' => $btx['value_date'], 'amount' => $btx['amount']]);
        $res = $query->fetch(PDO::FETCH_ASSOC);

        if ($res) {
            $ids = explode(',', $res['merged_ids']);
            foreach ($ids as $otsId) {
                $this->saveMatch($btx['id'], $otsId, 'MANY_TO_ONE');
            }
            return true;
        }
        return false;
    }

    private function saveMatch($bankStatementId, $otsRecordId, $status) {
        $stmt1 = $this->pdo->prepare("INSERT INTO bank_reconciliation (bank_statement_id, ots_record_id) VALUES (?, ?)");
        $stmt1->execute([$bankStatementId, $otsRecordId]);

        $stmt2 = $this->pdo->prepare("UPDATE bank_statements SET status = ? WHERE id = ?");
        $stmt2->execute([$status, $bankStatementId]);
    }
}
