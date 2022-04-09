<?php

include "vendor/autoload.php";

use Dotenv\Dotenv;
use PhpOffice\PhpSpreadsheet\Exception as PhpSpreadsheetException;
use PhpOffice\PhpSpreadsheet\IOFactory;

const LOG_FOLDER_ROOT = 'log';      // Название папки для хранения логов
const FIELD_NAME_SIZE = 'size';     // Поле содержащий размер присланного файла

const FILENAME_SPB = 'spbFile';     // Наименование файла в теле POST
const RECIPIENT_SPB = 'ozon_spb';   // Используется в XML (в 'claim_id')

const FILENAME_MSK = 'mskFile';
const RECIPIENT_MSK = 'ozon_msk';

const REFRESH_CLAIMS_TABLE_TIME = 2 * 60 * 60; // Через сколько времени считать таблицу неактуальной и пересоздать данные для нее

const WAREHOUSE_PRICE_LIST_FILE = 'candy_pricing.xml'; // Название файла с ценами и количеством на фтп

const RESULT_FILENAME = 'candy_order_xml_test.xml'; // С таким именем файл зальется на ФТП #TODO убрать 'test_'
const MAX_OLD_DATE = '-14 days'; // Используется в функции strtotime. Максимальное количество дней для хранения 'shippment_claim'

const OLD_XML_FILENAME = 'old_' . RESULT_FILENAME;  // Произвольное имя временного файла (прошлый xml файл с ФТП)
const TEMP_ROOT_ELEMENT = 'temp';                   // Произвольное имя элемента временного файла
const GEN_FOLDER = 'gen';                           // Произвольное имя папки, где будем хранить сгенерированные файлы
const ALL_CLAIMS_COPY_PATH = __DIR__ . DIRECTORY_SEPARATOR . GEN_FOLDER . DIRECTORY_SEPARATOR . 'all_claims.txt';

$alertClass = "danger";     // Цвет блока alert
$alertMsg = "";             // Содержание блока alert

$warehouseMsg = "";         // Содержание для сообщения в Popup-окне в случае несоответствия с ассортиментом
$localXmlPath = "";         // Адрес где будет храниться созданный файл для передачи на фтп

$currentTime = new DateTime();
$modifyDays = 1;
if (date("H") > 15 || (date("H") == 15 && date("i") > 30)) {
    $modifyDays = 2;
}

// Выполнение преднастроек скрипта

preSettings();

// ---------------------------------------------- Отправлена форма

if (isset($_POST['submit-hard'])) {     // Форма из Popup-окна с подтверждением отправки файла несмотря на несоответствия с ассортиментом

    logMsg("В форме Popup окна решили отправить Xml несмотря на несоответствия с ассортиментом");

    execDespiteWarning($alertClass, $alertMsg); // Загрузить уже готовый с прошлого раза Xml

} elseif (isset($_POST['delete'])) {    // Кнопка удаления

    logStartDelete(); // Логирует старт работы с присланными данными

    deleteMain($alertClass, $alertMsg); // Вызов главной функции

} elseif (isset($_POST['submit'])) {    // Основная форма

    logStartMain(); // Логирует старт работы с присланными данными

    main($alertClass, $alertMsg, $warehouseMsg, $localXmlPath); // Вызов главной функции
}

// ---------------------------------------------- Формирование таблицы

$allClaims = getAllClaims();

// ---------------------------------------------- Функции

/**
 * Выполнение преднастроек скрипта
 *
 * @return void
 *
 * @throws ErrorException
 */
function preSettings(): void
{
// Из файла .env берем значения для FTP соединения

    $dotenv = Dotenv::createImmutable(__DIR__);
    $dotenv->load();

// Установка часового пояса как в примере (где бы не выполнялся скрипт - одинаковое время)

    date_default_timezone_set('Europe/Moscow');

// Преобразуют Warning в Exception. Ошибки Ftp могут выкидывать Warning. Имплементировано для логирования содержимого

    set_error_handler(function ($err_severity, $err_msg, $err_file, $err_line, array $err_context) {
        throw new ErrorException($err_msg, 0, $err_severity, $err_file, $err_line);
    }, E_WARNING);
}

/**
 * Возвращает массив с актуальными shipment claims.
 *
 * @return array Каждый shipment claim представлен ассоц. массивом с ключами: date, recipient, productCount
 */
