<?php
require_once __DIR__ . '/../src/SampleData.php';
require_once __DIR__ . '/../src/NbpImporter.php';

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

$todayFormatted = date('d.m.Y');
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Wykresy kursów monet – Numizmatyka NBP</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --bg-color: #f8fafc;
            --card-bg: #ffffff;
            --border-color: #e2e8f0;
            --text-main: #1e293b;
            --text-muted: #64748b;
            --primary: #0284c7;
            --primary-hover: #0369a1;
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
            margin-bottom: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }

        .select-group {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 14px;
        }

        .form-select {
            padding: 10px 14px;
            font-size: 15px;
            border-radius: 6px;
            border: 1px solid #cbd5e1;
            background-color: #ffffff;
            color: var(--text-main);
            min-width: 380px;
            max-width: 100%;
            cursor: pointer;
            outline: none;
        }

        .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.15);
        }

        .chart-panel {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }

        .stats-row {
            display: flex;
            gap: 14px;
            flex-wrap: wrap;
            margin-bottom: 24px;
        }

        .stat-card {
            background: #f8fafc;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            padding: 12px 18px;
            flex: 1;
            min-width: 160px;
        }

        .stat-label {
            font-size: 12px;
            color: var(--text-muted);
            display: block;
            margin-bottom: 4px;
        }

        .stat-value {
            font-size: 18px;
            font-weight: 700;
            color: var(--text-main);
        }

        .canvas-wrapper {
            position: relative;
            height: 420px;
            width: 100%;
        }

        .no-data-box {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-muted);
            font-size: 15px;
            background: #f8fafc;
            border-radius: 6px;
            border: 1px dashed var(--border-color);
        }
    </style>
</head>
<body>

