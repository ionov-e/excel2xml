<?php

include "vendor/autoload.php";

use Dotenv\Dotenv;
use \PhpOffice\PhpSpreadsheet\Exception as PhpSpreadsheetException;
use PhpOffice\PhpSpreadsheet\IOFactory;

const LOG_FOLDER_ROOT = 'log';      // Название папки для хранения логов
const FIELD_NAME_SIZE = 'size';     // Поле содержащий размер присланного файла

const FILENAME_SPB = 'spbFile';     // Наименование файла в теле POST
const RECIPIENT_SPB = 'ozon_spb';   // Используется в XML (в 'claim_id')

const FILENAME_MSK = 'mskFile';
const RECIPIENT_MSK = 'ozon_msk';

const RESULT_FILENAME = 'candy_order_xml_test.xml'; // С таким именем файл зальется на ФТП #TODO убрать 'test_'
const MAX_OLD_DATE = '-14 days'; // Используется в функции strtotime. Максимальное количество дней для хранения 'shippment_claim'

const OLD_XML_FILENAME = 'old_' . RESULT_FILENAME;  // Произвольное имя временного файла (прошлый xml файл с ФТП)
const TEMP_ROOT_ELEMENT = 'temp';                   // Произвольное имя элемента временного файла

$alert = false;
$alertClass = 'danger';
$msg = "";

$minDate = new DateTime();
$modifyDays = 1;
if(date("H") > 15 || (date("H") == 15 && date("i") > 30)) {
    $modifyDays = 2;
}

if(isset($_POST['submit'])) {

    logStartMessage(); // Логирует старт работы с присланными данными

// Из файла .env берем значения для FTP соединения

    $dotenv = Dotenv::createImmutable(__DIR__);
    $dotenv->load();

// Установка часового пояса как в примере (где бы не выполнялся скрипт - одинаковое время)

    date_default_timezone_set('Europe/Moscow');

// Преобразуют Warning в Exception. Ошибки Ftp могут выкидывать Warning. Имплементировано для логирования содержимого

    set_error_handler(function ($err_severity, $err_msg, $err_file, $err_line, array $err_context)
    {
        throw new ErrorException( $err_msg, 0, $err_severity, $err_file, $err_line );
    }, E_WARNING);

// Вызов главной функции

    main($alert, $alertClass, $msg);
}

// ---------------------------------------------- Функции

/**
 * Главная функция
 *
 * @return void
 */
function main(&$alert, &$alertClass, &$msg) {
    try {
        // Проверки на отсутствие присланных данных
        if (!$_POST["date"]) {
            throw new Exception("не указана дата отгрузки не прислана");
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
            processData($receivedExcels);
        }

        $msg = "Скрипт отработал без ошибок. Количество полученных файлов: " . count($receivedExcels);
        $alertClass = 'success';
        $alert = true;

    } catch (DOMException|PhpSpreadsheetException $e) {
        logMessage($e->getMessage());
        http_response_code(400);
        $msg = "Прислана таблица с несоответствующим содержанием";
    } catch (Exception $e) {
        http_response_code(400);
        $msg = $e->getMessage();
    }

    logMessage($msg);
}

/**
 * Обрабатывает таблицы, формирует xml, заливает на FTP
 *
 * @param array $receivedExcels Массив, каждый элемент которого содержит [$fileName, $recipient] (имя файла в POST и claim_id)
 *
 * @return void
 *
 * @throws DOMException
 * @throws PhpSpreadsheetException
 * @throws Exception Тут должны быть только исключения только явно вызванные в коде
 */
function processData(array $receivedExcels)
{
    // Адрес где будет храниться временный созданный файл для передачи на фтп
    $localFilePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . RESULT_FILENAME;

    $allClaims = getAllClaims($receivedExcels); // Получение искомого массива из экселя и старого Xml

    // Создание xml файла в указанном пути
    createXml($allClaims, $localFilePath);

    // Отправка файла на FTP сервер
    uploadToFtp(RESULT_FILENAME, $localFilePath);
}

/**
 * Возвращает массив с всеми отсортированными и отобранными 'shippment_claim' в виде нодов (Nodes) из временного DOMDocument
 *
 * Использует при создании Excel таблицы из Post и старый Xml с фтп-сервера
 *
 * @param array $receivedExcels Массив, каждый элемент которого содержит [$fileName, $recipient] (имя файла в POST и claim_id)
 *
 * @return DOMNode[]
 *
 * @throws DOMException
 * @throws ErrorException
 * @throws PhpSpreadsheetException
 */
