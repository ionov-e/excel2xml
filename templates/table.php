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
            $claimDate = $claim[KEY_ALL_CLAIMS_DATE];
            $claimRecipient = $claim[KEY_ALL_CLAIMS_RECIPIENT];
            $claimProdCount = $claim[KEY_ALL_CLAIMS_PROD_COUNT];
            ?>
        <tr>
            <td><?=++$id?></td>
            <td><?=$claimDate?></td>
            <td><?=$claimRecipient?></td>
            <td><?=$claimProdCount?></td>
            <td>
                <?php if ($claimDate > (new DateTime())->format("Y-m-d")):?>
                    <form action="" method="post">
                        <input class="btn-sm btn-primary" type="submit" name="<?=POST_DELETE?>" value="Удалить" />
                        <input type="hidden" name="<?=POST_DELETE_DATE?>" value="<?=$claimDate?>">
                        <input type="hidden" name="<?=POST_DELETE_RECIPIENT?>" value="<?=$claimRecipient?>">
                    </form>
                <?php endif;?>
            </td>
        </tr>
    <?php endforeach;?>
    </tbody>
</table>