function getAllClaims(): array
{
    try {
        // Обновление данных для таблицы с shipment claims. Создаем файл, если нет или обновляем файл, если устаревший

        if (!file_exists(ALL_CLAIMS_COPY_PATH) || (time() - filemtime(ALL_CLAIMS_COPY_PATH) > REFRESH_CLAIMS_TABLE_TIME)) {
            updateClaimsTable();
        }

        // Читаем файл, возвращаем массив

        if (file_exists(ALL_CLAIMS_COPY_PATH)) {
            return unserialize(file_get_contents(ALL_CLAIMS_COPY_PATH));
        }
    } catch (Exception $e) {
        logMsg("При создании обновлении таблицы получили Exception: " . $e->getMessage());
    }

    // Если все равно не создался - странно и плохо, но не причина совсем не работать

    logMsg("Совсем не получается создать файл со всеми shipment claims: " . ALL_CLAIMS_COPY_PATH);
    return [];
}

/**
 * Главная функция
 *
 * @return void
 */
function main(&$alertClass, &$alertMsg, &$warehouseMsg, &$localXmlPath)
{
    try {
        // Адрес где будет храниться временный созданный файл Xml для передачи на фтп
        $localXmlPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . RESULT_FILENAME;

        // Проверки на отсутствие присланных данных
        if (!$_POST["date"]) {
            throw new Exception("Не указана дата отгрузки");
        }

        if ((!isset($_FILES[FILENAME_SPB]) || 0 == $_FILES[FILENAME_SPB][FIELD_NAME_SIZE]) &&
            (!isset($_FILES[FILENAME_MSK]) || 0 == $_FILES[FILENAME_MSK][FIELD_NAME_SIZE])) {
            throw new Exception("Ни одна таблица не прислана");
        }

        // Создание и отправка XML

        $receivedExcels = [];

        if (isset($_FILES[FILENAME_SPB]) && $_FILES[FILENAME_SPB][FIELD_NAME_SIZE] > 0) {
            $receivedExcels[] = [FILENAME_SPB, RECIPIENT_SPB];
        }

        if (isset($_FILES[FILENAME_MSK]) && $_FILES[FILENAME_MSK][FIELD_NAME_SIZE] > 0) {
            $receivedExcels[] = [FILENAME_MSK, RECIPIENT_MSK];
        }

        if (!empty($receivedExcels)) {
            processData($receivedExcels, $localXmlPath, $warehouseMsg);
        }

        if (empty($warehouseMsg)) {
            $alertClass = "success";
            $alertMsg = "Скрипт отработал без ошибок. Количество полученных файлов: " . count($receivedExcels);
            updateClaimsTable();
        } else {
            $alertClass = "warning";
            $alertMsg = "С прошлыми файлами были несоответствия с ассортиментом. Вы выбрали не отправлять их";
        }

    } catch (DOMException|PhpSpreadsheetException $e) {
        logMsg($e->getMessage());
        http_response_code(400);
        $alertMsg = "Прислана таблица с несоответствующим содержанием";
    } catch (Exception $e) {
        http_response_code(400);
        $alertMsg = $e->getMessage();
    }

    if (empty($warehouseMsg)) { // Случай когда не пусто - пользователь не увидит, если клацнет "Все равно отправить"
        logMsg("Пользователю высветилось в блоке alert: $alertMsg");
    }
}

/**
 * Загрузить на фтп уже готовый Xml
 *
 * Вызывается после первичной отработки скрипта, уже с готовой ссылкой на Xml (из Post берется)
 *
 * @param $alertClass
 * @param $alertMsg
 *
 * @return void
 */
function execDespiteWarning(&$alertClass, &$alertMsg)
{
    try {
        if (isset($_POST["readyXml"])) {
            uploadToFtp(RESULT_FILENAME, $_POST["readyXml"]);
            logMsg("Xml отправлен без ошибок");
            $alertMsg = "Скрипт отработал без ошибок";
            $alertClass = "success";
            updateClaimsTable();
        } else {
            logMsg("Почему-то через Popup форму не получили локальный путь к уже готовому Xml");
            $alertMsg = "Что-то пошло не так. Обратитесь в поддержку";
        }
    } catch (Exception $e) {
        http_response_code(400);
        $alertMsg = $e->getMessage();
    }
}