function getAllClaims(array $receivedExcels): array
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
        $shipmentClaimsExcels[] = excelToXmlNode($singleExcel[0], $singleExcel[1], $tempDom);
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
    usort($tempClaims, static function($a, $b) {
        return strcasecmp($a->getAttribute('date'), $b->getAttribute('date'));
        }
    );

    return $tempClaims;
}

/**
 * Создает XML-файл по указанному пути
 *
 * @param array $allClaims Массив со всеми отсортированными и отобранными 'shippment_claim' в виде нодов (Nodes) из временного DOMDocument
 * @param string $localFilePath Путь куда сохранить созданный файл
 *
 * @return void
 *
 * @throws DOMException
 */
function createXml(array $allClaims, string $localFilePath): void {

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
    foreach ($allClaims as $tempClaim) {
        $newNode = $dom->importNode($tempClaim, true);
        $root2->appendChild($newNode);
    }

    // Сохранение файла во временную папку

    $dom->save($localFilePath);
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

    $oldXmlFilePath = getXmlFromFtp();

    if (empty($oldXmlFilePath)) {
        return [];
    }

    // Получаем DOMDocument из файла

    $oldXml = new DOMDocument();
    $oldXml->load($oldXmlFilePath);

    // Нам нужно вернуть только те 'shippment_claim', в которых аттрибут дата не позднее определенной даты
    // И если на присланную дату в присланном складе будет в старом Xml запись - ее не возвращать

    $returnArray = []; // Список для возвращения из функции

    $countDeleted = 0; // Используется для логирования количества неподходящих по дате shippment_claim из старого файла

    $roots3Array = $oldXml->getElementsByTagName('shippment_claim');

    $minRelevantDate = date('Y-m-d', strtotime(MAX_OLD_DATE)); // Все что раньше этой даты не возвращать

    $recipientList = []; // Элемент - значение 'recipient' на каждый из присланных экселей
    foreach ($receivedExcels as $singleReceivedExcel) {
        $recipientList[] = $singleReceivedExcel[1];
    }

    foreach ($roots3Array as $singleRoot3) {
        $root3Date = $singleRoot3->getAttribute('date');
        $root3Recipient = $singleRoot3->getAttribute('recipient');
        if ($root3Date > $minRelevantDate) { // Здесь убираем все, которые меньше даты
            // Убираем, если на эту же дату и склад в прошлом XML была запись
            if (!($root3Date == $_POST['date'] && in_array($root3Recipient, $recipientList))) {
                $returnArray[] = $singleRoot3;
                continue;
            }
        }
        $countDeleted++; // Сюда добираемся всегда, когда не добрались до строки с добавлением в массив
    }

    logMessage("Из старого файла удалено: $countDeleted");
    return $returnArray;
}

/**
 * Создание 'shippment_claim' (последнего родительского элемента xml) вместе с товарами из загруженного экселя
 *
 * @param string $fileName Имя файла с таблицей из тела Post-запроса
 * @param string $recipient Используется в XML (в 'claim_id')
 * @param DOMDocument $dom Содержимое формируемого нашего нового XML файла
 *
 * @return DOMElement
 *
 * @throws DOMException
 * @throws PhpSpreadsheetException
 */
function excelToXmlNode(string $fileName, string $recipient, DOMDocument &$dom): DOMElement
{
    $date = $_POST['date']; // Полученная дата отгрузки

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

    return $root3;
}

/**
 * Выкачивает с фтп старый XML файл. Возвращает ссылку на скаченный файл, или пусто в случае если не было файла на фтп
 *
 * @return string Путь к скаченному предыдущему XML
 *
 * @throws ErrorException Выбрасывается вместо Warning - значит ошибка соединения с ftp
 * @throws Exception
 */
