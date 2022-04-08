<?php
/**
 * @var string $alertClass Класс alert блока - для выбора цвета
 * @var string $alertMsg Сообщение в alert блок
 *
 * @var DateTime $currentTime Текущее время, используемое для вычисления возможной отгрузки
 * @var int $modifyDays Количество дней от сегодня, в которые уже дата отгрузки невозможна
 *
 * @var string $warehouseMsg Сообщение для Popup-окна, в случае, когда отправили таблицу с несоответствиями с наличием
 * @var string $localXmlPath Локальный путь хранения уже готового Xml для случая, когда нужно лишнее подтверждение перед Фтп-отправкой
 */
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
    <?php if (!empty($alertMsg)): ?>
        <div id="alert" class="alert alert-<?php echo $alertClass ?>">
            <?php echo $alertMsg ?>
        </div>
    <?php endif; ?>
    <form action="" method="post" class="was-validated" enctype="multipart/form-data">
        <div class="form-group">
            <label for="date">Дата планируемой отгрузки:</label>
            <input type="date" min="<?php echo $currentTime->modify("+ {$modifyDays} days")->format("Y-m-d") ?>"
                   class="form-control" id="date" placeholder="Выберите дату отгрузки" name="date" required>
            <div class="invalid-feedback">Обязательно для заполнения.</div>
        </div>
        <div class="form-group">
            <label for="pwd">Эксель Питер:</label>
            <input type="file" class="form-control" id="<?php echo FILENAME_SPB ?>" placeholder="Выберите файл"
                   name="<?php echo FILENAME_SPB ?>">
        </div>
        <div class="form-group">
            <label for="pwd">Эксель Москва:</label>
            <input type="file" class="form-control" id="<?php echo FILENAME_MSK ?>" placeholder="Выберите файл"
                   name="<?php echo FILENAME_MSK ?>">
        </div>
        <button type="submit" name="submit" class="btn btn-primary">Отправить</button>
    </form>


    <!-- The Modal -->
    <?php if (!empty($warehouseMsg)): ?>
        <div class="modal" id="myModal" style="display: block; backdrop-filter: blur(3px);">
            <div class="modal-dialog">
                <div class="modal-content">

                    <!-- Modal Header -->
                    <div class="modal-header">
                        <h4 class="modal-title">Обнаружены несоответствия с ассортиментом</h4>
                    </div>

                    <!-- Modal body -->
                    <div class="modal-body">
                        <?php echo $warehouseMsg ?>
                    </div>

                    <div class="modal-footer">
                        <form action="" method="post" class="was-validated" enctype="multipart/form-data">
                            <textarea rows="1" name="readyXml" style="display: none;"><?php echo $localXmlPath ?></textarea>
                            <button id="modalCloseButton" type="reset" name="reset" class="btn btn-primary"
                                    data-dismiss="modal">Заново выбрать файлы
                            </button>
                            <button type="submit" name="submit-hard" class="btn btn-primary">Отправить без изменений
                            </button>
                        </form>
                    </div>

                </div>
            </div>
        </div>
    <?php endif ?>

    <script>
        $("#date").on('change', function () {
            $("#alert").hide();
        })
    </script>
    <script>
        $('#modalCloseButton').click(function () {
            $('#myModal').css({
                'display': 'none'
            });
        });
    </script>
</body>
</html>