/**
 * Обрабатывает таблицы, формирует xml, заливает на FTP
 *
 * @param array $receivedExcels Массив, каждый элемент которого содержит [$fileName, $recipient] (имя файла в POST и claim_id)
 * @param string $localXmlPath Адрес где будет храниться созданный файл для передачи на фтп
 * @param string $warehouseMsg Уведомляет о несоответствии с файлом ассортимента
 *
 * @return void
 *
 * @throws DOMException
 * @throws PhpSpreadsheetException
 * @throws Exception Тут должны быть только исключения только явно вызванные в коде
 */
function processData(array $receivedExcels, string &$localXmlPath, string &$warehouseMsg)
{
    // Получение искомого массива из экселя и старого Xml
    $allClaimsAsNodes = getAllClaimsAsNodes($receivedExcels, $warehouseMsg);

    // Создание xml файла в указанном пути
    createXml($allClaimsAsNodes, $localXmlPath);

    // Отправка файла на FTP сервер на этом этапе, только когда не было противоречий с файлом ассортимента
    if (empty($warehouseMsg)) {
        uploadToFtp(RESULT_FILENAME, $localXmlPath);
    }
}

/**
 * Возвращает массив с всеми отсортированными и отобранными 'shippment_claim' в виде нодов (Nodes) из временного DOMDocument
 *
 * Использует при создании Excel таблицы из Post и старый Xml с фтп-сервера
 *
 * @param array $receivedExcels Массив, каждый элемент которого содержит [$fileName, $recipient] (имя файла в POST и claim_id)
 * @param string $warehouseMsg Уведомляет о несоответствии с файлом ассортимента
 *
 * @return DOMNode[]
 *
 * @throws DOMException
 * @throws ErrorException
 * @throws PhpSpreadsheetException
 */
function getAllClaimsAsNodes(array $receivedExcels, string &$warehouseMsg): array
{
    // Создание временного объекта для хранения содержимого будущего XML-файла с 'shippment_claim' без необходимых родительских категорий

    $tempDom = new DOMDocument();
    $tempDom->encoding = 'UTF-8';
    $tempDom->xmlVersion = '1.1';
    $tempDom->formatOutput = true;
    $rootTempDom = $tempDom->createElement(TEMP_ROOT_ELEMENT); // Единственный родительский элемент
    // В нем будем хранить все 'shippment_claim' (последнего родительского элемента) вместе с товарами. Таких может быть несколько
    $tempDom->appendChild($rootTempDom); // Сразу вкладываем

    // Получение массива 'shippment_claim' из загруженных экселей

    $shipmentClaimsExcels = []; // Сюда будем складывать из экселя
    foreach ($receivedExcels as $singleExcel) { // Добавляем один 'shippment_claim' для каждого загруженного экселя
        $shipmentClaimsExcels[] = excelToXmlNode($singleExcel[0], $singleExcel[1], $tempDom, $warehouseMsg);
    }

    // Получение массива 'shippment_claim' из предыдущего Xml файла с фтп-сервера

    $shipmentClaimsOldXml = processOldXml($receivedExcels); // Сюда сложили все roots3 ('shippment_claim') из файла с фтп

    // Вкладываем root3 из экселей

    foreach ($shipmentClaimsExcels as $root3) {
        $rootTempDom->appendChild($root3);
    }

    // Вкладываем root3 из предыдущего xml (без неподходящих дат)

    foreach ($shipmentClaimsOldXml as $shipmentClaim) {
        $newNode = $tempDom->importNode($shipmentClaim, true);
        $rootTempDom->appendChild($newNode);
    }

    // Собираем в массив все элементы 'shippment_claim' (тут уже из экселей и Xml) как DomNodeList
    $xpath = new DOMXpath($tempDom);
    $tempNodeList = $xpath->evaluate('/' . TEMP_ROOT_ELEMENT . '/shippment_claim');

    // Переводит наш DomNodeList в обычный массив
    $tempClaims = iterator_to_array($tempNodeList);

    // Сортируем наш массив (сортируем 'date' как обычную строку, т.к. формат одинаковый и подходящий: 'yyyy-mm-dd')
    usort($tempClaims, static function ($a, $b) {
        return strcasecmp($a->getAttribute('date'), $b->getAttribute('date'));
    }
    );

    return $tempClaims;
}