function getXmlFromFtp(): string {
    $localFilePathToOldXml = sys_get_temp_dir() . DIRECTORY_SEPARATOR . OLD_XML_FILENAME;

    $ftp = connectToFtp();

    $listOfFilesOnServer = ftp_nlist($ftp, '');

    if (!in_array(RESULT_FILENAME, $listOfFilesOnServer)) {
        logMessage('На фтп не было файла с именем: ' . RESULT_FILENAME);
        return '';
    }

    if (!ftp_get($ftp, $localFilePathToOldXml, RESULT_FILENAME, FTP_ASCII)) { // загрузка файла
        throw new Exception('Не удалось скачать с фтп файл: ' . RESULT_FILENAME);
    }

    ftp_close($ftp); // закрытие соединения

    logMessage('Успешно скачали с фтп файл с именем: ' . RESULT_FILENAME);
    return $localFilePathToOldXml;
}

/**
 * Отправляет сформированный xml на FTP сервер
 *
 * Перезаписывает, если файл уже существует на FTP
 *
 * @param string $newFileName Этим именем будет называться файл залитый на ftp
 * @param string $localFilePath Путь к существующему файлу для отправки
 *
 * @return void
 *
 * @throws Exception
 * @throws ErrorException Выбрасывается вместо Warning - значит ошибка соединения с ftp
 */
function uploadToFtp(string $newFileName, string $localFilePath) {
    $ftp = connectToFtp();

    if (!ftp_put($ftp, $newFileName, $localFilePath, FTP_ASCII)) { // загрузка файла
        throw new Exception("Не удалось загрузить $newFileName на сервер");
    }

    ftp_close($ftp); // закрытие соединения
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
 * Логирует старт работы. Пишет в лог все что прислали из формы
 *
 * @return void
 */
function logStartMessage(): void
{
    $string = str_repeat("-", 50) . PHP_EOL . "Были присланы данные:";

    if ($_POST["date"] && is_string($_POST["date"])) {
        $string = $string . PHP_EOL . "Дата: " . $_POST["date"];
    }

    foreach ($_FILES as $key => $sentFile) {
        $string = $string . PHP_EOL . $key . " ||| название файла: " . $sentFile["name"] . " ||| Размер: " . $sentFile["size"];
    }

    logMessage($string);
}


/**
 * Логирует сообщение
 *
 * @param string $logString Строка для логирования
 *
 * @return void
 */
function logMessage(string $logString): void
{
    $logFolder = LOG_FOLDER_ROOT . DIRECTORY_SEPARATOR . date('Y') . DIRECTORY_SEPARATOR . date('m');

    if (!is_dir($logFolder)) { // Проверяет создана ли соответствующая папка для лога. Создает, если не существует
        mkdir($logFolder, 0777, true);
    }

    $logFileAddress = $logFolder . DIRECTORY_SEPARATOR . date('d') . '.log';

    $logString = date('H-i-s') . ": " . $logString . PHP_EOL;
    file_put_contents($logFileAddress, $logString, FILE_APPEND);
}
?>

<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Excel2Xml</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.1/dist/css/bootstrap.min.css">
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.1/dist/js/bootstrap.bundle.min.js"></script>
</head>
<body>
    <div class="container">
        <?php if($alert):?>
            <div id="alert" class="alert alert-<?php echo $alertClass?>">
              <?php echo $msg?>
            </div>
        <?php endif;?>
        <form action="" method="post" class="was-validated" enctype="multipart/form-data">
            <div class="form-group">
                <label for="date">Дата планируемой отгрузки:</label>
                <input type="date" min="<?php echo $minDate->modify("+ {$modifyDays} days")->format("Y-m-d")?>" class="form-control" id="date" placeholder="Выберите дату отгрузки" name="date" required>
                <div class="invalid-feedback">Обязательно для заполнения.</div>
            </div>
            <div class="form-group">
                <label for="pwd">Эксель Питер:</label>
                <input type="file" class="form-control" id="<?php echo FILENAME_SPB?>" placeholder="Выберите файл" name="<?php echo FILENAME_SPB?>">
            </div>
            <div class="form-group">
                <label for="pwd">Эксель Москва:</label>
                <input type="file" class="form-control" id="<?php echo FILENAME_MSK?>" placeholder="Выберите файл" name="<?php echo FILENAME_MSK?>">
            </div>
            <button type="submit" name="submit" class="btn btn-primary">Отправить</button>
        </form>
    </div>
    <script>
        $("#date").on('change', function () {
            $("#alert").hide();
        })
    </script>
</body>
</html>
