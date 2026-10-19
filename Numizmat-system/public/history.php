<?php
require_once __DIR__ . '/../src/SampleData.php';
require_once __DIR__ . '/../src/NbpImporter.php';

$dataDir = __DIR__ . '/../data';
$dbPath = $dataDir . '/database.sqlite';

$db = new PDO('sqlite:' . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$period = $_GET['period'] ?? '7days';

$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));

if ($period === 'yesterday') {
    $titlePeriod = "Wczoraj (" . date('d.m.Y', strtotime($yesterday)) . ")";
    $condition = "scan_date = '$yesterday'";
} elseif ($period === '30days') {
    $startDate = date('Y-m-d', strtotime('-30 days'));
    $titlePeriod = "Ostatnie 30 dni (" . date('d.m.Y', strtotime($startDate)) . " – " . date('d.m.Y') . ")";
    $condition = "scan_date >= '$startDate' AND scan_date <= '$today'";
} else {
    $period = '7days';
    $startDate = date('Y-m-d', strtotime('-7 days'));
    $titlePeriod = "Ostatnie 7 dni (" . date('d.m.Y', strtotime($startDate)) . " – " . date('d.m.Y') . ")";
    $condition = "scan_date >= '$startDate' AND scan_date <= '$today'";
}


$sql = "
    SELECT 
        coin_name,
        MIN(min_price) AS global_min,
        MAX(max_price) AS global_max,
        COUNT(scan_date) AS days_recorded
    FROM coin_price_history
    WHERE $condition AND (min_price IS NOT NULL OR max_price IS NOT NULL)
    GROUP BY coin_name
";

$stmt = $db->query($sql);
$stats = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $stats[$row['coin_name']] = $row;
}


$trendSql = "
    SELECT coin_name, scan_date, min_price, max_price
    FROM coin_price_history
    WHERE $condition AND (min_price IS NOT NULL OR max_price IS NOT NULL)
    ORDER BY coin_name ASC, scan_date ASC
";
$tStmt = $db->query($trendSql);
$allRows = $tStmt->fetchAll(PDO::FETCH_ASSOC);

$grouped = [];
foreach ($allRows as $r) {
    if ($r['min_price'] !== null) {
        $grouped[$r['coin_name']]['min'][] = (float)$r['min_price'];
    }
    if ($r['max_price'] !== null) {
        $grouped[$r['coin_name']]['max'][] = (float)$r['max_price'];
    }
}


