<?php

declare(strict_types=1);

// Real file copies of a tiny local fixture only. No HTTP or application bootstrap.
require __DIR__ . '/../vendor/composer/ClassLoader.php';
$loader = new Composer\Autoload\ClassLoader();
foreach (require __DIR__ . '/../vendor/composer/autoload_psr4.php' as $prefix => $paths) $loader->addPsr4($prefix, $paths);
$loader->register();

$directory = __DIR__ . '/image-copy-fixture-' . bin2hex(random_bytes(5));
mkdir($directory);
$original = $directory . '/original.png';
$bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
file_put_contents($original, $bytes);
$storage = new App\Service\ObjectImageStorage($directory, dirname(__DIR__));
$stored = null;
try {
    $stored = $storage->archiveMainImages([
        'manifestUrl' => '', 'infoUrl' => '',
        'imageUrl' => '/uploads/objects/original.png', 'thumbnailUrl' => '/uploads/objects/original.png',
    ])[0];
    $copy = $directory . '/' . basename($stored['image_url']);
    if (file_get_contents($copy) !== $bytes || file_get_contents($original) !== $bytes) {
        throw new RuntimeException('Archive must preserve original bytes without resizing or overwriting.');
    }
    unlink($original);
    if (!$storage->exists($stored['image_url']) || $storage->dataUri($stored['image_url']) !== 'data:image/png;base64,' . base64_encode($bytes)) {
        throw new RuntimeException('The archive must remain independently readable after the original disappears.');
    }
    echo "PASS: exact image bytes, original untouched, independent archive and PDF data URI.\n";
} finally {
    if ($stored !== null) {
        $storage->remove($stored['image_url']);
        $storage->remove($stored['thumbnail_url']);
    }
    if (is_file($original)) unlink($original);
    rmdir($directory);
}
