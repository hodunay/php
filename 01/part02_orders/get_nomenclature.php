<?php 

function getNomenclature(string $path = null): array {

    if (!file_exists($path)) {
        logger("Файл номенклатуры 1С не найден!", "CRITICAL");
        exit("Ошибка: Файл 1С не найден.\n");
    }

    logger("Начало загрузки номенклатуры 1С...");

    $nomenclature = [];

    if (($handle = fopen($path, "r")) !== false) {
        // Чтение заголовков с дефолтным экранированием для PHP 8.4+
        $headers = fgetcsv($handle, 0, ";", '"', "\\"); 
        
        while (($data = fgetcsv($handle, 0, ";", '"', "\\")) !== false) {
            if (count($data) < 6) {
                logger("Пропущена неполная строка в CSV 1С: " . implode(',', $data), "WARNING");
                continue;
            }
            
            // Фикс BOM-маркера для первой строки файла
            $sku = trim($data[1]);
            if (strpos($sku, "\xEF\xBB\xBF") === 0) {
                $sku = substr($sku, 3);
            }
            $sku = strtoupper(trim($sku)); // Приводим к верхнему регистру для надежности синхронизации
            
            $name = trim($data[2]);
            $priceStr = str_replace([' ', ','], ['', '.'], $data[4]);
            $stockStr = str_replace([' ', ','], ['', '.'], $data[5]);
            
            $nomenclature[$sku] = [
                'name'  => $name,
                'price' => (float)$priceStr,
                'stock' => (float)$stockStr
            ];
        }
        fclose($handle);
    }

    logger("Загружено " . count($nomenclature) . " уникальных позиций из 1С.");

    return $nomenclature;
}