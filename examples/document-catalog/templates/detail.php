<?php

declare(strict_types=1);

use Examples\DocumentCatalog\Document;
use Examples\DocumentCatalog\Html;

return static function (Document $document): void {
    ?>
<h1><?php echo Html::escape($document->title); ?></h1>
<p>Опубликован <time datetime="<?php echo gmdate('Y-m-d', $document->publishedAt); ?>"><?php echo gmdate('d.m.Y', $document->publishedAt); ?></time> (UTC).</p>
<p class="description"><?php echo Html::escape($document->description); ?></p>
<p><a href="/documents/<?php echo $document->id; ?>/download">Скачать «<?php echo Html::escape($document->title); ?>» — PDF, <?php echo Html::fileSize($document->fileSize); ?></a></p>
<p>Имя файла: <?php echo Html::escape($document->originalName); ?>. Доступность содержимого PDF зависит от подготовки документа.</p>
<nav aria-label="Навигация по каталогу"><a href="/documents">Вернуться к списку документов</a></nav>
<?php
};
