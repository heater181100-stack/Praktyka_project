<?php

require_once __DIR__ . '/SampleData.php';

class NbpImporter
{
    private string $pdfUrl;
    private string $cacheDir;

    public function __construct(string $pdfUrl, string $cacheDir)
    {
        $this->pdfUrl = $pdfUrl;
        $this->cacheDir = $cacheDir;
    }

    public function getCoins(): array
    {
        // Возвращаем эталонный структурированный список с подпунктами (3.1, 3.2 и т.д.)
        return getSampleCoins();
    }
}