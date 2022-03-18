<?php

include "vendor/autoload.php";

use PhpOffice\PhpSpreadsheet\IOFactory;

const LOG_FOLDER_ROOT = 'log'; // Название папки для хранения логов
const FIELD_NAME_SIZE = 'size'; // Поле содержащий размер присланного файла

const FILENAME_SPB = 'spbFile';
const RECIPIENT_SPB = 'ozon_spb';
const SUFFIX_SPB = 'spb';

const FILENAME_MSK = 'mskFile';
const RECIPIENT_MSK = 'ozon_msk';
const SUFFIX_MSK = 'msk';

// Из файла .env берем значения для FTP соединения
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Установка часового пояса как в примере (где бы не выполнялся скрипт - одинаковое время)
date_default_timezone_set('Europe/Moscow');

// Ошибки Ftp могут выкидывать Warning вместо Exception. Эти 3 строки выбрасывают исключение при Warning
set_error_handler(function ($err_severity, $err_msg, $err_file, $err_line, array $err_context)
{
    throw new ErrorException( $err_msg, 0, $err_severity, $err_file, $err_line );
}, E_WARNING);

// Вызов главной функции
main();

// ---------------------------------------------- Функции

/**
 * Главная функция
 *
 * @return void
 */
function main() {
    try {
        $successCount = 0; // Считает сколько файлов успешно отправили

        // Проверки на отсутствие присланных данных
        if (!$_POST["date"]) {
            throw new Exception("date не прислана");
        }

        if ((!isset($_FILES[FILENAME_SPB]) || $_FILES[FILENAME_SPB][FIELD_NAME_SIZE] == 0) &&
            (!isset($_FILES[FILENAME_MSK]) || $_FILES[FILENAME_MSK][FIELD_NAME_SIZE] == 0)) {
            throw new Exception("Ни одна таблица не прислана");
        }

        // Создание и отправка XML
        if (isset($_FILES[FILENAME_SPB]) && $_FILES[FILENAME_SPB][FIELD_NAME_SIZE] > 0) {
            processExcel(FILENAME_SPB, RECIPIENT_SPB, SUFFIX_SPB);
            $successCount++;
        }

        if (isset($_FILES[FILENAME_MSK]) && $_FILES[FILENAME_MSK][FIELD_NAME_SIZE] > 0) {
            processExcel(FILENAME_MSK, RECIPIENT_MSK, SUFFIX_MSK);
            $successCount++;
        }

        logMessage("Скрипт отработал без ошибок. Количество отправленных файлов: $successCount");

    } catch (ErrorException $e) { // Будет выбрасываться в определенных случаях Ftp-соединения
        logMessage("Словили Warning (не Exception): " . $e->getMessage());
        http_response_code(400);
        echo "Словили Warning. Содержание в логе (проблема должна быть с работой Ftp)";
    } catch (DOMException|\PhpOffice\PhpSpreadsheet\Exception $e) {
        logMessage($e->getMessage());
        http_response_code(400);
        echo "Прислана таблица с несоответствующим содержанием";
    } catch (Exception $e) { // Здесь ожидаются лишь явно вызванные исключения
        logMessage($e->getMessage());
        http_response_code(400);
        echo $e->getMessage();
    }
    exit();
}

/**
 * Обрабатывает таблицу, формирует xml, заливает на FTP
 *
 * @param string $fileName
 * @param string $recipient
 * @param string $suffix
 *
 * @return void
 *
 * @throws DOMException
 * @throws \PhpOffice\PhpSpreadsheet\Exception
 * @throws Exception Тут должны быть только исключения только явно вызванные в коде
 */
function processExcel(string $fileName, string $recipient, string $suffix)
{
    // Адрес где будет храниться временный созданный файл
    $newFileName = "candy_order_xml_{$suffix}.xml";
    $localFilePath = sys_get_temp_dir() . "/{$newFileName}";

    // Создание xml файла в указанном пути
    createXml($fileName, $recipient, $localFilePath);

    // Отправка файла на FTP сервер
    uploadOnFtp($newFileName, $localFilePath);
}

/**
 * Обрабатывает excel таблицу и переделывает в xml. Записывает во временный указанный путь
 *
 * @param string $fileName
 * @param string $recipient
 * @param string $localFilePath
 *
 * @return void
 *
 * @throws DOMException
 * @throws \PhpOffice\PhpSpreadsheet\Exception
 */
