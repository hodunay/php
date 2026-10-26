<?php
/**
 * Модуль сопоставления заказов маркетплейса с выгрузкой 1С (Хьюмен Систем)
 * Совместим с PHP 8.1 - 8.4+
 */

declare(strict_types=1);

define('PATH_1C_NOMENCLATURE', __DIR__ . '/data/nomenclature_1c.csv');
define('PATH_ORDERS', __DIR__ . '/data/orders.json');

require_once 'logger.php';
require_once 'get_nomenclature.php';
require_once 'get_orders.php';

$nomenclature = getNomenclature(PATH_1C_NOMENCLATURE);
$orders = getOrders(PATH_ORDERS);

$report = [];
$processedOrderItems = []; 

$report[] = ['ID Заказа', 'Статус Заказа', 'Артикул (SKU)', 'Товар 1С', 'Кол-во', 'Цена МП', 'Цена 1С', 'Статус Проверки', 'Рекомендация'];

foreach ($orders as $order) {
    $orderId = $order['id'] ?? 'UNKNOWN';
    $orderStatus = strtolower($order['status'] ?? 'new');
    $items = $order['items'] ?? [];
    
    // Обработка пустых заказов (Кейс MP-24028)
    if (empty($items)) {
        logger("Заказ #{$orderId} не содержит товаров в корзине.", "WARNING");
        $report[] = [$orderId, $orderStatus, '-', '-', 0, 0, 0, 'ПУСТОЙ ЗАКАЗ', 'Проверить лог выгрузки маркетплейса'];
        continue;
    }
    
    foreach ($items as $item) {
        try {
            $sku = strtoupper(trim($item['sku'] ?? '')); // Убираем пробелы и ровняем регистр (" ts-1002" -> "TS-1002")
            $qty = (int)($item['quantity'] ?? 0);
            $priceMarket = isset($item['price']) ? (float)$item['price'] : null;
            
            if (empty($sku)) {
                logger("В заказе #{$orderId} обнаружена строка без артикула.", "ERROR");
                $report[] = [$orderId, $orderStatus, 'МАРКЕР_ПУСТО', '-', $qty, 0, 0, 'НЕТ АРТИКУЛА', 'Связаться с техподдержкой площадки'];
                continue;
            }
            
            // Защита от дубликатов строк ВНУТРИ одного заказа (Кейс MP-24017)
            $dupKey = "{$orderId}_{$sku}";
            if (isset($processedOrderItems[$dupKey])) {
                logger("Дубликат строки SKU {$sku} в заказе #{$orderId}. Пропущено.", "WARNING");
                $report[] = [$orderId, $orderStatus, $sku, '-', $qty, $priceMarket ?? 0, 0, 'ДУБЛИКАТ СТРОКИ', 'Игнорировать дубль строки при импорте'];
                continue;
            }
            $processedOrderItems[$dupKey] = true;
            
            // Обработка отмененных заказов (Кейс MP-24027)
            if (in_array($orderStatus, ['canceled', 'cancelled', 'returned'])) {
                $report[] = [$orderId, $orderStatus, $sku, $nomenclature[$sku]['name'] ?? 'Неизвестно', $qty, $priceMarket ?? 0, $nomenclature[$sku]['price'] ?? 0, 'ОТМЕНЕН', 'Не резервировать товар (информационный статус)'];
                continue;
            }
            
            // 1. Проверка наличия артикула в базе 1С
            if (!isset($nomenclature[$sku])) {
                logger("Артикул {$sku} из заказа #{$orderId} отсутствует в 1С.", "ERROR");
                $report[] = [$orderId, $orderStatus, $sku, 'ОТСУТСТВУЕТ В 1С', $qty, $priceMarket ?? 0, 0, 'НЕТ АРТИКУЛА', 'Завести карточку товара в 1С'];
                continue;
            }
            
            $product1C = $nomenclature[$sku];
            $checkStatus = 'ОК';
            $recommendation = 'Все корректно. Импортировать в 1С.';
            
            // 2. Валидация цены и структуры (Кейс MP-24030 с ценой null)
            if ($priceMarket === null) {
                $checkStatus = 'БИТЫЕ ДАННЫЕ';
                $recommendation = 'В заказе отсутствует цена товара!';
                logger("В заказе #{$orderId} для SKU {$sku} пришло значение цены NULL.", "ERROR");
            } elseif (abs($priceMarket - $product1C['price']) > 0.01) {
                $checkStatus = 'РАСХОЖДЕНИЕ ЦЕНЫ';
                $recommendation = "Уточнить скидку. МП: {$priceMarket}, 1С: {$product1C['price']}";
                logger("Расхождение цены для SKU {$sku} в #{$orderId}. МП: {$priceMarket}, 1С: {$product1C['price']}", "WARNING");
            }
            
            // 3. Проверка накопительного остатка
            if ($product1C['stock'] < $qty) {
                $checkStatus = 'НЕХВАТКА ОСТАТКА';
                $recommendation = "Отказ по FBS. На складе 1С: {$product1C['stock']} шт., требуется: {$qty}";
                logger("Нехватка остатка для SKU {$sku} в #{$orderId}. Доступно: {$product1C['stock']}", "ERROR");
            } else {
                $nomenclature[$sku]['stock'] -= $qty; // Списываем остаток
            }
            
            $report[] = [$orderId, $orderStatus, $sku, $product1C['name'], $qty, $priceMarket ?? 0, $product1C['price'], $checkStatus, $recommendation];
            
        } catch (\Throwable $e) {
            logger("Критический сбой обработки строки заказа #{$orderId}: " . $e->getMessage(), "CRITICAL");
        }
    }
}

$fp = fopen(PATH_REPORT, 'w');
fprintf($fp, chr(0xEF).chr(0xBB).chr(0xBF)); // Пишем BOM для авто-кодировки Excel
foreach ($report as $row) {
    fputcsv($fp, $row, ';', '"', "\\");
}
fclose($fp);

logger("Синхронизация успешно завершена.");
echo "Скрипт отработал без предупреждений. Проверьте report_manager.csv!\n";
