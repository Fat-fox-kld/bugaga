<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/Collector.php';
try {
    foreach (['curl', 'dom'] as $ext) if (!extension_loaded($ext)) throw new RuntimeException("PHP extension required: $ext");
    $output = dirname(__DIR__) . '/var/probe';
    if (!is_dir($output) && !mkdir($output, 0755, true)) throw new RuntimeException('Cannot create output directory');
    $lock = fopen($output . '/probe.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Another probe is running');
    $collector = new Collector();
    $listing = $collector->fetch('https://bugaga.ru/jokes/');
    file_put_contents($output . '/listing.html', $listing['body']);
    $links = Collector::extract($listing['body'], $listing['url']);
    if (!$links['posts']) throw new RuntimeException('No post links found; inspect var/probe/listing.html and adjust parser');
    $report = ['collected_at' => gmdate('c'), 'source' => $listing['url'], 'posts_found' => count($links['posts']), 'posts' => [], 'downloads' => []];
    foreach (array_slice($links['posts'], 0, 3) as $index => $url) {
        usleep(1000000);
        $post = $collector->fetch($url);
        file_put_contents($output . '/post-' . $index . '.html', $post['body']);
        $parsed = Collector::extract($post['body'], $post['url']);
        $images = $parsed['images'];
        if (!$images) throw new RuntimeException('No article images found: ' . $url);
        $report['posts'][] = ['url' => $post['url'], 'title' => $parsed['title'], 'published_at' => $parsed['published_at'], 'images' => $images];
        foreach ($images as $image) {
            if (count($report['downloads']) >= 5) break;
            usleep(1000000);
            $response = $collector->fetch($image, 20971520);
            $info = @getimagesizefromstring($response['body']);
            if (!$info) throw new RuntimeException('Response is not an image: ' . $image);
            $extension = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'][$info[2]] ?? null;
            if (!$extension) throw new RuntimeException('Unsupported image type');
            $hash = hash('sha256', $response['body']);
            $file = $hash . '.' . $extension;
            if (!is_file($output . '/' . $file)) file_put_contents($output . '/' . $file, $response['body'], LOCK_EX);
            $report['downloads'][$hash] = ['source' => $image, 'file' => $file, 'width' => $info[0], 'height' => $info[1], 'bytes' => strlen($response['body'])];
        }
    }
    file_put_contents($output . '/report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    if (!$report['downloads']) throw new RuntimeException('No images downloaded; inspect saved post HTML and report.json');
    echo 'Downloaded ' . count($report['downloads']) . " unique images. See var/probe/report.json\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n"); exit(1);
}
