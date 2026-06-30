# Revizor HTTP Smoke Test
# Tests that all critical pages respond without 500 errors.
# Run: powershell -File tools/http_smoke.ps1

$base = "http://localhost/revizor"
$exitCode = 0

function Check-Page($path, $desc, $expectedStatus = 200) {
    $url = "$base/$path"
    try {
        $req = [System.Net.WebRequest]::Create($url)
        $req.Timeout = 10000
        $req.AllowAutoRedirect = $false
        $resp = $req.GetResponse()
        $status = [int]$resp.StatusCode
        $resp.Close()
        if ($status -eq $expectedStatus) {
            Write-Host "  PASS: $desc ($status)" -ForegroundColor Green
        } else {
            Write-Host "  FAIL: $desc - expected $expectedStatus, got $status" -ForegroundColor Red
            $global:exitCode = 1
        }
    } catch [System.Net.WebException] {
        $resp = $_.Exception.Response
        if ($resp -ne $null) {
            $status = [int]$resp.StatusCode
            if ($status -eq $expectedStatus) {
                Write-Host "  PASS: $desc ($status)" -ForegroundColor Green
            } else {
                Write-Host "  FAIL: $desc - expected $expectedStatus, got $status" -ForegroundColor Red
                $global:exitCode = 1
            }
            $resp.Close()
        } else {
            Write-Host "  FAIL: $desc - $($_.Exception.Message)" -ForegroundColor Red
            $global:exitCode = 1
        }
    }
}

Write-Host "=== HTTP Smoke Test: Pages requiring login (expect 302 redirect to login.php) ===" -ForegroundColor Cyan
Check-Page "index.php" "index.php" 302
Check-Page "upload.php" "upload.php" 302
Check-Page "reconciliation.php" "reconciliation.php" 302
Check-Page "search.php" "search.php" 302
Check-Page "document_check.php" "document_check.php" 302
Check-Page "session_ping.php" "session_ping.php" 401

Write-Host "`n=== HTTP Smoke Test: Public pages (expect 200) ===" -ForegroundColor Cyan
Check-Page "login.php" "login.php" 200
Check-Page "logout.php" "logout.php" 302
Check-Page "help.php" "help.php" 200

Write-Host "`n=== HTTP Smoke Test: Non-existent pages (expect 404) ===" -ForegroundColor Cyan
Check-Page "nonexistent.php" "nonexistent.php" 404
Check-Page "tools/nonexistent.php" "tools/nonexistent.php" 404

Write-Host "`n=== HTTP Smoke Test: Static files (expect 200) ===" -ForegroundColor Cyan
Check-Page "print.html" "print.html" 200

Write-Host "`n=== HTTP Smoke Test: All transactions (expect 302) ===" -ForegroundColor Cyan
Check-Page "all_transactions/all_transactions_multi.php" "all_transactions_multi.php" 302

Write-Host "`n=== HTTP Smoke Test: AI engine (expect 302) ===" -ForegroundColor Cyan
Check-Page "ai_engine/index.php" "ai_engine/index.php" 302

Write-Host "`n========================================" -ForegroundColor Cyan
if ($global:exitCode -eq 0) {
    Write-Host "ALL HTTP TESTS PASSED" -ForegroundColor Green
} else {
    Write-Host "SOME HTTP TESTS FAILED" -ForegroundColor Red
}
exit $global:exitCode