/**
 * Создает XML-файл по указанному пути
 *
 * @param array $allClaimsAsNodes Массив со всеми отсортированными и отобранными 'shippment_claim' в виде нодов (Nodes) из временного DOMDocument
 * @param string $localXmlPath Путь куда сохранить созданный файл
 *
 * @return void
 *
 * @throws DOMException
 */
function createXml(array $allClaimsAsNodes, string $localXmlPath): void
{
    $currentTime = new DateTime(); // Получение текущего времени

    // Создание объекта для сохранения итогового XML-файла

    $dom = new DOMDocument();
    $dom->encoding = 'UTF-8';
    $dom->xmlVersion = '1.1';
    $dom->formatOutput = true;

    // Создание и привязывание атрибутов к родительскому элементу 1

    $root1 = $dom->createElement('shippment_claims_feed');

    $attrRoot1Version = new DOMAttr('version', '1.0');
    $root1->setAttributeNode($attrRoot1Version);
    $attrRoot1Mode = new DOMAttr('mode', 'feed');
    $root1->setAttributeNode($attrRoot1Mode);
    $attrRoot1Timestamp = new DOMAttr('timestamp', $currentTime->format('U'));
    $root1->setAttributeNode($attrRoot1Timestamp);
    $attrRoot1GeneratedAt = new DOMAttr('generated_at', $currentTime->format('D M d Y H:i:s \G\M\TO (T)'));
    $root1->setAttributeNode($attrRoot1GeneratedAt);

    // Создание и привязывание атрибутов к родительскому элементу 2

    $root2 = $dom->createElement('shippment_claims');

    $attrRoot2ClientId = new DOMAttr('client_id', 'FIM');
    $root2->setAttributeNode($attrRoot2ClientId);
    $attrRoot2SupplierId = new DOMAttr('supplier_id', 'Candy');
    $root2->setAttributeNode($attrRoot2SupplierId);
    $attrRoot2SupplierId = new DOMAttr('set_id', 'candy_sku_group');
    $root2->setAttributeNode($attrRoot2SupplierId);

    // Вложение родительских элементов в соответствующем порядке: 1 - самый верхний, 2-ой вложен в 1-ый, 3-ие во 2-ой

    $dom->appendChild($root1);
    $root1->appendChild($root2);
    foreach ($allClaimsAsNodes as $tempClaim) {
        $newNode = $dom->importNode($tempClaim, true);
        $root2->appendChild($newNode);
    }

    // Сохранение файла во временную папку

    $dom->save($localXmlPath);
}

/**
 * Возвращает массив со всеми 'shippment_claim' (только нужных дат) из xml файла с фтп сервера
 *
 * @param array $receivedExcels Массив, каждый элемент которого содержит [$fileName, $recipient] (имя файла в POST и claim_id)
 *
 * @return array
 *
 * @throws ErrorException
 */
function processOldXml(array $receivedExcels): array
{
    // Выкачиваем файл с фтп
    $localFilePathToOldXml = sys_get_temp_dir() . DIRECTORY_SEPARATOR . OLD_XML_FILENAME; // Адрес куда временно запишем старый Xml
    if (!getFileFromFtp(RESULT_FILENAME, $localFilePathToOldXml)) {
        return [];
    }

    // Получаем DOMDocument из файла

    $oldXml = new DOMDocument();
    $oldXml->load($localFilePathToOldXml);

    // Нам нужно вернуть только те 'shippment_claim', в которых аттрибут дата не позднее определенной даты
    // И если на присланную дату в присланном складе будет в старом Xml запись - ее не возвращать

    $returnArray = []; // Список для возвращения из функции

    $countDeleted = 0; // Используется для логирования количества неподходящих по дате shippment_claim из старого файла

    $oldXmlClaims = $oldXml->getElementsByTagName('shippment_claim');

    $minRelevantDate = date('Y-m-d', strtotime(MAX_OLD_DATE)); // Все что раньше этой даты не возвращать

    $recipientList = []; // Элемент - значение 'recipient' на каждый из присланных экселей
    foreach ($receivedExcels as $singleReceivedExcel) {
        $recipientList[] = $singleReceivedExcel[1];
    }

    // Одновременно с обработкой старого Xml обновим наш список со всеми shipment claim

    $allClaims = []; // Массив каждый элемент которого относится к конкретному shipment claim с ключами: date, recipient, productCount

    // Обработка старого Xml

    foreach ($oldXmlClaims as $oldXmlClaim) {

        // Получение значений

        $claimProductCount = $oldXmlClaim->getElementsByTagName('product')->length; // Количество товаров внутри
        $claimDate = $oldXmlClaim->getAttribute('date');
        $claimRecipient = $oldXmlClaim->getAttribute('recipient');

        // Обновление списка со всеми shipment claim

        $allClaims[] = ['date' => $claimDate, 'recipient' => $claimRecipient, 'productCount' => $claimProductCount];

        // Формирование массива для нового Xml только с нужными shipment claim

        if ($claimDate > $minRelevantDate) { // Здесь убираем все, которые меньше даты
            // Убираем, если на эту же дату и склад в прошлом XML была запись
            if (!($claimDate == $_POST['date'] && in_array($claimRecipient, $recipientList))) {
                $returnArray[] = $oldXmlClaim;
                continue;
            }
        }
        $countDeleted++; // Сюда добираемся всегда, когда не добрались до строки с добавлением в массив
    }

    // Сохранение актуального списка со всеми shipment claim

    saveAllClaims($allClaims);

    // Завершение работы функции

    logMsg("Из старого файла не использовано: $countDeleted");
    return $returnArray;
}