$trendData = [];
foreach ($grouped as $cName => $prices) {
    $minChange = 0.0;
    $maxChange = 0.0;
    
    $minCount = isset($prices['min']) ? count($prices['min']) : 0;
    $maxCount = isset($prices['max']) ? count($prices['max']) : 0;

    if ($minCount > 1) {
        $firstMin = $prices['min'][0];
        $lastMin = $prices['min'][$minCount - 1];
        if ($firstMin > 0) {
            $minChange = round((($lastMin - $firstMin) / $firstMin) * 100, 1);
        }
    }

    if ($maxCount > 1) {
        $firstMax = $prices['max'][0];
        $lastMax = $prices['max'][$maxCount - 1];
        if ($firstMax > 0) {
            $maxChange = round((($lastMax - $firstMax) / $firstMax) * 100, 1);
        }
    }

    $trendData[$cName] = [
        'min_change' => $minChange,
        'max_change' => $maxChange,
        'min_count'  => $minCount,
        'max_count'  => $maxCount
    ];
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
    <title>Historia i Analiza Notowań – Numizmatyka NBP</title>
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
            --danger-bg: #fee2e2;
            --danger-text: #991b1b;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background-color: var(--bg-color);
            color: var(--text-main);
            margin: 0;
            padding: 30px 20px;
        }

        .container {
            max-width: 1240px;
            margin: 0 auto;
        }

        .nav-links {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }

        .nav-btn {
            text-decoration: none;
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            color: var(--text-main);
            background: #e2e8f0;
            transition: all 0.2s;
        }

        .nav-btn.active {
            background: var(--primary);
            color: #ffffff;
        }

        .panel {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 20px 24px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }

        .period-tabs {
            display: flex;
            gap: 8px;
        }

        .tab-btn {
            text-decoration: none;
            padding: 6px 14px;
            font-size: 13px;
            font-weight: 600;
            border-radius: 6px;
            border: 1px solid var(--border-color);
            color: var(--text-main);
            background: #ffffff;
        }

        .tab-btn.active {
            background: #0f172a;
            color: #ffffff;
            border-color: #0f172a;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }

        th, td {
            padding: 14px 16px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
            font-size: 14px;
        }

        th {
            background-color: #f8fafc;
            font-weight: 600;
            color: #475569;
        }

        .col-price {
            width: 140px;
            font-variant-numeric: tabular-nums;
        }

        .col-trend {
            width: 150px;
            text-align: center;
        }

        .trend-badges-container {
            display: flex;
            flex-direction: column;
            gap: 6px;
            align-items: center;
        }

        .badge-trend-up {
            background: var(--success-bg);
            color: var(--success-text);
            padding: 3px 8px;
            border-radius: 4px;
            font-weight: 600;
            font-size: 12px;
            width: 90px;
            text-align: left;
        }

        .badge-trend-down {
            background: var(--danger-bg);
            color: var(--danger-text);
            padding: 3px 8px;
            border-radius: 4px;
            font-weight: 600;
            font-size: 12px;
            width: 90px;
            text-align: left;
        }

        .badge-neutral {
            background: #f1f5f9;
            color: #475569;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 12px;
            width: 90px;
            text-align: left;
        }

        .text-muted { color: var(--text-muted); }
    </style>
</head>
<body>

<div class="container">
    <div class="nav-links">
        <a href="index.php" class="nav-btn">📅 Dzisiejsze notowania</a>
        <a href="history.php" class="nav-btn active">📊 Historia i Analiza kursów</a>
        <a href="charts.php" class="nav-btn">📈 Wykresy kursów</a>
    </div>

    <div class="panel">
        <div>
            <h2 style="margin: 0 0 4px 0; font-size: 20px;">Raport historyczny: <?= htmlspecialchars($titlePeriod) ?></h2>
            <div style="font-size: 13px; color: var(--text-muted);">
                Algorytmy agregujące zakresy cen i kierunek dynamiki rynkowej (osobno dla cen maksymalnych i minimalnych)
            </div>
        </div>

        <div class="period-tabs">
            <a href="history.php?period=yesterday" class="tab-btn <?= $period === 'yesterday' ? 'active' : '' ?>">Wczoraj</a>
            <a href="history.php?period=7days" class="tab-btn <?= $period === '7days' ? 'active' : '' ?>">Ostatnie 7 dni</a>
            <a href="history.php?period=30days" class="tab-btn <?= $period === '30days' ? 'active' : '' ?>">Ostatni miesiąc</a>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width: 50px; text-align: center;">Lp.</th>
                <th>Nazwa monety</th>
                <th class="col-price">Najniższa cena</th>
                <th class="col-price">Najwyższa cena</th>
                <th style="width: 100px; text-align: center;">Dni z ceną</th>
                <th class="col-trend">Trend (Max / Min)</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($coins as $coin): ?>
                <?php 
                    $name = $coin['name'];
                    $hasStats = isset($stats[$name]);
                    $minP = $hasStats ? $stats[$name]['global_min'] : null;
                    $maxP = $hasStats ? $stats[$name]['global_max'] : null;
                    $days = $hasStats ? $stats[$name]['days_recorded'] : 0;
                    $trend = $trendData[$name] ?? null;
                ?>
                <tr>
                    <td style="text-align: center; color: var(--text-muted);"><?= htmlspecialchars((string)($coin['lp'] ?? '—')) ?></td>
                    <td><strong><?= htmlspecialchars($name) ?></strong></td>
                    <td class="col-price"><?= formatPrice($minP) ?></td>
                    <td class="col-price"><?= formatPrice($maxP) ?></td>
                    <td style="text-align: center; color: var(--text-muted);">
                        <?= $days > 0 ? "<strong>$days</strong> dni" : '—' ?>
                    </td>
                    <td class="col-trend">
                        <div class="trend-badges-container">

                            <?php if ($trend && $trend['max_count'] > 1): ?>
                                <?php if ($trend['max_change'] > 0): ?>
                                    <span class="badge-trend-up">Max: ▲ +<?= $trend['max_change'] ?>%</span>
                                <?php elseif ($trend['max_change'] < 0): ?>
                                    <span class="badge-trend-down">Max: ▼ <?= $trend['max_change'] ?>%</span>
                                <?php else: ?>
                                    <span class="badge-neutral">Max: ■ 0.0%</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="badge-neutral">Max: —</span>
                            <?php endif; ?>


                            <?php if ($trend && $trend['min_count'] > 1): ?>
                                <?php if ($trend['min_change'] > 0): ?>
                                    <span class="badge-trend-up">Min: ▲ +<?= $trend['min_change'] ?>%</span>
                                <?php elseif ($trend['min_change'] < 0): ?>
                                    <span class="badge-trend-down">Min: ▼ <?= $trend['min_change'] ?>%</span>
                                <?php else: ?>
                                    <span class="badge-neutral">Min: ■ 0.0%</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="badge-neutral">Min: —</span>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

</body>
</html>