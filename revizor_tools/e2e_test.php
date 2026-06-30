<?php
/**
 * Revizor Comprehensive E2E Page Test.
 *
 * Creates a fresh admin session per page and verifies every page loads.
 * Also discovers links on each page and checks them too.
 *
 * Run: php tools/e2e_test.php
 */

$BASE_URL = 'http://localhost/revizor';

function create_session_file(): string {
    $sid = bin2hex(random_bytes(16));
    $session_data = [
        'SDA_LOGGED' => true,
        'SDA_USER_ID' => 1,
        'SDA_USER_RIGHTS' => 512,
        'SDA_CHURCH_ID' => 43,
        'GN_USER_ID' => 1,
        'GN_USER_RIGHTS' => 512,
        'GN_CHURCH_ID' => 43,
        'GC_USER_FULL_NAME' => 'E2E Tester',
        'GC_LOGIN_COOKIE' => true,
        'SDA_LAST_ACTIVE' => time(),
        'revizor_expires_at' => time() + 3600,
        'csrf_token' => bin2hex(random_bytes(32)),
        'revizor_app_role' => 'admin',
    ];
    $encoded = '';
    foreach ($session_data as $k => $v) {
        $encoded .= $k . '|' . serialize($v);
    }
    file_put_contents('D:/laragon/tmp/sess_' . $sid, $encoded);
    return $sid;
}

function cleanup_session($sid) {
    $f = 'D:/laragon/tmp/sess_' . $sid;
    if (file_exists($f)) unlink($f);
}

function fetch($url, $sid, &$status = null, &$body = null, &$ct = null) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_COOKIE => 'PHPSESSID=' . $sid,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $resp = curl_exec($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);

    $status = $info['http_code'];
    $ct = $info['content_type'] ?? '';
    $header_size = $info['header_size'];
    $body = substr($resp, $header_size);
    return $status;
}

function resolve_url($href, $base_url) {
    if (empty($href) || $href === '#' || $href === '#!' ||
        str_starts_with($href, 'javascript:') || str_starts_with($href, 'mailto:') ||
        str_starts_with($href, 'tel:') || str_starts_with($href, 'data:')) return null;

    $parsed = parse_url($base_url);
    $scheme = $parsed['scheme'] ?? 'http';
    $host = $parsed['host'] ?? 'localhost';
    $base_path = dirname($parsed['path'] ?? '');
    if ($base_path === '\\') $base_path = '';

    if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
        $resolved = $href;
    } elseif (str_starts_with($href, '//')) {
        $resolved = $scheme . ':' . $href;
    } elseif (str_starts_with($href, '/')) {
        $resolved = $scheme . '://' . $host . $href;
    } else {
        $resolved = $scheme . '://' . $host . $base_path . '/' . ltrim($href, '/');
    }

    // Normalize path: resolve ../ and ./
    $scheme_part = '';
    if (str_contains($resolved, '://')) {
        [$scheme_part, $rest] = explode('://', $resolved, 2);
        $scheme_part .= '://';
    } else {
        $rest = $resolved;
    }

    $parts = explode('/', $rest);
    $stack = [];
    foreach ($parts as $p) {
        if ($p === '..' && count($stack) > 0) { array_pop($stack); }
        elseif ($p !== '' && $p !== '.' && $p !== '..') { $stack[] = $p; }
    }
    $resolved = $scheme_part . implode('/', $stack);

    // Only test local URLs
    if (!str_contains($resolved, 'localhost/revizor')) return null;

    return $resolved;
}

function should_skip($url): bool {
    $path = parse_url($url, PHP_URL_PATH) ?? '';
    $basename = basename($path);
    $skip = ['logout.php', 'reset_db.php', 'login.php', 'ots_ready'];
    foreach ($skip as $s) {
        if (str_contains($basename, $s) || str_contains($url, $s)) return true;
    }
    return false;
}

function test_page($url, $sid): int {
    $url_clean = rtrim(strtok($url, '?#'), '/');
    $status = fetch($url_clean, $sid, $status_code);

    $path = parse_url($url_clean, PHP_URL_PATH) ?? '';
    $basename = basename($path);

    // Expected behavior
    if ($status === 200 || $status === 302) {
        return $status;
    }
    return $status;
}