function createXml(string $fileName, string $recipient, string $localFilePath) {

    $date = $_POST['date']; // Полученная дата отгрузки
    $currentTime = new DateTime(); // Получение текущего времени

    // Обработка полученной Excel таблицы
    $spreadsheet = IOFactory::load($_FILES[$fileName]["tmp_name"]);
    $worksheet = $spreadsheet->getActiveSheet();

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

    // Создание и привязывание атрибутов к родительскому элементу 3
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

    // Вложение родительских элементов в соответствующем порядке: 1 - самый верхний, 2-ой вложен в 1-ый, 3-ий во 2-ой
    $root2->appendChild($root3);
    $root1->appendChild($root2);
    $dom->appendChild($root1);

    // Непосредственное создание товаров из таблицы эксель
    foreach ($worksheet->getRowIterator() as $row) { // Здесь перебираются строки

        $product = $dom->createElement('product'); // Создает элемент с товаром
        $cellIterator = $row->getCellIterator(); // Объект для выбора ячейки строки

        // Обработка ячейки из столбца 1
        $columnA = $cellIterator->seek('A'); // Выбираем столбец 1-ый
        $cellA = $columnA->current();
        $attrProductSku = new DOMAttr('sku', $cellA->getValue());
        $product->setAttributeNode($attrProductSku);

        // Обработка ячейки из столбца 2
        $columnB = $cellIterator->seek('B'); // Выбираем столбец 2-ой
        $cellB = $columnB->current();
        $attrProductQty = new DOMAttr('qty', $cellB->getValue());
        $product->setAttributeNode($attrProductQty);

        // Вкладываем товар в самый нижний элемент XML
        $root3->appendChild($product);
    }

    // Сохранение файла во временную папку
    $dom->save($localFilePath);
}

/**
 * Отправляет сформированный xml на FTP сервер
 *
 * Перезаписывает, если файл уже существует на FTP
 *
 * @param string $newFileName
 * @param string $localFilePath
 *
 * @return void
 *
 * @throws Exception
 */
function uploadOnFtp(string $newFileName, string $localFilePath) {
    $ftp = ftp_connect($_ENV['FTP_SERVER']); // установка соединения

    if (!$ftp) {
        throw new Exception("FTP ошибка: Не Удалось подсоединиться к серверу");
    }

    if (!ftp_login($ftp, $_ENV['FTP_USER'], $_ENV['FTP_PASSWORD'])) {
        throw new Exception("FTP ошибка: Логин / Пароль не подошли");
    }

    ftp_pasv($ftp, true);

    if (ftp_put($ftp, $newFileName, $localFilePath, FTP_ASCII)) { // загрузка файла
        echo "$newFileName успешно загружен на сервер <br>";
    } else {
        // До этого иначе дойти не должно, т.к. прежде должен выброситься Warning, а он должен выбросить
        // Exception (мы переделали). Иначе содержимое Warning появлялось на странице - нельзя было поменять статус-код
        // с 200 на другой и надо было содержимое Warning поместить в лог.
        throw new Exception("Не удалось загрузить $newFileName на сервер");
    }

    ftp_close($ftp); // закрытие соединения
}

/**
 * Логирует сообщение
 *
 * @param string $logString
 *
 * @return void
 */
function logMessage(string $logString): void
{
    $logFolder = LOG_FOLDER_ROOT . DIRECTORY_SEPARATOR . date('Y') . DIRECTORY_SEPARATOR . date('m');
    checkLogFolders($logFolder);
    $logFileAddress = $logFolder . DIRECTORY_SEPARATOR . date('d') . '.log';

    $postContents = "Были присланы данные:" . PHP_EOL;
    if ($_POST["date"] && is_string($_POST["date"])) {
        $postContents = $postContents . "Дата: " . $_POST["date"] . PHP_EOL;
    }

    foreach ($_FILES as $key => $sentFile) {
        $postContents = $postContents . $key . " ||| название файла: " . $sentFile["name"] . " ||| Размер: " . $sentFile["size"] . PHP_EOL;
    }

    $postContents = $postContents . str_repeat("-", 50) . PHP_EOL;

    $logString = date('H-i-s') . ": " . $logString . PHP_EOL . $postContents;
    file_put_contents($logFileAddress, $logString, FILE_APPEND);
}

/**
 * Проверяет создана ли соответствующая папка для лога. Создает, если не существует
 *
 * @param string $logFolder
 *
 * @return void
 */
function checkLogFolders(string $logFolder): void
{
    if (!is_dir(LOG_FOLDER_ROOT)) {
        mkdir(LOG_FOLDER_ROOT, 0777, true);
    }
    if (!is_dir($logFolder)) {
        mkdir($logFolder, 0777, true);
    }
}