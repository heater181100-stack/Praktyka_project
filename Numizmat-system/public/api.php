<?php
date_default_timezone_set('Europe/Warsaw');
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$dataDir = __DIR__ . '/../data';
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0775, true);
}
$dbPath = $dataDir . '/database.sqlite';

try {
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $db->exec("CREATE TABLE IF NOT EXISTS coin_price_history (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        coin_name TEXT NOT NULL,
        scan_date DATE NOT NULL,
        scanned_at DATETIME NOT NULL,
        min_price REAL,
        max_price REAL,
        min_url TEXT,
        max_url TEXT,
        UNIQUE(coin_name, scan_date)
    )");

    $db->exec("CREATE INDEX IF NOT EXISTS idx_history_coin_date ON coin_price_history(coin_name, scan_date)");

    $db->exec("CREATE TABLE IF NOT EXISTS sync_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        log_text TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Błąd bazy: ' . $e->getMessage()]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = file_get_contents('php://input');
    $payload = json_decode($rawInput, true) ?? [];

    $coinName = trim($payload['coin_name'] ?? '');
    if (!$coinName) {
        echo json_encode(['status' => 'error', 'message' => 'Brak nazwy monety']);
        exit;
    }

    $minPrice = $payload['min_price'] !== null ? (float)$payload['min_price'] : null;
    $maxPrice = $payload['max_price'] !== null ? (float)$payload['max_price'] : null;
    $minUrl   = $payload['min_url'] ?? null;
    $maxUrl   = $payload['max_url'] ?? null;

    $todayDate = date('Y-m-d');
    $nowDateTime = date('Y-m-d H:i:s');

    $stmt = $db->prepare("
        INSERT INTO coin_price_history (coin_name, scan_date, scanned_at, min_price, max_price, min_url, max_url)
        VALUES (:coin_name, :scan_date, :scanned_at, :min_price, :max_price, :min_url, :max_url)
        ON CONFLICT(coin_name, scan_date) DO UPDATE SET
            scanned_at = excluded.scanned_at,
            min_price = excluded.min_price,
            max_price = excluded.max_price,
            min_url = excluded.min_url,
            max_url = excluded.max_url
    ");
    $stmt->execute([
        ':coin_name'   => $coinName,
        ':scan_date'   => $todayDate,
        ':scanned_at'  => $nowDateTime,
        ':min_price'   => $minPrice,
        ':max_price'   => $maxPrice,
        ':min_url'     => $minUrl,
        ':max_url'     => $maxUrl
    ]);

    echo json_encode(['status' => 'ok', 'coin_name' => $coinName, 'date' => $todayDate, 'time' => $nowDateTime]);
    exit;
}

$action = $_GET['action'] ?? '';

if ($action === 'coin_history') {
    $coinName = trim($_GET['coin_name'] ?? '');
    if (!$coinName) {
        echo json_encode(['status' => 'error', 'message' => 'Brak nazwy monety']);
        exit;
    }

    $stmt = $db->prepare("
        SELECT scan_date, scanned_at, min_price, max_price, min_url, max_url
        FROM coin_price_history
        WHERE coin_name = :coin_name AND min_price IS NOT NULL
        ORDER BY scan_date ASC
    ");
    $stmt->execute([':coin_name' => $coinName]);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['status' => 'ok', 'coin_name' => $coinName, 'history' => $history]);
    exit;
}

$todayDate = date('Y-m-d');
$stmt = $db->prepare("SELECT * FROM coin_price_history WHERE scan_date = :today");
$stmt->execute([':today' => $todayDate]);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['status' => 'ok', 'date' => $todayDate, 'data' => $results]);