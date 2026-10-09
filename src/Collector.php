<?php
declare(strict_types=1);

final class Collector
{
    public function fetch(string $url, int $limit = 8388608): array
    {
        for ($redirect = 0; $redirect < 5; $redirect++) {
            $parts = parse_url($url);
            if (($parts['scheme'] ?? '') !== 'https' || !in_array(strtolower($parts['host'] ?? ''), ['bugaga.ru', 'www.bugaga.ru'], true) || isset($parts['user']) || isset($parts['port'])) {
                throw new RuntimeException('Unsupported source URL: ' . $url);
            }
            $body = ''; $location = null;
            $ch = curl_init($url);
            // PHP cURL does not consistently pick up uppercase HTTPS_PROXY.
            $proxy = getenv('https_proxy') ?: getenv('HTTPS_PROXY');
            if ($proxy) curl_setopt($ch, CURLOPT_PROXY, $proxy);
            curl_setopt_array($ch, [
                CURLOPT_USERAGENT => 'BugagaFeedCollector/0.1 (PHP feasibility probe)',
                CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 45,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$location): int {
                    if (stripos($line, 'Location:') === 0) $location = trim(substr($line, 9));
                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body, $limit): int {
                    if (strlen($body) + strlen($chunk) > $limit) return 0;
                    $body .= $chunk; return strlen($chunk);
                },
            ]);
            $ok = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            $error = curl_error($ch); curl_close($ch);
            if ($ok === false) throw new RuntimeException("Request failed ($url): $error");
            if ($status >= 300 && $status < 400 && $location !== null) {
                $url = self::resolve($url, $location); continue;
            }
            if ($status !== 200) throw new RuntimeException("HTTP $status: $url");
            return ['body' => $body, 'type' => $type, 'url' => $url];
        }
        throw new RuntimeException('Too many redirects');
    }

    public static function resolve(string $base, string $url): string
    {
        $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (str_starts_with($url, '//')) return 'https:' . $url;
        if (preg_match('~^[a-z]+:~i', $url)) return $url;
        $parts = parse_url($base);
        $path = str_starts_with($url, '/') ? $url : dirname($parts['path'] ?? '/') . '/' . $url;
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') array_pop($segments);
            elseif ($segment !== '' && $segment !== '.') $segments[] = $segment;
        }
        return 'https://' . $parts['host'] . '/' . implode('/', $segments);
    }

    public static function extract(string $html, string $base): array
    {
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors(); libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($doc);
        $posts = []; $images = [];
        foreach ($xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " w_hdr ")]//a[@href]') as $node) {
            $url = self::resolve($base, $node->getAttribute('href'));
            if (preg_match('~^https://(?:www\.)?bugaga\.ru/jokes/[^?#]+\.html$~i', $url)) $posts[$url] = $url;
        }
        foreach ($xpath->query('//*[@itemprop="articleBody"]//img') as $node) {
            $candidates = [];
            if ($node->parentNode instanceof DOMElement && $node->parentNode->tagName === 'a') {
                $candidates[] = $node->parentNode->getAttribute('href');
            }
            foreach (['data-src', 'data-original', 'src'] as $attribute) {
                if (!$node->hasAttribute($attribute)) continue;
                $candidates[] = $node->getAttribute($attribute);
            }
            foreach ($candidates as $candidate) {
                $url = self::resolve($base, $candidate);
                if (preg_match('~^https://(?:www\.)?bugaga\.ru/uploads/posts/[^?#]+\.(?:jpe?g|png|gif|webp)(?:\?[^#]*)?$~i', $url)) {
                    $images[$url] = $url;
                    break;
                }
            }
        }
        return [
            'posts' => array_values($posts), 'images' => array_values($images),
            'published_at' => $xpath->evaluate('string(//meta[@itemprop="datePublished"]/@content)'),
            'title' => trim($xpath->evaluate('string(//h1)')),
        ];
    }
}
