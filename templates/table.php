<?php
/**
 * @var array $allClaims Массив со всеми shipment claim. Каждый в виде ассоц. массива с ключами: date, recipient, productCount
 */
?>

<h2 class="pt-5 text-center">Таблица с shipment claims (<?php echo (count($allClaims)) ?>)</h2>
<table class="table table-hover">
    <thead>
    <tr>
        <th>Id</th>
        <th>Дата</th>
        <th>Склад</th>
        <th>Кол-во товаров</th>
        <th>Удалить заказ</th>
    </tr>
    </thead>
    <tbody>
    <?php
        $id = 0;
        foreach ($allClaims as $claim):
    ?>
        <tr>
            <td><?=++$id?></td>
            <td><?=$claim['date']?></td>
            <td><?=$claim['recipient']?></td>
            <td><?=$claim['productCount']?></td>
            <td>
                <?php if ($claim['date'] > (new DateTime())->format("Y-m-d")):?>
                    <form action="" method="post">
                        <input class="btn-sm btn-primary" type="submit" name="delete" value="Удалить" />
                        <input type="hidden" name="deleteDate" value="<?=$claim['date']?>">
                        <input type="hidden" name="deleteRecipient" value="<?=$claim['recipient']?>">
                    </form>
                <?php endif;?>
            </td>
        </tr>
    <?php endforeach;?>
    </tbody>
</table>