/**
 * Создание 'shippment_claim' (последнего родительского элемента xml) вместе с товарами из загруженного экселя
 *
 * @param string $fileName Имя файла с таблицей из тела Post-запроса
 * @param string $recipient Используется в XML (в 'claim_id')
 * @param DOMDocument $dom Содержимое формируемого нашего нового XML файла
 * @param string $warehouseMsg Уведомляет о несоответствии с файлом ассортимента
 *
 * @return DOMElement
 *
 * @throws DOMException
 * @throws ErrorException
 * @throws PhpSpreadsheetException
 */
function excelToXmlNode(string $fileName, string $recipient, DOMDocument &$dom, string &$warehouseMsg): DOMElement
{
    $date = $_POST['date']; // Полученная дата отгрузки

    // Выкачиваем файл с наличием товаров с фтп

    $localFilePathToPriceList = sys_get_temp_dir() . DIRECTORY_SEPARATOR . WAREHOUSE_PRICE_LIST_FILE; // Адрес куда временно запишем прайслист

    if (getFileFromFtp(WAREHOUSE_PRICE_LIST_FILE, $localFilePathToPriceList)) {
        $warehouseMsg = $warehouseMsg . 'Файл на фтп с прайс-листом отсутствует. Нет возможности перепроверить наличие';
    }

    // Получаем DOMDocument из файла

    $priceListDom = new DOMDocument();
    $priceListDom->load($localFilePathToPriceList);

    // Получаем DomNodeList со всеми товарами со склада (содержит наличие, цены и т.д.)

    $warehouseOffers = $priceListDom->getElementsByTagName('offer');

    // Делаем ассоциативный массив с SKU и количеством товара

    $stock = [];

    foreach ($warehouseOffers as $offer) {
        $offerSku = $offer->getAttribute('xmlId');
        if (array_key_exists($offerSku, $stock)) { // Проверка на случай ошибки в файле на фтп
            logMsg("В файле склада (" . WAREHOUSE_PRICE_LIST_FILE . ") повторяется sku: $offerSku");
        }
        $stock[$offerSku] = $offer->getAttribute('stock_mow');
    }

    // Массив с Sku проблемных товаров из экселя:
    $notFoundSkus = []; // Такие же Sku не найдены в скачанной таблице ассортимента
    $notLeftSkus = [];  // Количество в ассортименте было 0

    // Обработка полученной Excel таблицы

    $spreadsheet = IOFactory::load($_FILES[$fileName]["tmp_name"]);
    $worksheet = $spreadsheet->getActiveSheet();

    // Создание и привязывание атрибутов к родительскому элементу 3
    // Т.е. 'shippment_claim'. В экселе он будет лишь один

    $root3 = $dom->createElement('shippment_claim');

    $attrRoot3CustomerType = new DOMAttr('customerType', 'customer');
    $root3->setAttributeNode($attrRoot3CustomerType);
    $attrRoot3Recipient = new DOMAttr('recipient', $recipient);
    $root3->setAttributeNode($attrRoot3Recipient);
    $attrRoot3Date = new DOMAttr('date', $date);
    $root3->setAttributeNode($attrRoot3Date);
    $attrRoot3WarehouseId = new DOMAttr('warehouseId', 'candy_D34');
    $root3->setAttributeNode($attrRoot3WarehouseId);
    $attrRoot3ClaimId = new DOMAttr('claim_id', "{$date}/{$recipient}/customer/candy_D34");
    $root3->setAttributeNode($attrRoot3ClaimId);
    $attrRoot3Label = new DOMAttr('label', 'Озон/Кондиция/Обычные Покупатели');
    $root3->setAttributeNode($attrRoot3Label);

    // Непосредственное создание товаров из таблицы эксель

    foreach ($worksheet->getRowIterator() as $row) { // Здесь перебираются строки

        $product = $dom->createElement('product'); // Создает элемент с товаром
        $cellIterator = $row->getCellIterator(); // Объект для выбора ячейки строки

        // Обработка ячейки из столбца 1

        $columnA = $cellIterator->seek('A'); // Выбираем столбец 1-ый (содержит Sku)
        $cellA = $columnA->current();
        $productSku = $cellA->getValue();
        $attrProductSku = new DOMAttr('sku', $productSku);
        $product->setAttributeNode($attrProductSku);

        // Обработка ячейки из столбца 2

        $columnB = $cellIterator->seek('B'); // Выбираем столбец 2-ой (содержит Qty)
        $cellB = $columnB->current();
        $productQty = $cellB->getValue();
        $attrProductQty = new DOMAttr('qty', $productQty);
        $product->setAttributeNode($attrProductQty);

        // Вкладываем товар в самый нижний элемент XML

        $root3->appendChild($product);

        // Проверка на соответствие с файлом ассортимента

        if (!array_key_exists($productSku, $stock)) {
            $notFoundSkus[] = $productSku;
        } elseif (0 == $stock[$productSku]) {
            $notLeftSkus[] = $productSku;
        }
    }

    // Предупреждение, если были найдены Sku в экселе ненайденные в ассортименте или с количеством 0 в ассортименте

    $badSkus = array_merge($notFoundSkus, $notLeftSkus);
    if (!empty($badSkus)) {
        $warehouseMsg = "Нет в наличии товаров с sku: " . implode($badSkus, ", ");
    }

    // Логирование проблемных Sku

    if (!empty($notFoundSkus)) {
        logMsg("В экселе $fileName были Sku не найденные в файле ассортимента: " . implode($notFoundSkus, ", "));
    }
    if (!empty($notLeftSkus)) {
        logMsg("В экселе $fileName были Sku в ассортименте у которых кол-во стоит 0: " . implode($notLeftSkus, ", "));
    }


    return $root3;
}

