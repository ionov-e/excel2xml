<?php
/**
 * @var string $warehouseMsg Сообщение для Popup-окна, в случае, когда отправили таблицу с несоответствиями с наличием
 * @var string $localXmlPath Локальный путь хранения уже готового Xml для случая, когда нужно лишнее подтверждение перед Фтп-отправкой
 */
?>

<div class="modal" id="myModal" style="display: block; backdrop-filter: blur(3px);">
    <div class="modal-dialog">
        <div class="modal-content">
            <!-- Modal Header -->
            <div class="modal-header">
                <h4 class="modal-title">Обнаружены несоответствия с ассортиментом</h4>
            </div>
            <!-- Modal body -->
            <div class="modal-body">
                <?=$warehouseMsg?>
            </div>
            <div class="modal-footer">
                <form action="" method="post" class="was-validated" enctype="multipart/form-data">
                            <input type="hidden" name="<?=POST_READY_XML?>" value="<?=$localXmlPath?>">
                    <button id="modalCloseButton" type="reset" name="reset" class="btn btn-danger"
                            data-dismiss="modal">
                        Заново выбрать файлы
                    </button>
                    <button type="submit" name="<?=POST_SUBMIT_HARD?>" class="btn btn-success my-listen-btn">
                        Отправить без изменений
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
