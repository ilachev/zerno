<?php

declare(strict_types=1);

use Examples\DocumentCatalog\Document;
use Examples\DocumentCatalog\Html;

return static function (Document $document): void {
    ?>
<h1><?php echo Html::escape($document->title); ?></h1>
<p>Published <time datetime="<?php echo gmdate('Y-m-d', $document->publishedAt); ?>"><?php echo gmdate('Y-m-d', $document->publishedAt); ?></time> (UTC).</p>
<p class="description"><?php echo Html::escape($document->description); ?></p>
<p><a href="/documents/<?php echo $document->id; ?>/download">Download “<?php echo Html::escape($document->title); ?>” — PDF, <?php echo Html::fileSize($document->fileSize); ?></a></p>
<p>Filename: <?php echo Html::escape($document->originalName); ?>. PDF accessibility depends on how the document was prepared.</p>
<nav aria-label="Catalog navigation"><a href="/documents">Back to the document list</a></nav>
<?php
};