<div class="container">
    <div class="nav-links">
        <a href="index.php" class="nav-btn">📅 Dzisiejsze notowania</a>
        <a href="history.php" class="nav-btn">📊 Historia i Analiza kursów</a>
        <a href="charts.php" class="nav-btn active">📈 Wykresy kursów</a>
    </div>

    <div class="panel">
        <h2 style="margin: 0 0 6px 0; font-size: 20px;">Wykresy wahań kursów monet</h2>
        <div style="font-size: 13px; color: var(--text-muted);">
            Wybierz monetę z listy rozwijanej, aby zobaczyć historyczne zmiany cen minimalnych i maksymalnych na wykresie.
        </div>

        <div class="select-group">
            <label for="coinSelect" style="font-weight: 600; font-size: 14px;">Wybierz monetę z listy:</label>
            <select id="coinSelect" class="form-select" onchange="loadCoinChart(this.value)">
                <?php foreach ($coins as $coin): ?>
                    <option value="<?= htmlspecialchars($coin['name'], ENT_QUOTES, 'UTF-8') ?>">
                        <?= htmlspecialchars(($coin['lp'] ? $coin['lp'] . '. ' : '') . $coin['name'], ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="chart-panel">
        <h3 id="chartCoinTitle" style="margin: 0 0 16px 0; font-size: 18px;">Ładowanie...</h3>

        <div class="stats-row" id="statsRow" style="display: none;">
            <div class="stat-card">
                <span class="stat-label">Najniższa cena (min)</span>
                <span class="stat-value" id="statMin">—</span>
            </div>
            <div class="stat-card">
                <span class="stat-label">Najwyższa cena (max)</span>
                <span class="stat-value" id="statMax">—</span>
            </div>
            <div class="stat-card">
                <span class="stat-label">Średnia cena minimalna</span>
                <span class="stat-value" id="statAvg">—</span>
            </div>
            <div class="stat-card">
                <span class="stat-label">Liczba odczytów</span>
                <span class="stat-value" id="statCount">—</span>
            </div>
        </div>

        <div id="noDataMessage" class="no-data-box" style="display: none;">
            Brak zapisanych punktów pomiarowych dla tej monety.<br>
            Uruchom synchronizację cen na stronie głównej, aby dodać pierwsze pomiary.
        </div>

        <div class="canvas-wrapper" id="canvasWrapper">
            <canvas id="coinChart"></canvas>
        </div>
    </div>
</div>

<script>
let chartInstance = null;

async function loadCoinChart(coinName) {
    const titleEl = document.getElementById('chartCoinTitle');
    const statsRow = document.getElementById('statsRow');
    const noDataEl = document.getElementById('noDataMessage');
    const canvasWrapper = document.getElementById('canvasWrapper');
    const canvas = document.getElementById('coinChart');

    titleEl.textContent = coinName;

    if (chartInstance) {
        chartInstance.destroy();
        chartInstance = null;
    }

    try {
        const response = await fetch('api.php?action=coin_history&coin_name=' + encodeURIComponent(coinName));
        const result = await response.json();

        if (!result.history || result.history.length === 0) {
            statsRow.style.display = 'none';
            canvasWrapper.style.display = 'none';
            noDataEl.style.display = 'block';
            return;
        }

        const history = result.history;
        const labels = history.map(item => item.scan_date);
        const minPrices = history.map(item => item.min_price);
        const maxPrices = history.map(item => item.max_price);

        const validMins = minPrices.filter(p => p !== null);
        const validMaxs = maxPrices.filter(p => p !== null);

        const minVal = validMins.length ? Math.min(...validMins) : 0;
        const maxVal = validMaxs.length ? Math.max(...validMaxs) : 0;
        const avgVal = validMins.length ? (validMins.reduce((a, b) => a + b, 0) / validMins.length) : 0;

        document.getElementById('statMin').textContent = minVal.toLocaleString('pl-PL', { minimumFractionDigits: 2 }) + ' zł';
        document.getElementById('statMax').textContent = maxVal.toLocaleString('pl-PL', { minimumFractionDigits: 2 }) + ' zł';
        document.getElementById('statAvg').textContent = avgVal.toLocaleString('pl-PL', { minimumFractionDigits: 2 }) + ' zł';
        document.getElementById('statCount').textContent = history.length + ' dni';

        statsRow.style.display = 'flex';
        noDataEl.style.display = 'none';
        canvasWrapper.style.display = 'block';

        const ctx = canvas.getContext('2d');
        chartInstance = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Min. cena (zł)',
                        data: minPrices,
                        borderColor: '#10b981',
                        backgroundColor: 'rgba(16, 185, 129, 0.1)',
                        borderWidth: 3,
                        pointBackgroundColor: '#10b981',
                        pointRadius: 6,
                        pointHoverRadius: 8,
                        tension: 0.2,
                        fill: false
                    },
                    {
                        label: 'Maks. cena (zł)',
                        data: maxPrices,
                        borderColor: '#0284c7',
                        backgroundColor: 'rgba(2, 132, 199, 0.1)',
                        borderWidth: 3,
                        pointBackgroundColor: '#0284c7',
                        pointRadius: 6,
                        pointHoverRadius: 8,
                        tension: 0.2,
                        fill: false
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                        labels: { font: { size: 13, weight: 'bold' } }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.dataset.label + ': ' + Number(context.raw).toLocaleString('pl-PL', { minimumFractionDigits: 2 }) + ' zł';
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        ticks: {
                            callback: function(val) { return val + ' zł'; }
                        },
                        grid: { color: '#e2e8f0' }
                    },
                    x: {
                        grid: { color: '#f1f5f9' }
                    }
                }
            }
        });
    } catch (e) {
        console.error(e);
        noDataEl.textContent = 'Błąd podczas pobierania danych notowań.';
        noDataEl.style.display = 'block';
        statsRow.style.display = 'none';
        canvasWrapper.style.display = 'none';
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const select = document.getElementById('coinSelect');
    if (select && select.value) {
        loadCoinChart(select.value);
    }
});
</script>

</body>
</html>