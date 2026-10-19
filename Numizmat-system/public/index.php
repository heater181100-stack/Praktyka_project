<?php
date_default_timezone_set('Europe/Warsaw');
require_once __DIR__ . '/../src/SampleData.php';
require_once __DIR__ . '/../src/NbpImporter.php';

$dataDir = __DIR__ . '/../data';
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0775, true);
}
$dbPath = $dataDir . '/database.sqlite';

$todayDate = date('Y-m-d');
$todayFormatted = date('d.m.Y');
$todayPrices = [];

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

    $stmt = $db->prepare("SELECT * FROM coin_price_history WHERE scan_date = :today");
    $stmt->execute([':today' => $todayDate]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $todayPrices[$row['coin_name']] = $row;
    }
} catch (Throwable $e) {
    $todayPrices = [];
}

$pdfUrl = 'https://static.nbp.pl/publikacje/numizmatyka/plan-2026-pl.pdf';
$cacheDir = __DIR__ . '/../data/cache';

$importer = new NbpImporter($pdfUrl, $cacheDir);
try {
    $coins = $importer->getCoins();
    if (empty($coins)) {
        $coins = getSampleCoins();
    }
} catch (Throwable $e) {
    $coins = getSampleCoins();
}

function formatPrice(?float $price): string
{
    if ($price === null) return '—';
    return number_format($price, 2, ',', ' ') . ' zł';
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monitoring cen monet NBP – Dzisiejsze notowania</title>
    <style>
        :root {
            --bg-color: #f8fafc;
            --card-bg: #ffffff;
            --border-color: #e2e8f0;
            --text-main: #1e293b;
            --text-muted: #64748b;
            --primary: #0284c7;
            --primary-hover: #0369a1;
            --success-bg: #dcfce7;
            --success-text: #166534;
            --warning-bg: #fef3c7;
            --warning-text: #92400e;
            --danger-bg: #fee2e2;
            --danger-text: #991b1b;
            --info-bg: #f1f5f9;
            --info-text: #475569;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background-color: var(--bg-color);
            color: var(--text-main);
            margin: 0;
            padding: 30px 20px;
        }
        .container { max-width: 1240px; margin: 0 auto; }
        .nav-links { display: flex; gap: 10px; margin-bottom: 20px; }
        .nav-btn {
            text-decoration: none; padding: 8px 16px; border-radius: 6px;
            font-size: 14px; font-weight: 600; color: var(--text-main);
            background: #e2e8f0; transition: all 0.2s;
        }
        .nav-btn.active { background: var(--primary); color: #ffffff; }
        .panel {
            background: var(--card-bg); border: 1px solid var(--border-color);
            border-radius: 8px; padding: 20px 24px; margin-bottom: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .panel-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; }
        .controls { display: flex; align-items: center; gap: 12px; }
        .btn {
            background-color: var(--primary); color: #ffffff; border: none;
            padding: 10px 18px; font-size: 14px; font-weight: 600;
            border-radius: 6px; cursor: pointer; transition: background-color 0.15s ease;
        }
        .btn:hover { background-color: var(--primary-hover); }
        .btn:disabled { background-color: #94a3b8; cursor: not-allowed; }
        .status-container { font-size: 14px; color: var(--text-muted); margin-top: 10px; }
        table {
            width: 100%; border-collapse: collapse; background: var(--card-bg);
            border: 1px solid var(--border-color); border-radius: 8px; overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        th, td { padding: 14px 16px; text-align: left; border-bottom: 1px solid var(--border-color); font-size: 14px; }
        th { background-color: #f8fafc; font-weight: 600; color: #475569; }
        tr:last-child td { border-bottom: none; }
        .col-lp { width: 50px; text-align: center; color: var(--text-muted); }
        .col-price { width: 160px; font-variant-numeric: tabular-nums; }
        .col-time { width: 160px; color: var(--text-muted); font-size: 13px; }
        .col-status { width: 140px; text-align: center; }
        .price-link { color: #047857; font-weight: 600; text-decoration: underline; }
        .price-link:hover { color: #065f46; }
        .badge {
            display: inline-block; padding: 4px 10px; font-size: 12px;
            font-weight: 600; border-radius: 9999px; text-align: center;
        }
        .badge-success { background-color: var(--success-bg); color: var(--success-text); }
        .badge-warning { background-color: var(--warning-bg); color: var(--warning-text); }
        .badge-danger  { background-color: var(--danger-bg);  color: var(--danger-text); }
        .badge-info    { background-color: var(--info-bg);    color: var(--info-text); }
        .badge-loading { background-color: #e0f2fe; color: #0369a1; }
        .text-muted    { color: var(--text-muted); }
    </style>
</head>
<body>

<div class="container">
    <div class="nav-links">
        <a href="index.php" class="nav-btn active">📅 Dzisiejsze ceny (<?= $todayFormatted ?>)</a>
        <a href="history.php" class="nav-btn">📊 Historia i Analiza kursów</a>
        <a href="charts.php" class="nav-btn">📈 Wykresy kursów</a>
    </div>

    <div class="panel">
        <div class="panel-header">
            <div>
                <h2 style="margin: 0 0 6px 0; font-size: 20px;">Synchronizacja cen Allegro – Dzisiaj</h2>
                <div style="font-size: 13px; color: var(--text-muted);">
                    Status połączenia: 
                    <span id="extensionStatusBadge" class="badge badge-info">Wykrywanie rozszerzenia...</span>
                </div>
            </div>

            <input type="hidden" id="extensionId" name="extensionId" value="">

            <div class="controls">
                <button type="button" id="startSyncBtn" class="btn" onclick="window.startAllegroSync && window.startAllegroSync()">Uruchom sprawdzanie cen na Allegro</button>
            </div>
        </div>

        <div class="status-container" id="progressStatus">
            Gotowe do uruchomienia synchronizacji na dzień dzisiejszy.
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th class="col-lp">Lp.</th>
                <th>Nazwa monety</th>
                <th class="col-price">Min. cena (dziś)</th>
                <th class="col-price">Maks. cena (dziś)</th>
                <th class="col-time">Godzina zapisu</th>
                <th class="col-status">Status</th>
            </tr>
        </thead>
        <tbody id="coinsTableBody">
            <?php foreach ($coins as $coin): ?>
                <?php 
                    $name = $coin['name'];
                    $isScannedToday = isset($todayPrices[$name]);
                    $hasOffers = $isScannedToday && $todayPrices[$name]['min_price'] !== null;
                    
                    $minP = $hasOffers ? (float)$todayPrices[$name]['min_price'] : null;
                    $maxP = $hasOffers ? (float)$todayPrices[$name]['max_price'] : null;
                    $minUrl = $hasOffers ? $todayPrices[$name]['min_url'] : null;
                    $maxUrl = $hasOffers ? $todayPrices[$name]['max_url'] : null;
                    
                    $scannedTime = $isScannedToday ? date('H:i:s', strtotime($todayPrices[$name]['scanned_at'])) : null;
                ?>
                <tr data-coin-name="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>">
                    <td class="col-lp"><?= htmlspecialchars((string)($coin['lp'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><strong><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></strong></td>
                    <td class="col-price min-price">
                        <?php if ($hasOffers && $minUrl): ?>
                            <a href="<?= htmlspecialchars($minUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" class="price-link">
                                <?= formatPrice($minP) ?> ↗
                            </a>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="col-price max-price">
                        <?php if ($hasOffers && $maxUrl): ?>
                            <a href="<?= htmlspecialchars($maxUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" class="price-link">
                                <?= formatPrice($maxP) ?> ↗
                            </a>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="col-time scan-time">
                        <?= $scannedTime ? htmlspecialchars($scannedTime) : '<span class="text-muted">—</span>' ?>
                    </td>
                    <td class="col-status sync-status">
                        <?php if ($hasOffers): ?>
                            <span class="badge badge-success">Zapisano dziś</span>
                        <?php elseif ($isScannedToday): ?>
                            <span class="badge badge-warning">Brak ofert</span>
                        <?php else: ?>
                            <span class="badge badge-warning">Brak z dzisiaj</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script src="app.js"></script>
</body>
</html>