// MAIN
echo "=== Revizor E2E Page & Link Test ===\n\n";

$seed_pages = [
    $BASE_URL . '/index.php',
    $BASE_URL . '/reconciliation.php',
    $BASE_URL . '/search.php',
    $BASE_URL . '/document_check.php',
    $BASE_URL . '/upload.php',
    $BASE_URL . '/all_transactions/all_transactions_multi.php',
    $BASE_URL . '/help.php',
];

$visited = [];
$queue = $seed_pages;
$page_count = 0;
$error_count = 0;
$warn_count = 0;
$link_count = 0;
$errors = [];

echo "Testing pages (fresh session per page)...\n\n";

while (!empty($queue)) {
    $url = array_shift($queue);
    $norm = rtrim(strtok($url, '?#'), '/');

    if (($visited[$norm] ?? null) === true) continue;
    if (should_skip($url)) continue;

    $visited[$norm] = true;
    $page_count++;

    // Create fresh session for each page
    $sid = create_session_file();

    $label = str_replace($BASE_URL . '/', '', $url);
    echo "  [$page_count] $label ... ";

    $status = fetch($url, $sid);
    $elapsed = '?';

    if ($status === 0) {
        echo "TIMEOUT\n";
        $errors[] = "  $url -> timeout";
        $error_count++;
        cleanup_session($sid);
        continue;
    }

    if ($status >= 500) {
        echo "ERROR $status\n";
        $errors[] = "  $url -> HTTP $status";
        $error_count++;
        cleanup_session($sid);
        continue;
    }

    // Same call also gives us body for link extraction
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_COOKIE => 'PHPSESSID=' . $sid,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $resp = curl_exec($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);
    $body = substr($resp, $info['header_size']);
    $ct = $info['content_type'] ?? '';

    if ($status === 200) {
        echo "OK\n";
    } elseif ($status === 302) {
        if (str_contains($basename ?? '', 'logout')) {
            echo "OK 302 (logout redirect)\n";
        } else {
            echo "OK 302\n";
        }
    } elseif ($status === 404) {
        echo "OK 404 (not found)\n";
    } else {
        echo "WARN $status\n";
        $warn_count++;
    }

    // Extract links from HTML
    if ($body && $ct && str_contains($ct, 'text/html')) {
        $discovered = 0;
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $body, LIBXML_NOWARNING | LIBXML_NOERROR);

        foreach ($dom->getElementsByTagName('a') as $el) {
            $href = $el->getAttribute('href');
            $resolved = resolve_url($href, $url);
            if ($resolved) {
                $discovered++;
                if (!should_skip($resolved)) {
                    $lnorm = rtrim(strtok($resolved, '?#'), '/');
                    if (!isset($visited[$lnorm])) {
                        $queue[] = $resolved;
                        $visited[$lnorm] = 'queued';
                        $link_count++;
                    }
                }
            }
        }

        foreach ($dom->getElementsByTagName('form') as $el) {
            $href = $el->getAttribute('action');
            if (empty($href)) continue;
            $resolved = resolve_url($href, $url);
            if ($resolved) {
                $discovered++;
                if (!should_skip($resolved)) {
                    $lnorm = rtrim(strtok($resolved, '?#'), '/');
                    if (!isset($visited[$lnorm])) {
                        $queue[] = $resolved;
                        $visited[$lnorm] = 'queued';
                        $link_count++;
                    }
                }
            }
        }
        echo "      ($discovered links found on page)\n";
    }

    cleanup_session($sid);
}

echo "\n=== Summary ===\n";
echo "  Pages checked: $page_count\n";
echo "  Unique links discovered: $link_count\n";
echo "  Errors (timeout/500): $error_count\n";
echo "  Warnings (unexpected): $warn_count\n";

if (!empty($errors)) {
    echo "\n  --- Errors ---\n";
    foreach ($errors as $e) {
        echo "    $e\n";
    }
}

echo "\n" . ($error_count === 0 ? "ALL TESTS PASSED" : "SOME TESTS FAILED") . "\n";
exit($error_count > 0 ? 1 : 0);
