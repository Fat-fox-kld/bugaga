<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/Collector.php';
$html = '<div class="w_hdr"><a href="/jokes/123-pictures.html">Post</a><a href="/jokes/123-pictures.html">Duplicate</a></div><img src="/uploads/posts/sidebar.jpg"><div itemprop="articleBody"><a href="/uploads/posts/full.jpg"><img src="/uploads/posts/thumbs/full.jpg"></a><img data-src="/uploads/posts/second.png"><img src="https://evil.example/uploads/posts/ad.jpg"></div><meta itemprop="datePublished" content="2026-10-04 22:02:30"><h1>Title</h1>';
$result = Collector::extract($html, 'https://bugaga.ru/jokes/');
if ($result !== ['posts' => ['https://bugaga.ru/jokes/123-pictures.html'], 'images' => ['https://bugaga.ru/uploads/posts/full.jpg', 'https://bugaga.ru/uploads/posts/second.png'], 'published_at' => '2026-10-04 22:02:30', 'title' => 'Title']) throw new RuntimeException('Parser fixture failed');
echo "Parser fixture passed\n";
$real = dirname(__DIR__) . '/var/probe/post-0.html';
if (is_file($real)) {
    $parsed = Collector::extract(file_get_contents($real), 'https://bugaga.ru/jokes/');
    if (count($parsed['images']) !== 15 || $parsed['published_at'] !== '2026-10-04 22:02:30') throw new RuntimeException('Saved first-post regression failed');
    foreach ($parsed['images'] as $url) if (str_contains($url, '/thumbs/')) throw new RuntimeException('Thumbnail collected');
    echo "Saved real post: 15 originals, publication date verified\n";
}
