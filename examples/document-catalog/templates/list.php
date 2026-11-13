<?php

declare(strict_types=1);

use Examples\DocumentCatalog\Document;
use Examples\DocumentCatalog\Html;

$template = static function (array $documents, ?int $next): void {
    /** @var list<Document> $documents */
    ?>
<h1>Documents</h1>
<p>Published documents, most recently added first.</p>
<?php if ($documents === []) { ?>
<p>There are no published documents on this page.</p>
<?php } else { ?>
<ol>
<?php foreach ($documents as $document) { ?>
    <li>
        <h2><a href="/documents/<?php echo $document->id; ?>"><?php echo Html::escape($document->title); ?></a></h2>
        <small>Published <time datetime="<?php echo gmdate('Y-m-d', $document->publishedAt); ?>"><?php echo gmdate('Y-m-d', $document->publishedAt); ?></time>. PDF, <?php echo Html::fileSize($document->fileSize); ?>.</small>
    </li>
<?php } ?>
</ol>
<?php } ?>
<nav aria-label="Catalog pages">
<?php if ($next !== null) { ?>
    <a rel="next" href="/documents?before=<?php echo $next; ?>">Next documents</a>
<?php } ?>
    <a href="/documents">Back to the first page</a>
</nav>
<?php
};

return $template;
