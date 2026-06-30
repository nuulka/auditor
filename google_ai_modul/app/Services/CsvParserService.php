<?php

class CsvParserService {
    private $pdo;
    private $skipAccounts = [];
    private $accountToChurch = []; // bank_account_number → church_id

    public function __construct(PDO $pdo, array $skipAccounts = []) {
        $this->pdo = $pdo;
        $this->skipAccounts = $skipAccounts;
        $this->loadAccountMap();
    }

    private function loadAccountMap() {
        try {
            $rows = $this->pdo->query("SELECT church_id, bank_account_number FROM church_bank_accounts")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $clean = preg_replace('/[^0-9]/', '', $r['bank_account_number']);
                if ($clean) $this->accountToChurch[$clean] = (int)$r['church_id'];
            }
        } catch (Exception $e) {}
    }

    public function importCsv($filePath) {
        if (($handle = fopen($filePath, "r")) !== FALSE) {
            // Első sor (fejléc) átugrása
            fgetcsv($handle, 1000, ";");

            $sql = "INSERT IGNORE INTO bank_statements
                    (statement_date, value_date, amount, beneficiary_name, initiator_name, description, bank_tx_id, bank_account, church_id)
                    VALUES (:s_date, :v_date, :amount, :b_name, :i_name, :desc, :tx_id, :bank_account, :church_id)";

            $stmt = $this->pdo->prepare($sql);

            $count = 0;
            $skipped = 0;
            while (($data = fgetcsv($handle, 1000, ";")) !== FALSE) {
                if (empty($data[15])) continue; // Ha nincs tranzakcióazonosító, ugorjuk át

                // Bankszámlaszám (index 1)
                $bankAccount = trim($data[1] ?? '');

                // Skip list ellenőrzés: ha a bankszáma szerepel a kihagyandók között, ugrás
                if ($bankAccount && !empty($this->skipAccounts)) {
                    $cleanAcc = preg_replace('/[^0-9]/', '', $bankAccount);
                    if (isset($this->skipAccounts[$cleanAcc])) {
                        $skipped++;
                        continue;
                    }
                }

                // Magyar dátumformátum (pl. 2026.06.21) átalakítása MySQL formátumra (2026-06-21)
                $statementDate = str_replace('.', '-', trim($data[0]));
                $valueDate = str_replace('.', '-', trim($data[8]));

                // Összeg tisztítása és előjelezése (pl. "15 000" vagy "-5 000")
                $amountRaw = str_replace([' ', "\xc2\xa0"], '', $data[9]); // Szóközök eltávolítása
                $amount = (float)str_replace(',', '.', $amountRaw);

                // church_id meghatározása a bankszámlaszám alapján
                $churchId = null;
                if ($bankAccount) {
                    $cleanAcc = preg_replace('/[^0-9]/', '', $bankAccount);
                    $churchId = $this->accountToChurch[$cleanAcc] ?? null;
                }

                $stmt->execute([
                    's_date'       => $statementDate,
                    'v_date'       => $valueDate,
                    'amount'       => $amount,
                    'b_name'       => mb_convert_encoding($data[10], "UTF-8", "ISO-8859-2"),
                    'i_name'       => mb_convert_encoding($data[13], "UTF-8", "ISO-8859-2"),
                    'desc'         => mb_convert_encoding($data[12], "UTF-8", "ISO-8859-2"),
                    'tx_id'        => trim($data[15]),
                    'bank_account' => $bankAccount,
                    'church_id'    => $churchId
                ]);
                $count++;
            }
            fclose($handle);
            return $count;
        }
        return false;
    }
}
