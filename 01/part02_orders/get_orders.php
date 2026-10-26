<?php 

function getOrders(string $path = null): array {
    logger("Начало загрузки заказов маркетплейса...");
    if (!file_exists($path)) {
        logger("Файл заказов orders.json не найден!", "CRITICAL");
        exit("Ошибка: Файл заказов не найден.\n");
    }

    $ordersRaw = json_decode(file_get_contents($path), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        logger("Ошибка JSON в файле заказов: " . json_last_error_msg(), "CRITICAL");
        exit("Ошибка: Битый JSON.\n");
    }

    $orders = $ordersRaw['data']['orders'] ?? [];
    logger("Найдено " . count($orders) . " заказов в пакете API.");
    
    return $orders;
}