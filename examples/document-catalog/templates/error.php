<?php

declare(strict_types=1);

use Examples\DocumentCatalog\Html;

return static function (string $title, string $message): void {
    ?>
<h1><?php echo Html::escape($title); ?></h1>
<p><?php echo Html::escape($message); ?></p>
<p><a href="/documents">Открыть каталог документов</a></p>
<?php
};
