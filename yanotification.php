<?php
define("STOP_STATISTICS", true);
define('NO_AGENT_CHECK', true);
define('NOT_CHECK_PERMISSIONS', true);
define("DisableEventsCheck", true);

use Bitrix\Main\Application;
use YandexCheckout\Model\Notification\NotificationSucceeded;
use YandexCheckout\Model\Notification\NotificationWaitingForCapture;
use YandexCheckout\Model\NotificationEventType;
use YandexCheckout\Model\PaymentStatus;

require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php");
require($_SERVER["DOCUMENT_ROOT"]."/local/php_interface/lib/yandex_payment/lib/autoload.php");
// Получите данные из POST-запроса от Яндекс.Кассы
$source = file_get_contents('php://input');
$requestBody = json_decode($source, true);
global $APPLICATION;
try {
    $notification = ($requestBody['event'] === NotificationEventType::PAYMENT_SUCCEEDED)
        ? new NotificationSucceeded($requestBody)
        : new NotificationWaitingForCapture($requestBody);
} catch (Exception $e) {
    // Обработка ошибок при неверных данных
    file_put_contents($_SERVER['DOCUMENT_ROOT'] . '/upload/logs/yandexkassa.txt', date('d.m.Y H:i:s ').'Ошибка '.$e->getMessage()."\n", FILE_APPEND);
   \CEventLog::Add(array(
        "SEVERITY" => "ERROR",
        "AUDIT_TYPE_ID" => "YANDEX_NOTIFICATION",
        "MODULE_ID" => "",
        "ITEM_ID" => "",
        "DESCRIPTION" => "Ошибка уведомлений от яндекса".$e->getMessage(),
    ));
}
?>

<?php

$payment = $notification->getObject();

if ($payment->getStatus() === PaymentStatus::SUCCEEDED) {
    \Bitrix\Main\Loader::includeModule('sale');
    $yandex_id = $payment->getId();
    $result = \Bitrix\Sale\Order::getList(
        [
            'filter' => [
                'PROPERTY.CODE'  => 'ID_YANDEX',
                'PROPERTY.VALUE' => $yandex_id,
            ],
            'select' => ['ID'],

        ]
    );
    if ($order = $result->fetch()) {
        $order = \Bitrix\Sale\Order::load($order["ID"]);
        $paymentCollection = $order->getPaymentCollection();
        $onePayment = $paymentCollection[0];
        $onePayment->setPaid("Y");
        $shipmentCollection = $order->getShipmentCollection();
        foreach ($shipmentCollection as $shipment) {
            if (!$shipment->isSystem()) {
                $shipment->allowDelivery(); // разрешаем отгрузку
            }
        }
        \CEventLog::Add(array(
            "SEVERITY" => "SECURITY",
            "AUDIT_TYPE_ID" => "YANDEX_NOTIFICATION",
            "MODULE_ID" => "",
            "ITEM_ID" => $order->getId(),
            "DESCRIPTION" => "Оплата в яндекс кассе прошла успешно ID заказа ".$order->getId(),
        ));
        $order->save();

    }
}
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/epilog_after.php");
?>
