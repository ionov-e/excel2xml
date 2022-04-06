<?php

include "vendor/autoload.php";

use Dotenv\Dotenv;
use \PhpOffice\PhpSpreadsheet\Exception as PhpSpreadsheetException;
use PhpOffice\PhpSpreadsheet\IOFactory;

const LOG_FOLDER_ROOT = 'log';      // Название папки для хранения логов
const FIELD_NAME_SIZE = 'size';     // Поле содержащий размер присланного файла

const FILENAME_SPB = 'spbFile';     // Наименование файла в теле POST
const RECIPIENT_SPB = 'ozon_spb';   // Используется в XML (в 'claim_id')
const SUFFIX_SPB = 'test_spb';      // Добавляется в конец названия файла перед расширением #TODO убрать 'test_'

const FILENAME_MSK = 'mskFile';
const RECIPIENT_MSK = 'ozon_msk';
const SUFFIX_MSK = 'test_msk'; #TODO убрать 'test_'

$alert = false;
$alertClass = 'danger';
$msg = "";

$minDate = new DateTime();
$modifyDays = 1;
if(date("H") > 15 || (date("H") == 15 && date("i") > 30)) {
    $modifyDays = 2;
}

if(isset($_POST['submit'])) {
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
        $successCount = 0; // Считает сколько файлов успешно отправили

        // Проверки на отсутствие присланных данных
        if (!$_POST["date"]) {
            throw new Exception("не указана дата отгрузки не прислана");
        }

        if ((!isset($_FILES[FILENAME_SPB]) || 0 == $_FILES[FILENAME_SPB][FIELD_NAME_SIZE]) &&
            (!isset($_FILES[FILENAME_MSK]) || 0 == $_FILES[FILENAME_MSK][FIELD_NAME_SIZE])) {
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

        $msg = "Скрипт отработал без ошибок. Количество отправленных файлов: $successCount";
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
 * Обрабатывает таблицу, формирует xml, заливает на FTP
 *
 * @param string $fileName  Наименование файла в теле POST
 * @param string $recipient Используется в XML (в 'claim_id')
 * @param string $suffix    Добавляется в конец названия файла перед расширением
 *
 * @return void
 *
 * @throws DOMException
 * @throws PhpSpreadsheetException
 * @throws Exception Тут должны быть только исключения только явно вызванные в коде
 */
function processExcel(string $fileName, string $recipient, string $suffix)
{
    // Адрес где будет храниться временный созданный файл
    $newFileName = "candy_order_xml_{$suffix}.xml";
    $localFilePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $newFileName;

    // Создание xml файла в указанном пути
    createXml($fileName, $recipient, $localFilePath);

    // Отправка файла на FTP сервер
    uploadOnFtp($newFileName, $localFilePath);
}

/**
 * Обрабатывает excel таблицу и переделывает в xml. Записывает во временный указанный путь
 *
 * @param string $fileName      Наименование файла в теле POST
 * @param string $recipient     Используется в XML (в 'claim_id')
 * @param string $localFilePath Путь куда сохранить созданный файл
 *
 * @return void
 *
 * @throws DOMException
 * @throws PhpSpreadsheetException
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
 * @param string $newFileName Этим именем будет называться файл залитый на ftp
 * @param string $localFilePath Путь к существующему файлу для отправки
 *
 * @return void
 *
 * @throws Exception
 * @throws ErrorException Выбрасывается вместо Warning - значит ошибка соединения с ftp
 */
function uploadOnFtp(string $newFileName, string $localFilePath) {
    $ftp = ftp_connect($_ENV['FTP_SERVER']); // установка соединения

    if (!$ftp) {
        throw new Exception("FTP ошибка: Не Удалось подсоединиться к серверу");
    }

    if (!ftp_login($ftp, $_ENV['FTP_USER'], $_ENV['FTP_PASSWORD'])) {
// До этой строки дойти не должно, т.к. прежде должен выброситься Warning, а он должен выбросить ErrorException (мы переделали)
        throw new Exception("FTP ошибка: Неверный логин / пароль");
    }

    ftp_pasv($ftp, true);

    if (!ftp_put($ftp, $newFileName, $localFilePath, FTP_ASCII)) { // загрузка файла
        throw new Exception("Не удалось загрузить $newFileName на сервер");
    }

    ftp_close($ftp); // закрытие соединения
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