/**
 * Обработка запроса на удаление
 *
 * @param string $alertClass
 * @param string $alertMsg
 *
 * @return void
 */
function deleteMain(string &$alertClass, string &$alertMsg)
{
    try {
        // Проверка, что фронт отработал верно
        if (!$_POST["deleteDate"] || !$_POST["deleteRecipient"]) {
            throw new Exception("В Post не было: deleteDate и deleteRecipient");
        }

        // Адрес где будет храниться временный созданный файл Xml для передачи на фтп
        $localXmlPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . RESULT_FILENAME;

        // Получение искомого массива из актуального Xml
        $uselessString = ''; // Необходима какая-то строка для метода следующего. Не используется больше
        $allClaimsAsNodes = getAllClaimsAsNodes([], $uselessString);

        // Удаление запрашиваемого shipment claim
        deleteClaim($allClaimsAsNodes);

        // Создание xml файла в указанном пути
        createXml($allClaimsAsNodes, $localXmlPath);

        // Отправка файла на FTP сервер
        uploadToFtp(RESULT_FILENAME, $localXmlPath);

        // Подготовка вывода сообщения для пользователя
        $alertClass = 'success';
        $alertMsg = sprintf('Успешно удален выбранный shipment claim с date: %s и recipient: %s',
            $_POST["deleteDate"], $_POST["deleteRecipient"]);

        // Обновить таблицу
        updateClaimsTable();

    } catch (Exception $e) {
        logMsg("Exception: " . $e->getMessage());
        $alertMsg = 'Что-то пошло не так. Не удалось удалить выбранный shipment claim';
    }

    logMsg("Пользователь увидел в блоке alert: $alertMsg");
}

