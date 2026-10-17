<?php

declare(strict_types=1);

use Examples\DocumentCatalog\Html;

return static function (string $title, string $content): void {
    ?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo Html::escape($title); ?> — Каталог документов</title>
    <style>
        :root{font-family:system-ui,sans-serif;color:#162333;background:#fff;line-height:1.6}
        body{max-width:60rem;margin:auto;padding:1rem}a{color:#064fa4}a:hover{text-decoration-thickness:.15em}
        a:focus-visible{outline:3px solid #171717;outline-offset:4px;background:#ffef99}
        .skip{position:absolute;left:-9999px}.skip:focus{position:static}header{border-bottom:1px solid #bbc4ce;padding-bottom:1rem}
        main{padding-block:1rem}h1{line-height:1.2;overflow-wrap:anywhere}h2{font-size:1.2rem;line-height:1.4}
        ol{padding-left:1.5rem}li{padding-block:.5rem}small{display:block;color:#39485a}.description{white-space:pre-wrap;overflow-wrap:anywhere}
        nav{margin-block:1.5rem}footer{border-top:1px solid #bbc4ce;padding-top:1rem;color:#39485a}
    </style>
</head>
<body>
<a class="skip" href="#main">Перейти к содержанию</a>
<header><a href="/documents">Каталог документов</a></header>
<main id="main" tabindex="-1">
<?php echo $content; ?>
</main>
<footer>Публичные документы. Для просмотра страниц JavaScript не требуется.</footer>
</body>
</html>
<?php
};
