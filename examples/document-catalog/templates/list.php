<?php

declare(strict_types=1);

use Examples\DocumentCatalog\Document;
use Examples\DocumentCatalog\Html;

$template = static function (array $documents, ?int $next): void {
    /** @var list<Document> $documents */
    ?>
<h1>Документы</h1>
<p>Опубликованные документы, начиная с последних добавленных.</p>
<?php if ($documents === []) { ?>
<p>Опубликованных документов на этой странице нет.</p>
<?php } else { ?>
<ol>
<?php foreach ($documents as $document) { ?>
    <li>
        <h2><a href="/documents/<?php echo $document->id; ?>"><?php echo Html::escape($document->title); ?></a></h2>
        <small>Опубликован <time datetime="<?php echo gmdate('Y-m-d', $document->publishedAt); ?>"><?php echo gmdate('d.m.Y', $document->publishedAt); ?></time>. PDF, <?php echo Html::fileSize($document->fileSize); ?>.</small>
    </li>
<?php } ?>
</ol>
<?php } ?>
<nav aria-label="Страницы каталога">
<?php if ($next !== null) { ?>
    <a rel="next" href="/documents?before=<?php echo $next; ?>">Следующие документы</a>
<?php } ?>
    <a href="/documents">К началу каталога</a>
</nav>
<?php
};

return $template;