/**
 * Удаление shippment claim по запросу пользователя из массива с Node
 *
 * @param array $allClaimsAsNodes
 *
 * @return void
 */
function deleteClaim(array &$allClaimsAsNodes)
{
    foreach ($allClaimsAsNodes as $key => $claimNode) {
        if ($_POST["deleteDate"] == $claimNode->getAttribute('date') &&
            $_POST["deleteRecipient"] == $claimNode->getAttribute('recipient')) {
            unset($allClaimsAsNodes[$key]);
        }
    }
}

/**
 * Выкачивает файл с фтп
 *
 * @param string $remoteFilepath Путь куда сохраняем
 * @param string $localFilepath Путь к файлу на нашем сервере
 *
 * @return bool
 *
 * @throws ErrorException Выбрасывается вместо Warning - значит ошибка соединения с ftp
 * @throws Exception
 */
function getFileFromFtp(string $remoteFilepath, string $localFilepath): bool
{
    $ftp = connectToFtp();

    $listOfFilesOnServer = ftp_nlist($ftp, '');

    if (!in_array(RESULT_FILENAME, $listOfFilesOnServer)) {
        logMsg("На фтп не было такого файла: $remoteFilepath");
        return false;
    }

    if (!ftp_get($ftp, $localFilepath, $remoteFilepath, FTP_ASCII)) {
        throw new Exception("Не удалось скачать с фтп существующий файл: $remoteFilepath");
    }

    ftp_close($ftp);

    logMsg("Успешно скачали с фтп файл: $remoteFilepath");
    return true;
}

/**
 * Отправляет файл на FTP сервер (перезаписывает, если с таким именем уже существует на FTP)
 *
 * @param string $newFileName Этим именем будет называться файл залитый на ftp
 * @param string $localFilePath Путь к существующему файлу для отправки
 *
 * @return void
 *
 * @throws Exception
 * @throws ErrorException Выбрасывается вместо Warning - значит ошибка соединения с ftp
 */
function uploadToFtp(string $newFileName, string $localFilePath)
{
    $ftp = connectToFtp();

    if (!ftp_put($ftp, $newFileName, $localFilePath, FTP_ASCII)) { // загрузка файла
        throw new Exception("Не удалось загрузить $newFileName на сервер");
    }

    ftp_close($ftp);

    logMsg("Успешно залили файл $localFilePath на фтп под именем: $newFileName");
}

/**
 * Устанавливает соединение с FTP-сервером. Убеждается в успешной логинизации
 *
 * @return resource
 * @throws Exception
 * @throws ErrorException Выбрасывается вместо Warning - значит ошибка соединения с ftp
 */
function connectToFtp()
{
    $ftp = ftp_connect($_ENV['FTP_SERVER']); // установка соединения

    if (!$ftp) {
        throw new Exception("FTP ошибка: Не Удалось подсоединиться к серверу");
    }

    if (!ftp_login($ftp, $_ENV['FTP_USER'], $_ENV['FTP_PASSWORD'])) {
// До этой строки дойти не должно, т.к. прежде должен выброситься Warning, а он должен выбросить ErrorException (мы переделали)
        throw new Exception("FTP ошибка: Неверный логин / пароль");
    }

    ftp_pasv($ftp, true);

    return $ftp;
}

/**
 * Обновляет файл со всеми актуальными shipment claim с фтп
 *
 * @return void
 *
 * @throws ErrorException
 */
function updateClaimsTable(): void
{
    // Адрес куда временно запишем актуальный Xml

    $localFilePathToOldXml = sys_get_temp_dir() . DIRECTORY_SEPARATOR . OLD_XML_FILENAME;

    // Скачиваем актуальный Xml с фтп

    getFileFromFtp(RESULT_FILENAME, $localFilePathToOldXml);

    // Получаем DOMDocument из файла

    $xml = new DOMDocument();
    $xml->load($localFilePathToOldXml);

    // Получаем DomNodeList со всеми shipment claim

    $xmlClaims = $xml->getElementsByTagName('shippment_claim');

    // Формируем массив со всеми shipment claim

    $allClaims = []; // Массив каждый элемент которого относится к конкретному shipment claim с ключами: date, recipient, productCount

    foreach ($xmlClaims as $xmlClaim) {
        $claimDate = $xmlClaim->getAttribute('date');
        $claimRecipient = $xmlClaim->getAttribute('recipient');
        $claimProductCount = $xmlClaim->getElementsByTagName('product')->length;

        $allClaims[] = ['date' => $claimDate, 'recipient' => $claimRecipient, 'productCount' => $claimProductCount];
    }

    saveAllClaims($allClaims);
}

/**
 * Сохраняет массив с актуальными shipment claim в файл
 *
 * @param array $allClaims Массив каждый элемент которого относится к конкретному shipment claim с ключами: date, recipient, productCount
 *
 * @return void
 */
function saveAllClaims(array $allClaims)
{
    // Проверяет создана ли соответствующая папка. Создает, если не существует

    if (!is_dir(GEN_FOLDER)) {
        mkdir(GEN_FOLDER, 0777, true);
    }

    // Сохраняем массив в файл

    file_put_contents(ALL_CLAIMS_COPY_PATH, serialize($allClaims));

    logMsg("Все актуальные Claims (" . count($allClaims) . " шт.) сериализованы в файле: " . ALL_CLAIMS_COPY_PATH);
}

/**
 * Перемещает файл в папку для хранения (не очищается каждую сессию)
 *
 * @param string $tempFilePath Старый путь к файлу
 * @param string $newFileName Имя файла, под которым будет храниться
 *
 * @return string Новый путь к файлу
 */
function moveToGenFolder(string $tempFilePath, string $newFileName): string
{
    if (!file_exists($tempFilePath)) {
        logMsg("Была попытка переместить несуществующий файл: $tempFilePath");
        return '';
    }

    if (!is_dir(GEN_FOLDER)) { // Проверяет создана ли соответствующая папка. Создает, если не существует
        mkdir(GEN_FOLDER, 0777, true);
    }
    $newFilePath = __DIR__ . DIRECTORY_SEPARATOR . GEN_FOLDER . DIRECTORY_SEPARATOR . $newFileName;
    if (!rename($tempFilePath, $newFilePath)) {
        logMsg("Несмотря на то, что файл: $tempFilePath существует, не удалось переместить в $newFilePath");
        return ''; // В случае если не удалось переместить. Например, файла не существовало по старому пути
    }

    logMsg("Переместили файл: $tempFilePath в $newFilePath");

    return $newFilePath;
}

/**
 * Логирует старт работы при запросе на удаление
 *
 * @return void
 */
function logStartDelete(): void
{
    $string = str_repeat("-", 50) . PHP_EOL . "Было запрошено удалить:" . PHP_EOL;

    if ($_POST["deleteDate"] && is_string($_POST["deleteDate"])) {
        $string = $string . "date: " . $_POST["deleteDate"] . "  ;  ";
    }

    if ($_POST["deleteRecipient"] && is_string($_POST["deleteRecipient"])) {
        $string = $string . "recipient: " . $_POST["deleteRecipient"];
    }

    logMsg($string);
}


/**
 * Логирует старт работы. Пишет в лог все что прислали из основной формы
 *
 * @return void
 */
function logStartMain(): void
{
    $string = str_repeat("-", 50) . PHP_EOL . "Были присланы данные:";

    if ($_POST["date"] && is_string($_POST["date"])) {
        $string = $string . PHP_EOL . "Дата: " . $_POST["date"];
    }

    foreach ($_FILES as $key => $sentFile) {
        $string = $string . PHP_EOL . $key . " ||| название файла: " . $sentFile["name"] . " ||| Размер: " . $sentFile["size"];
    }

    logMsg($string);
}


/**
 * Логирует сообщение
 *
 * @param string $logString Строка для логирования
 *
 * @return void
 */
function logMsg(string $logString): void
{
    $logFolder = LOG_FOLDER_ROOT . DIRECTORY_SEPARATOR . date('Y') . DIRECTORY_SEPARATOR . date('m');

    if (!is_dir($logFolder)) { // Проверяет создана ли соответствующая папка. Создает, если не существует
        mkdir($logFolder, 0777, true);
    }

    $logFileAddress = $logFolder . DIRECTORY_SEPARATOR . date('d') . '.log';

    $logString = date('H-i-s') . ": " . $logString . PHP_EOL;
    file_put_contents($logFileAddress, $logString, FILE_APPEND);
}

include 'upload-form.php'; // Html Форма

?>