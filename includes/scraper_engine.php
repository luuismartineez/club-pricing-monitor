<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

function fetch_html(string $url): string
{
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 40,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122 Safari/537.36',
        CURLOPT_HTTPHEADER => [
            'Accept-Language: es-ES,es;q=0.9,en;q=0.8'
        ]
    ]);

    $html = curl_exec($ch);

    if ($html === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Error cURL: ' . $error);
    }

    curl_close($ch);

    return $html;
}

function create_xpath_from_html(string $html): array
{
    libxml_use_internal_errors(true);

    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);

    libxml_clear_errors();

    $xpath = new DOMXPath($dom);

    return [$dom, $xpath];
}

function extract_title_from_node(DOMXPath $xpath, DOMNode $node): string
{
    $queries = [
		'.//a[@title]',
        './/img[@alt]',
        './/*[self::h1 or self::h2 or self::h3 or self::h4]',
        './/*[contains(@class,"title")]',
        './/*[contains(@class,"name")]',
        './/*[contains(@class,"product-name")]',
        './/*[contains(@class,"product-title")]'   
    ];

    foreach ($queries as $query) {
        $list = $xpath->query($query, $node);

        if (!$list || $list->length === 0) {
            continue;
        }

        foreach ($list as $item) {
            if (!$item instanceof DOMElement) {
                $value = trim($item->textContent);
            } else {
                if ($item->hasAttribute('alt')) {
                    $value = trim($item->getAttribute('alt'));
                } elseif ($item->hasAttribute('title')) {
                    $value = trim($item->getAttribute('title'));
                } else {
                    $value = trim($item->textContent);
                }
            }

            if ($value !== '' && mb_strlen($value) > 3) {
                return normalize_title($value);
            }
        }
    }

    return '';
}

function extract_image_from_node(DOMXPath $xpath, DOMNode $node, string $baseUrl): string
{
    $imgs = $xpath->query('.//img', $node);

    if (!$imgs || $imgs->length === 0) {
        return '';
    }

    foreach ($imgs as $img) {
        if (!$img instanceof DOMElement) {
            continue;
        }

        $candidates = [];

        foreach (['srcset', 'data-srcset'] as $attr) {
            $value = trim($img->getAttribute($attr));
            if ($value !== '') {
                $parts = explode(',', $value);
                $last = trim(end($parts));
                $urlPart = trim(explode(' ', $last)[0]);
                if ($urlPart !== '') {
                    $candidates[] = $urlPart;
                }
            }
        }

        foreach (['src', 'data-src', 'data-original', 'data-lazy'] as $attr) {
            $value = trim($img->getAttribute($attr));
            if ($value !== '') {
                $candidates[] = $value;
            }
        }

        foreach ($candidates as $src) {
            if (
                $src !== '' &&
                !str_contains($src, 'placeholder') &&
                !str_contains($src, 'data:image') &&
                !str_contains($src, 'icon') &&
                !str_contains($src, 'logo')
            ) {
                return absolute_url($baseUrl, $src);
            }
        }
    }

    return '';
}

function extract_product_url_from_node(DOMXPath $xpath, DOMNode $node, string $baseUrl): string
{
    $links = $xpath->query('.//a[@href]', $node);

    if (!$links || $links->length === 0) {
        return '';
    }

    foreach ($links as $link) {
        if (!$link instanceof DOMElement) {
            continue;
        }

        $href = trim($link->getAttribute('href'));

        if ($href === '' || str_starts_with($href, 'javascript:') || str_starts_with($href, '#')) {
            continue;
        }

        return absolute_url($baseUrl, $href);
    }

    return '';
}

function extract_prices_from_node(DOMXPath $xpath, DOMNode $node): array
{
    $priceTexts = [];

    $queries = [
        './/*[contains(@class,"price-item--sale")]',
        './/*[contains(@class,"price-item--regular")]',
        './/*[contains(@class,"price__sale")]',
        './/*[contains(@class,"price__regular")]',
        './/*[contains(@class,"special-price")]',
        './/*[contains(@class,"regular-price")]',
        './/*[contains(@class,"sale-price")]',
        './/*[contains(@class,"product-price")]',
        './/*[contains(@class,"woocommerce-Price-amount")]',
        './/*[contains(@class,"price")]',
        './/*[@data-price]',
        './/*[@data-regular-price]',
        './/*[@data-sale-price]'
    ];

    foreach ($queries as $query) {
        $list = $xpath->query($query, $node);

        if (!$list || $list->length === 0) {
            continue;
        }

        foreach ($list as $item) {
            if ($item instanceof DOMElement) {
                foreach (['data-price', 'data-regular-price', 'data-sale-price'] as $attr) {
                    $attrValue = trim($item->getAttribute($attr));
                    if ($attrValue !== '') {
                        $priceTexts[] = $attrValue;
                    }
                }
            }

            $txt = trim($item->textContent);
            if ($txt !== '' && preg_match('/\d+[.,]\d{2}/', $txt)) {
                $priceTexts[] = $txt;
            }
        }
    }

    $prices = [];

    foreach ($priceTexts as $txt) {
        preg_match_all('/\d{1,4}(?:[.,]\d{2})/', $txt, $matches);

        foreach ($matches[0] as $candidate) {
            $price = clean_price_to_float($candidate);

            if ($price !== null && is_reasonable_price($price)) {
                $prices[] = $price;
            }
        }
    }

    $prices = array_values(array_unique($prices));
    sort($prices);

    $original = null;
    $discount = null;
    $discountActive = 0;

    if (count($prices) === 1) {
        $original = $prices[0];
    } elseif (count($prices) >= 2) {
        $lowest = min($prices);
        $highest = max($prices);

        if ($lowest < $highest) {
            $original = $highest;
            $discount = $lowest;
            $discountActive = 1;
        } else {
            $original = $highest;
            $discount = null;
            $discountActive = 0;
        }
    }

    return [
        'price_original' => $original,
        'price_discount' => $discount,
        'discount_active' => $discountActive
    ];
}

function is_probably_kit_product(string $title, array $club): bool
{
    if ($title === '') {
        return false;
    }

    $kitType = detect_kit_type($title, $club);

    if ($kitType !== null) {
        return true;
    }

    $titleLower = mb_strtolower($title);

    $genericSignals = [
        'camiseta',
        'shirt',
        'jersey',
        'equipación',
        'home',
        'away',
        'third',
        'local',
        'visitante',
        'tercera'
    ];

    foreach ($genericSignals as $signal) {
        if (mb_strpos($titleLower, $signal) !== false) {
            return true;
        }
    }

    return false;
}

function collect_dom_candidates(DOMXPath $xpath): DOMNodeList|false
{
    $candidateXpath = '//*[
        (
            contains(@class,"product-card") or
            contains(@class,"product-item") or
            contains(@class,"grid-product") or
            contains(@class,"card-product") or
            contains(@class,"product")
        )
        and .//a[contains(@href,"/products/") or contains(@href,"product")]
        and .//img
    ]';

    return $xpath->query($candidateXpath);
}

function scrape_from_dom_cards(array $club, string $html): array
{
    [$dom, $xpath] = create_xpath_from_html($html);
    $nodes = collect_dom_candidates($xpath);

    $results = [];

    if (!$nodes || $nodes->length === 0) {
        return $results;
    }

    foreach ($nodes as $node) {
        $title = extract_title_from_node($xpath, $node);

        if ($title === '') {
            continue;
        }

        if (!is_probably_kit_product($title, $club)) {
            continue;
        }

        $kitType = detect_kit_type($title, $club);

        if ($kitType === null) {
            continue;
        }
		$audience = detect_audience($title);
$garmentType = detect_garment_type($title);
$versionType = detect_version_type($title);

if (!is_valid_target_product($title)) {
    continue;
}

        $prices = extract_prices_from_node($xpath, $node);
$productUrl = extract_product_url_from_node($xpath, $node, $club['store_url']);
$imageUrl = extract_image_from_node($xpath, $node, $club['store_url']);
$cardHtml = sanitize_card_html($dom->saveHTML($node));

if (
    $prices['price_original'] === null ||
    !is_reasonable_price((float)$prices['price_original'])
) {
    continue;
}

       $results[] = [
    'scraped_title' => $title,
    'normalized_title' => normalize_title($title),
    'kit_type' => $kitType,
    'audience' => $audience,
    'garment_type' => $garmentType,
    'version_type' => $versionType,
    'product_url' => $productUrl,
    'image_url' => $imageUrl,
    'price_original' => (float)$prices['price_original'],
    'price_discount' => $prices['price_discount'] !== null ? (float)$prices['price_discount'] : null,
    'discount_active' => (int)$prices['discount_active'],
    'source_card_html' => $cardHtml
];
    }

    return $results;
}

function recursive_collect_json_products(mixed $data, array &$items): void
{
    if (!is_array($data)) {
        return;
    }

    $hasName = isset($data['name']) && is_string($data['name']);
    $hasPrice =
        isset($data['offers']['price']) ||
        isset($data['price']) ||
        isset($data['priceRange']) ||
        isset($data['regular_price']) ||
        isset($data['sale_price']);

    if ($hasName && $hasPrice) {
        $items[] = $data;
    }

    foreach ($data as $value) {
        recursive_collect_json_products($value, $items);
    }
}

function scrape_from_json_ld(array $club, string $html): array
{
    preg_match_all('/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches);

    $results = [];

    foreach ($matches[1] as $jsonBlock) {
        $decoded = json_decode(html_entity_decode($jsonBlock), true);

        if (!$decoded) {
            continue;
        }

        $items = [];
        recursive_collect_json_products($decoded, $items);

        foreach ($items as $item) {
            $title = normalize_title((string)($item['name'] ?? ''));

            if ($title === '' || !is_probably_kit_product($title, $club)) {
                continue;
            }

            $kitType = detect_kit_type($title, $club);
            if ($kitType === null) {
                continue;
            }
			$audience = detect_audience($title);
$garmentType = detect_garment_type($title);
$versionType = detect_version_type($title);

if (!is_valid_target_product($title)) {
    continue;
}

            $image = '';
            if (isset($item['image'])) {
                if (is_array($item['image'])) {
                    $image = (string)($item['image'][0] ?? '');
                } else {
                    $image = (string)$item['image'];
                }
            }

            $url = (string)($item['url'] ?? '');

            $priceOriginal = null;
            if (isset($item['offers']['price'])) {
                $priceOriginal = clean_price_to_float((string)$item['offers']['price']);
            } elseif (isset($item['price'])) {
                $priceOriginal = clean_price_to_float((string)$item['price']);
            } elseif (isset($item['regular_price'])) {
                $priceOriginal = clean_price_to_float((string)$item['regular_price']);
            }

            $priceDiscount = null;
            $discountActive = 0;

            if (isset($item['sale_price'])) {
                $sale = clean_price_to_float((string)$item['sale_price']);
                if ($sale !== null && $priceOriginal !== null && $sale < $priceOriginal) {
                    $priceDiscount = $sale;
                    $discountActive = 1;
                }
            }

            $results[] = [
                'scraped_title' => $title,
                'normalized_title' => normalize_title($title),
                'kit_type' => $kitType,
                'product_url' => absolute_url($club['store_url'], $url),
                'image_url' => absolute_url($club['store_url'], $image),
                'price_original' => (float)$priceOriginal,
                'price_discount' => $priceDiscount !== null ? (float)$priceDiscount : null,
                'discount_active' => $discountActive,
                'source_card_html' => '',
				'audience' => $audience,
				'garment_type' => $garmentType,
				'version_type' => $versionType

            ];
        }
    }

    return $results;
}

function scrape_from_embedded_json(array $club, string $html): array
{
    preg_match_all('/<script[^>]*>(.*?)<\/script>/is', $html, $matches);

    $results = [];

    foreach ($matches[1] as $script) {
        if (
            stripos($script, 'product') === false &&
            stripos($script, 'price') === false &&
            stripos($script, '__NEXT_DATA__') === false
        ) {
            continue;
        }

        preg_match_all('/\{.*\}/Us', $script, $jsonCandidates);

        foreach ($jsonCandidates[0] as $json) {
            $decoded = json_decode($json, true);

            if (!$decoded) {
                continue;
            }

            $items = [];
            recursive_collect_json_products($decoded, $items);

            foreach ($items as $item) {
                $title = normalize_title((string)($item['name'] ?? ''));

                if ($title === '' || !is_probably_kit_product($title, $club)) {
                    continue;
                }

                $kitType = detect_kit_type($title, $club);
                if ($kitType === null) {
                    continue;
                }
				$audience = detect_audience($title);
$garmentType = detect_garment_type($title);
$versionType = detect_version_type($title);

if (!is_valid_target_product($title)) {
    continue;
}

                $url = (string)($item['url'] ?? '');
                $image = '';

                if (isset($item['image'])) {
                    if (is_array($item['image'])) {
                        $image = (string)($item['image'][0] ?? '');
                    } else {
                        $image = (string)$item['image'];
                    }
                }

                $priceOriginal = null;
                if (isset($item['offers']['price'])) {
                    $priceOriginal = clean_price_to_float((string)$item['offers']['price']);
                } elseif (isset($item['price'])) {
                    $priceOriginal = clean_price_to_float((string)$item['price']);
                } elseif (isset($item['regular_price'])) {
                    $priceOriginal = clean_price_to_float((string)$item['regular_price']);
                }

                $priceDiscount = null;
                $discountActive = 0;

                if (isset($item['sale_price'])) {
                    $sale = clean_price_to_float((string)$item['sale_price']);
                    if ($sale !== null && $priceOriginal !== null && $sale < $priceOriginal) {
                        $priceDiscount = $sale;
                        $discountActive = 1;
                    }
                }

                if (isset($item['compareAtPrice'])) {
                    $compare = clean_price_to_float((string)$item['compareAtPrice']);
                    if ($compare !== null && $priceOriginal !== null && $compare > $priceOriginal) {
                        $priceDiscount = $priceOriginal;
                        $priceOriginal = $compare;
                        $discountActive = 1;
                    }
                }

                if ($priceOriginal === null || !is_reasonable_price((float)$priceOriginal)) {
    continue;
}

                $results[] = [
                    'scraped_title' => $title,
                    'normalized_title' => normalize_title($title),
                    'kit_type' => $kitType,
                    'product_url' => absolute_url($club['store_url'], $url),
                    'image_url' => absolute_url($club['store_url'], $image),
                    'price_original' => (float)$priceOriginal,
                    'price_discount' => $priceDiscount !== null ? (float)$priceDiscount : null,
                    'discount_active' => $discountActive,
                    'source_card_html' => '',
					'audience' => $audience,
					'garment_type' => $garmentType,
					'version_type' => $versionType

                ];
            }
        }
    }

    return $results;
}

function reduce_to_one_card_per_category(array $items): array
{
    $best = [];

    foreach ($items as $item) {
        $key =
            $item['kit_type'] . '|' .
            ($item['audience'] ?? 'desconocido') . '|' .
            ($item['garment_type'] ?? 'otra') . '|' .
            ($item['version_type'] ?? 'desconocida');

        $score = 0;

        if (!empty($item['image_url'])) {
            $score += 3;
        }

        if (!empty($item['product_url'])) {
            $score += 2;
        }

        if (is_reasonable_price((float)$item['price_original'])) {
            $score += 3;
        }

        $title = mb_strtolower($item['normalized_title'] ?? '');
        if (mb_strpos($title, 'camiseta') !== false || mb_strpos($title, 'shirt') !== false || mb_strpos($title, 'jersey') !== false) {
            $score += 5;
        }
		
		if (
    mb_strpos($title, 'champions') !== false ||
    mb_strpos($title, 'edition') !== false ||
    mb_strpos($title, 'edición') !== false ||
    mb_strpos($title, 'edicion') !== false
) {
    $score -= 3;
}

        if (
            mb_strpos($title, 'short') !== false ||
            mb_strpos($title, 'pantalón') !== false ||
            mb_strpos($title, 'pantalon') !== false ||
            mb_strpos($title, 'calcet') !== false
        ) {
            $score -= 10;
        }

        $item['_score'] = $score;

        if (!isset($best[$key]) || $item['_score'] > $best[$key]['_score']) {
            $best[$key] = $item;
        }
    }

    foreach ($best as &$row) {
        unset($row['_score']);
    }

    return array_values($best);
}

function dedupe_scraped_products(array $items, int $clubId): array
{
    $unique = [];

    foreach ($items as $item) {
       $keyBase =
    $clubId . '|' .
    ($item['product_url'] ?: $item['normalized_title']) . '|' .
    $item['kit_type'] . '|' .
    ($item['audience'] ?? 'desconocido') . '|' .
    ($item['garment_type'] ?? 'otra') . '|' .
    ($item['version_type'] ?? 'desconocida');
        $hash = md5($keyBase);

        if (!isset($unique[$hash])) {
            $item['source_hash'] = $hash;
            $unique[$hash] = $item;
            continue;
        }

        if (
            $unique[$hash]['source_card_html'] === '' &&
            $item['source_card_html'] !== ''
        ) {
            $unique[$hash]['source_card_html'] = $item['source_card_html'];
        }

        if (
            empty($unique[$hash]['image_url']) &&
            !empty($item['image_url'])
        ) {
            $unique[$hash]['image_url'] = $item['image_url'];
        }
    }

    return reduce_to_one_card_per_category(array_values($unique));
}

function extract_best_image_near_node(DOMXPath $xpath, DOMNode $node, string $baseUrl): string
{
    $contexts = [$node];

    if ($node->parentNode instanceof DOMNode) {
        $contexts[] = $node->parentNode;
    }

    if ($node->parentNode instanceof DOMNode && $node->parentNode->parentNode instanceof DOMNode) {
        $contexts[] = $node->parentNode->parentNode;
    }

    if (
        $node->parentNode instanceof DOMNode &&
        $node->parentNode->parentNode instanceof DOMNode &&
        $node->parentNode->parentNode->parentNode instanceof DOMNode
    ) {
        $contexts[] = $node->parentNode->parentNode->parentNode;
    }

    foreach ($contexts as $context) {
        $imgList = $xpath->query('.//img | .//source | .//*[@src] | .//*[@srcset]', $context);

        if (!$imgList || $imgList->length === 0) {
            continue;
        }

        foreach ($imgList as $imgNode) {
            if (!$imgNode instanceof DOMElement) {
                continue;
            }

            $candidates = [];

            foreach (['srcset', 'data-srcset'] as $attr) {
                $value = trim($imgNode->getAttribute($attr));
                if ($value !== '') {
                    $parts = array_map('trim', explode(',', $value));

                    foreach (array_reverse($parts) as $part) {
                        $urlPart = trim(explode(' ', $part)[0]);
                        if ($urlPart !== '') {
                            $candidates[] = $urlPart;
                        }
                    }
                }
            }

            foreach (['src', 'data-src', 'data-original', 'data-lazy', 'content'] as $attr) {
                $value = trim($imgNode->getAttribute($attr));
                if ($value !== '') {
                    $candidates[] = $value;
                }
            }

            foreach ($candidates as $src) {
                if (
                    $src !== '' &&
                    !str_contains($src, 'placeholder') &&
                    !str_contains($src, 'data:image') &&
                    !str_contains($src, 'icon') &&
                    !str_contains($src, 'logo') &&
                    (
                        str_contains($src, '.jpg') ||
                        str_contains($src, '.jpeg') ||
                        str_contains($src, '.png') ||
                        str_contains($src, '.webp') ||
                        str_contains($src, '/cdn/') ||
                        str_contains($src, 'shopify')
                    )
                ) {
                    return absolute_url($baseUrl, $src);
                }
            }
        }
    }

    return '';
}

function extract_image_from_product_page(string $productUrl): string
{
    try {
        $html = fetch_html($productUrl);
        [$dom, $xpath] = create_xpath_from_html($html);

        // 1) Primero: enlaces directos a imágenes del producto
        $imageLinks = $xpath->query('//a[@href]');
        if ($imageLinks && $imageLinks->length > 0) {
            foreach ($imageLinks as $link) {
                if (!$link instanceof DOMElement) {
                    continue;
                }

                $href = trim($link->getAttribute('href'));

                if (
                    $href !== '' &&
                    (
                        str_contains($href, '/cdn/shop/files/') ||
                        str_contains($href, '.jpg') ||
                        str_contains($href, '.jpeg') ||
                        str_contains($href, '.png') ||
                        str_contains($href, '.webp')
                    ) &&
                    !str_contains($href, 'placeholder') &&
                    !str_contains($href, 'icon') &&
                    !str_contains($href, 'logo')
                ) {
                    return absolute_url($productUrl, $href);
                }
            }
        }

        // 2) Meta tags de imagen
        $metaQueries = [
            '//meta[@property="og:image"]',
            '//meta[@name="twitter:image"]',
            '//meta[@property="twitter:image"]'
        ];

        foreach ($metaQueries as $query) {
            $list = $xpath->query($query);
            if ($list && $list->length > 0) {
                foreach ($list as $meta) {
                    if ($meta instanceof DOMElement) {
                        $content = trim($meta->getAttribute('content'));
                        if (
                            $content !== '' &&
                            !str_contains($content, 'placeholder') &&
                            !str_contains($content, 'icon') &&
                            !str_contains($content, 'logo')
                        ) {
                            return absolute_url($productUrl, $content);
                        }
                    }
                }
            }
        }

        // 3) Imágenes normales
        $imgList = $xpath->query('//img | //source');
        if ($imgList && $imgList->length > 0) {
            foreach ($imgList as $imgNode) {
                if (!$imgNode instanceof DOMElement) {
                    continue;
                }

                $candidates = [];

                foreach (['srcset', 'data-srcset'] as $attr) {
                    $value = trim($imgNode->getAttribute($attr));
                    if ($value !== '') {
                        $parts = array_map('trim', explode(',', $value));
                        foreach (array_reverse($parts) as $part) {
                            $urlPart = trim(explode(' ', $part)[0]);
                            if ($urlPart !== '') {
                                $candidates[] = $urlPart;
                            }
                        }
                    }
                }

                foreach (['src', 'data-src', 'data-original', 'data-lazy', 'content'] as $attr) {
                    $value = trim($imgNode->getAttribute($attr));
                    if ($value !== '') {
                        $candidates[] = $value;
                    }
                }

                foreach ($candidates as $src) {
                    if (
                        $src !== '' &&
                        !str_contains($src, 'placeholder') &&
                        !str_contains($src, 'data:image') &&
                        !str_contains($src, 'icon') &&
                        !str_contains($src, 'logo') &&
                        (
                            str_contains($src, '.jpg') ||
                            str_contains($src, '.jpeg') ||
                            str_contains($src, '.png') ||
                            str_contains($src, '.webp') ||
                            str_contains($src, '/cdn/') ||
                            str_contains($src, 'shopify')
                        )
                    ) {
                        return absolute_url($productUrl, $src);
                    }
                }
            }
        }
    } catch (Throwable $e) {
        return '';
    }

    return '';
}
function find_product_card_context(DOMNode $node): DOMNode
{
    $current = $node;

    for ($i = 0; $i < 6; $i++) {
        if (!$current->parentNode instanceof DOMNode) {
            break;
        }

        $current = $current->parentNode;

        if ($current instanceof DOMElement) {
            $tag = mb_strtolower($current->tagName);
            $class = $current->getAttribute('class');

            if (
                in_array($tag, ['article', 'li'], true) ||
                str_contains($class, 'card') ||
                str_contains($class, 'product') ||
                str_contains($class, 'item') ||
                str_contains($class, 'grid')
            ) {
                return $current;
            }
        }
    }

    return $node;
}

function scrape_real_madrid_product_page(string $productUrl, array $club): ?array
{
    $productUrl = strtok($productUrl, '?') ?: $productUrl;

    try {
        $html = fetch_html($productUrl);
        [$dom, $xpath] = create_xpath_from_html($html);

        $title = '';
        $imageUrl = '';
        $priceOriginal = null;
        $priceDiscount = null;
        $discountActive = 0;

        // 1) Intentar con JSON-LD del producto
        preg_match_all('/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches);

        foreach ($matches[1] as $jsonBlock) {
            $decoded = json_decode(html_entity_decode($jsonBlock), true);

            if (!$decoded) {
                continue;
            }

            $items = [];
            recursive_collect_json_products($decoded, $items);

            foreach ($items as $item) {
                $jsonTitle = normalize_title((string)($item['name'] ?? ''));

                if ($jsonTitle !== '') {
                    $title = $jsonTitle;
                }

                if (isset($item['image'])) {
                    if (is_array($item['image'])) {
                        $imageUrl = absolute_url($productUrl, (string)($item['image'][0] ?? ''));
                    } else {
                        $imageUrl = absolute_url($productUrl, (string)$item['image']);
                    }
                }

                if (isset($item['offers']['price'])) {
                    $priceOriginal = clean_price_to_float((string)$item['offers']['price']);
                } elseif (isset($item['price'])) {
                    $priceOriginal = clean_price_to_float((string)$item['price']);
                }

                break;
            }

            if ($title !== '' || $priceOriginal !== null || $imageUrl !== '') {
                break;
            }
        }

        // 2) Fallback de título
        if ($title === '') {
            $h1 = $xpath->query('//h1');
            if ($h1 && $h1->length > 0) {
                $title = normalize_title(trim($h1->item(0)->textContent));
            }
        }

        if ($title === '') {
            $metaTitle = $xpath->query('//meta[@property="og:title"]');
            if ($metaTitle && $metaTitle->length > 0 && $metaTitle->item(0) instanceof DOMElement) {
                $title = normalize_title(trim($metaTitle->item(0)->getAttribute('content')));
            }
        }

        // 3) Fallback de imagen
        if ($imageUrl === '') {
            $metaImage = $xpath->query('//meta[@property="og:image"]');
            if ($metaImage && $metaImage->length > 0 && $metaImage->item(0) instanceof DOMElement) {
                $imageUrl = absolute_url($productUrl, trim($metaImage->item(0)->getAttribute('content')));
            }
        }

        if ($imageUrl === '') {
            $imageUrl = extract_image_from_product_page($productUrl);
        }

        // 4) Fallback de precio leyendo bloques reales de precio
        if ($priceOriginal === null) {
            $priceQueries = [
                '//*[contains(@class,"price")]',
                '//*[contains(@class,"money")]',
                '//*[contains(text(),"€")]'
            ];

            $priceTexts = [];

            foreach ($priceQueries as $query) {
                $nodes = $xpath->query($query);
                if (!$nodes) {
                    continue;
                }

                foreach ($nodes as $node) {
                    $txt = trim($node->textContent);
                    if ($txt !== '' && preg_match('/\d+[.,]\d{2}/', $txt)) {
                        $priceTexts[] = $txt;
                    }
                }
            }

            $allPrices = [];

            foreach ($priceTexts as $txt) {
                preg_match_all('/\d{1,4}(?:[.,]\d{2})/', $txt, $m);
                foreach ($m[0] as $candidate) {
                    $p = clean_price_to_float($candidate);
                    if ($p !== null && is_reasonable_price($p)) {
                        $allPrices[] = $p;
                    }
                }
            }

            $allPrices = array_values(array_unique($allPrices));
            sort($allPrices);

            if (count($allPrices) === 1) {
                $priceOriginal = $allPrices[0];
            } elseif (count($allPrices) === 2) {
                $priceDiscount = min($allPrices);
                $priceOriginal = max($allPrices);

                if ($priceDiscount < $priceOriginal) {
                    $discountActive = 1;
                } else {
                    $priceDiscount = null;
                    $discountActive = 0;
                }
            }
        }

        if ($title === '' || $priceOriginal === null || !is_reasonable_price((float)$priceOriginal)) {
            return null;
        }

        $kitType = detect_kit_type($title, $club);
        if ($kitType === null) {
            return null;
        }

        $audience = detect_audience($title);
        $garmentType = detect_garment_type($title);
        $versionType = detect_version_type($title);

        if (!is_valid_target_product($title)) {
            return null;
        }

        return [
            'scraped_title' => $title,
            'normalized_title' => $title,
            'kit_type' => $kitType,
            'audience' => $audience,
            'garment_type' => $garmentType,
            'version_type' => $versionType,
            'product_url' => $productUrl,
            'image_url' => $imageUrl,
            'price_original' => (float)$priceOriginal,
            'price_discount' => $priceDiscount !== null ? (float)$priceDiscount : null,
            'discount_active' => $discountActive,
            'source_card_html' => ''
        ];
    } catch (Throwable $e) {
        return null;
    }
}

function fetch_json_url(string $url): ?array
{
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; ClubPricingMonitor/1.0)',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false || $httpCode >= 400 || $error) {
        return null;
    }

    $decoded = json_decode($response, true);

    return is_array($decoded) ? $decoded : null;
}

function get_shopify_handle_from_product_url(string $productUrl): ?string
{
    $path = parse_url($productUrl, PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        return null;
    }

    if (!preg_match('#/products/([^/?]+)#', $path, $matches)) {
        return null;
    }

    return $matches[1] ?? null;
}

function madrid_cart_request(string $url, string $method = 'GET', ?array $payload = null, ?string $cookieFile = null): ?array
{
    $ch = curl_init($url);

    $headers = [
        'User-Agent: Mozilla/5.0 (compatible; ClubPricingMonitor/1.0)',
        'Accept: application/json, text/javascript, */*; q=0.01',
        'X-Requested-With: XMLHttpRequest'
    ];

    if ($method === 'POST') {
        $headers[] = 'Content-Type: application/json';
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; ClubPricingMonitor/1.0)',
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);

    if ($cookieFile !== null) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    }

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false || $httpCode >= 400 || $error) {
        return null;
    }

    $decoded = json_decode($response, true);

    return is_array($decoded) ? $decoded : null;
}

function madrid_get_variants_from_product_json(string $productUrl): array
{
    $handle = get_shopify_handle_from_product_url($productUrl);
    if ($handle === null) {
        return [];
    }

    $jsonUrl = 'https://shop.realmadrid.com/products/' . $handle . '.js';
    $productData = fetch_json_url($jsonUrl);

    if (!$productData || empty($productData['variants']) || !is_array($productData['variants'])) {
        return [];
    }

    $variants = [];

    foreach ($productData['variants'] as $variant) {
        if (!is_array($variant) || empty($variant['id'])) {
            continue;
        }

        $variants[] = $variant;
    }

    // Primero disponibles, luego el resto
    usort($variants, function ($a, $b) {
        $aAvailable = !empty($a['available']) ? 1 : 0;
        $bAvailable = !empty($b['available']) ? 1 : 0;
        return $bAvailable <=> $aAvailable;
    });

    return $variants;
}

function madrid_get_cart_discount_prices(string $productUrl): ?array
{
    $variants = madrid_get_variants_from_product_json($productUrl);

    if (count($variants) === 0) {
        return null;
    }

    $bestResult = null;

    foreach ($variants as $variant) {
        $variantId = (int)($variant['id'] ?? 0);
        if ($variantId <= 0) {
            continue;
        }

        $cookieFile = tempnam(sys_get_temp_dir(), 'rmcart_');
        if ($cookieFile === false) {
            continue;
        }

        try {
            $addResponse = madrid_cart_request(
                'https://shop.realmadrid.com/cart/add.js',
                'POST',
                [
                    'items' => [
                        [
                            'id' => $variantId,
                            'quantity' => 1
                        ]
                    ]
                ],
                $cookieFile
            );

            if ($addResponse === null) {
                @unlink($cookieFile);
                continue;
            }

            $cart = madrid_cart_request(
                'https://shop.realmadrid.com/cart.js',
                'GET',
                null,
                $cookieFile
            );

            madrid_cart_request(
                'https://shop.realmadrid.com/cart/clear.js',
                'POST',
                [],
                $cookieFile
            );

            @unlink($cookieFile);

            if ($cart === null || empty($cart['items']) || !is_array($cart['items'])) {
                continue;
            }

            $item = $cart['items'][0] ?? null;
            if (!is_array($item)) {
                continue;
            }

            $originalCents = isset($item['original_price']) ? (int)$item['original_price'] : 0;
            $finalCents = isset($item['final_price']) ? (int)$item['final_price'] : 0;

            if ($originalCents <= 0 && isset($item['original_line_price'])) {
                $originalCents = (int)$item['original_line_price'];
            }

            if ($finalCents <= 0 && isset($item['final_line_price'])) {
                $finalCents = (int)$item['final_line_price'];
            }

            if ($finalCents <= 0) {
                continue;
            }

            $priceOriginal = round(($originalCents > 0 ? $originalCents : $finalCents) / 100, 2);
            $priceDiscount = null;
            $discountActive = 0;

            if ($originalCents > $finalCents && $originalCents > 0) {
                $priceDiscount = round($finalCents / 100, 2);
                $discountActive = 1;
            }

            $currentResult = [
                'price_original' => $priceOriginal,
                'price_discount' => $priceDiscount,
                'discount_active' => $discountActive
            ];

            // Si encontramos una variante con descuento real, nos la quedamos directamente
            if ($discountActive === 1) {
                return $currentResult;
            }

            // Si no hay descuento, nos quedamos con la mejor opción sin descuento
            if ($bestResult === null) {
                $bestResult = $currentResult;
            }
        } catch (Throwable $e) {
            @unlink($cookieFile);
            continue;
        }
    }

    return $bestResult;
}

function validate_real_madrid_product_from_json(array $item, array $club): ?array
{
    $productUrl = trim((string)($item['product_url'] ?? ''));
    if ($productUrl === '') {
        return null;
    }

    $productUrl = strtok($productUrl, '?') ?: $productUrl;

    $handle = get_shopify_handle_from_product_url($productUrl);
    if ($handle === null) {
        return null;
    }

    $jsonUrl = 'https://shop.realmadrid.com/products/' . $handle . '.js';
    $productData = fetch_json_url($jsonUrl);

    if (!$productData) {
        return null;
    }

    $title = clean_display_title((string)($productData['title'] ?? ''));
    if ($title === '') {
        $title = clean_display_title((string)($item['normalized_title'] ?? $item['scraped_title'] ?? ''));
    }

    $imageUrl = '';
    if (!empty($productData['images']) && is_array($productData['images'])) {
        $imageUrl = (string)($productData['images'][0] ?? '');
    }

    if ($imageUrl === '' && !empty($productData['featured_image'])) {
        $imageUrl = (string)$productData['featured_image'];
    }

    if ($imageUrl === '') {
        $imageUrl = (string)($item['image_url'] ?? '');
    }

    $variants = $productData['variants'] ?? [];
    if (!is_array($variants) || count($variants) === 0) {
        return null;
    }

    // Elegimos la primera variante disponible; en camisetas suele cambiar talla, no precio.
    $variant = null;
    foreach ($variants as $v) {
        if (!is_array($v)) {
            continue;
        }

        if (!empty($v['available'])) {
            $variant = $v;
            break;
        }

        if ($variant === null) {
            $variant = $v;
        }
    }

    if (!is_array($variant)) {
        return null;
    }

    $priceCents = isset($variant['price']) ? (int)$variant['price'] : 0;
$compareCents = isset($variant['compare_at_price']) && $variant['compare_at_price'] !== null
    ? (int)$variant['compare_at_price']
    : 0;

if ($priceCents <= 0) {
    return null;
}

$price = round($priceCents / 100, 2);
$compare = $compareCents > 0 ? round($compareCents / 100, 2) : null;

if (!is_reasonable_price($price)) {
    return null;
}

$discountActive = 0;
$priceOriginal = $price;
$priceDiscount = null;

/*
|--------------------------------------------------------------------------
| 1) Primero intentamos descuento real por carrito
|--------------------------------------------------------------------------
*/
$cartPricing = madrid_get_cart_discount_prices($productUrl);

if ($cartPricing !== null && is_reasonable_price((float)$cartPricing['price_original'])) {
    $priceOriginal = (float)$cartPricing['price_original'];
    $priceDiscount = $cartPricing['price_discount'] !== null ? (float)$cartPricing['price_discount'] : null;
    $discountActive = (int)$cartPricing['discount_active'];
} else {
    /*
    |--------------------------------------------------------------------------
    | 2) Fallback al JSON .js normal
    |--------------------------------------------------------------------------
    */
    if ($compare !== null && $compare > $price && is_reasonable_price($compare)) {
        $discountActive = 1;
        $priceOriginal = $compare;
        $priceDiscount = $price;
    }
}

    $kitType = detect_kit_type($title, $club) ?? (string)$item['kit_type'];

$audience = detect_audience($title);
if ($audience === 'desconocido') {
    $audience = detect_audience_from_url($productUrl);
}
if ($audience === 'desconocido') {
    $audience = (string)$item['audience'];
}

    $garmentType = detect_garment_type($title);
    if ($garmentType === 'otra') {
        $garmentType = (string)$item['garment_type'];
    }

    $versionType = detect_version_type($title);
    if ($versionType === 'desconocida') {
        $versionType = (string)$item['version_type'];
    }

    if (!is_valid_target_product($title)) {
        return null;
    }

    return [
        'scraped_title' => $title,
        'normalized_title' => clean_display_title($title),
        'kit_type' => $kitType,
        'audience' => $audience,
        'garment_type' => $garmentType,
        'version_type' => $versionType,
        'product_url' => $productUrl,
        'image_url' => $imageUrl,
        'price_original' => $priceOriginal,
        'price_discount' => $priceDiscount,
        'discount_active' => $discountActive,
        'source_card_html' => (string)($item['source_card_html'] ?? '')
    ];
}

function phaseb_validate_real_madrid_products(array $items, array $club): array
{
    $validated = [];

    foreach ($items as $item) {
        $v = validate_real_madrid_product_from_json($item, $club);
        if ($v !== null) {
            $validated[] = $v;
        }
    }

    return dedupe_scraped_products($validated, (int)$club['id']);
}

function scrape_real_madrid_collection(array $club): array
{
    $html = fetch_html($club['store_url']);
    [$dom, $xpath] = create_xpath_from_html($html);

    $links = $xpath->query('//a[@href]');
    $items = [];
    $seenUrls = [];

    if (!$links) {
        return [];
    }

    foreach ($links as $link) {
        if (!$link instanceof DOMElement) {
            continue;
        }

        $href = trim($link->getAttribute('href'));
        if ($href === '') {
            continue;
        }

        $productUrl = absolute_url($club['store_url'], $href);
        $productUrl = strtok($productUrl, '?') ?: $productUrl;

        if (
            mb_strpos($productUrl, '/products/') === false &&
            mb_strpos($productUrl, '/product/') === false
        ) {
            continue;
        }

        if (isset($seenUrls[$productUrl])) {
            continue;
        }

        $seenUrls[$productUrl] = true;

        $item = scrape_real_madrid_product_page($productUrl, $club);

        if ($item !== null) {
            $items[] = $item;
        }
    }

    return dedupe_scraped_products($items, (int)$club['id']);
}

function scrape_real_oviedo_collection(array $club): array
{
    $html = fetch_html($club['store_url']);
    [$dom, $xpath] = create_xpath_from_html($html);

    $links = $xpath->query('//a[@href]');
    $items = [];

    if (!$links) {
        return [];
    }

    foreach ($links as $link) {
        if (!$link instanceof DOMElement) {
            continue;
        }

        $href = trim($link->getAttribute('href'));
        $text = trim(preg_replace('/\s+/', ' ', $link->textContent));

        if ($href === '' || $text === '') {
            continue;
        }

        $productUrl = absolute_url($club['store_url'], $href);

        // Solo productos reales
        if (
            mb_strpos($productUrl, '/producto/') === false &&
            mb_strpos($productUrl, '/product/') === false &&
            mb_strpos($productUrl, '/tienda/') === false
        ) {
            continue;
        }

        if (!is_valid_target_product($text)) {
            continue;
        }

        $title = normalize_title($text);
        $kitType = detect_kit_type($title, $club);

        if ($kitType === null) {
            continue;
        }

        $audience = detect_audience($title);
        if ($audience === 'desconocido') {
            $audience = detect_audience_from_url($productUrl);
        }

        $garmentType = detect_garment_type($title);
        $versionType = detect_version_type($title);

        // Intentamos leer dos precios del bloque para detectar descuento
        $prices = extract_prices_from_node($xpath, $link);
        $priceOriginal = $prices['price_original'] !== null ? (float)$prices['price_original'] : null;
        $priceDiscount = $prices['price_discount'] !== null ? (float)$prices['price_discount'] : null;
        $discountActive = (int)$prices['discount_active'];

        // Fallback: si no encuentra precio en el nodo, usa el texto del enlace
        if ($priceOriginal === null || !is_reasonable_price($priceOriginal)) {
            $priceOriginal = extract_single_reasonable_price_from_text($text);
            $priceDiscount = null;
            $discountActive = 0;
        }

        if ($priceOriginal === null || !is_reasonable_price($priceOriginal)) {
            continue;
        }

        $imageUrl = extract_best_image_near_node($xpath, $link, $club['store_url']);

        $items[] = [
            'scraped_title' => $title,
            'normalized_title' => $title,
            'kit_type' => $kitType,
            'audience' => $audience,
            'garment_type' => $garmentType,
            'version_type' => $versionType,
            'product_url' => $productUrl,
            'image_url' => $imageUrl,
            'price_original' => $priceOriginal,
            'price_discount' => $priceDiscount,
            'discount_active' => $discountActive,
            'source_card_html' => sanitize_card_html($dom->saveHTML($link))
        ];
    }

    return dedupe_scraped_products($items, (int)$club['id']);
}

function scrape_espanyol_product_page(
    string $productUrl,
    string $forcedKitType,
    string $forcedAudience,
    string $fallbackTitle = ''
): ?array {
    try {
        $html = fetch_html($productUrl);
        [$dom, $xpath] = create_xpath_from_html($html);

        $title = $fallbackTitle;

        $h1 = $xpath->query('//h1');
        if ($h1 && $h1->length > 0) {
            $h1Text = normalize_title(trim($h1->item(0)->textContent));
            if ($h1Text !== '') {
                $title = $h1Text;
            }
        }

        if ($title === '' || !is_valid_target_product($title)) {
            return null;
        }

        $garmentType = detect_garment_type($title);
        if ($garmentType !== 'camiseta') {
            return null;
        }

        $priceOriginal = null;
        $priceDiscount = null;
        $discountActive = 0;

        $mainPriceText = '';

        $priceQueries = [
            '//div[contains(@class,"product-prices")]//*[contains(text(),"€")]',
            '//div[contains(@class,"product-price")]//*[contains(text(),"€")]',
            '//div[contains(@class,"current-price")]//*[contains(text(),"€")]',
            '//span[contains(@class,"current-price")]//*[contains(text(),"€")]',
            '//span[contains(@class,"price")]//*[contains(text(),"€")]',
            '//p[contains(@class,"price")]//*[contains(text(),"€")]',
        ];

        foreach ($priceQueries as $query) {
            $nodes = $xpath->query($query);

            if (!$nodes || $nodes->length === 0) {
                continue;
            }

            foreach ($nodes as $node) {
                $txt = trim(preg_replace('/\s+/', ' ', $node->textContent));

                if ($txt !== '' && preg_match('/\d+[.,]\d{2}/', $txt)) {
                    $mainPriceText = $txt;
                    break 2;
                }
            }
        }

        if ($mainPriceText === '') {
            $mainBlock = $xpath->query('//main | //section[contains(@id,"main")] | //div[contains(@id,"main")]');
            if ($mainBlock && $mainBlock->length > 0) {
                $txt = trim(preg_replace('/\s+/', ' ', $mainBlock->item(0)->textContent));

                if (preg_match('/\d{1,4}(?:[.,]\d{2})/', $txt, $m)) {
                    $mainPriceText = $m[0];
                }
            }
        }

        $prices = [];
        if ($mainPriceText !== '') {
            preg_match_all('/\d{1,4}(?:[.,]\d{2})/', $mainPriceText, $matches);

            foreach ($matches[0] as $candidate) {
                $p = clean_price_to_float($candidate);
                if ($p !== null && is_reasonable_price($p)) {
                    $prices[] = $p;
                }
            }
        }

        $prices = array_values(array_unique($prices));
        sort($prices);

        if (count($prices) === 1) {
            $priceOriginal = $prices[0];
        } elseif (count($prices) >= 2) {
            $lowest = min($prices);
            $highest = max($prices);

            if ($lowest < $highest) {
                $priceOriginal = $highest;
                $priceDiscount = $lowest;
                $discountActive = 1;
            } else {
                $priceOriginal = $highest;
            }
        }

        if ($priceOriginal === null || !is_reasonable_price((float)$priceOriginal)) {
            return null;
        }

        $versionType = detect_version_type($title);
        if ($versionType === 'desconocida') {
            $versionType = 'fan';
        }

        $imageUrl = extract_image_from_product_page($productUrl);

        return [
            'scraped_title' => $title,
            'normalized_title' => normalize_title($title),
            'kit_type' => $forcedKitType,
            'audience' => $forcedAudience,
            'garment_type' => 'camiseta',
            'version_type' => $versionType,
            'product_url' => $productUrl,
            'image_url' => $imageUrl,
            'price_original' => (float)$priceOriginal,
            'price_discount' => $priceDiscount !== null ? (float)$priceDiscount : null,
            'discount_active' => $discountActive,
            'source_card_html' => ''
        ];
    } catch (Throwable $e) {
        return null;
    }
}


function scrape_espanyol_collections(array $club): array
{
    $collections = [
        ['url' => 'https://shop.rcdespanyol.com/es/1950-1-equipacion', 'kit_type' => '1'],
        ['url' => 'https://shop.rcdespanyol.com/es/1958-2-equipacion', 'kit_type' => '2'],
        ['url' => 'https://shop.rcdespanyol.com/es/1964-3-equipacion', 'kit_type' => '3'],
    ];

    $all = [];
    $seenUrls = [];

    foreach ($collections as $collection) {
        $html = fetch_html($collection['url']);
        [$dom, $xpath] = create_xpath_from_html($html);

        $links = $xpath->query('//h2/a[@href]');

        if (!$links) {
            continue;
        }

        foreach ($links as $link) {
            if (!$link instanceof DOMElement) {
                continue;
            }

            $href = trim($link->getAttribute('href'));
            $text = normalize_title(trim(preg_replace('/\s+/', ' ', $link->textContent)));

            if ($href === '' || $text === '') {
                continue;
            }

            $productUrl = absolute_url($collection['url'], $href);
            $productUrl = strtok($productUrl, '#') ?: $productUrl;

            if (isset($seenUrls[$productUrl])) {
                continue;
            }
            $seenUrls[$productUrl] = true;

            if (!str_contains($productUrl, '.html')) {
                continue;
            }

            if (!is_valid_target_product($text)) {
                continue;
            }

            if (detect_garment_type($text) !== 'camiseta') {
                continue;
            }

            $audience = detect_audience($text);

            if ($audience === 'desconocido') {
                $t = mb_strtolower($text);

                if (
                    mb_strpos($t, 'infantil') !== false ||
                    mb_strpos($t, 'junior') !== false ||
                    mb_strpos($t, 'niño') !== false ||
                    mb_strpos($t, 'nino') !== false
                ) {
                    $audience = 'nino';
                } else {
                    $audience = 'hombre';
                }
            }

            $item = scrape_espanyol_product_page(
                $productUrl,
                (string)$collection['kit_type'],
                $audience,
                $text
            );

            if ($item !== null) {
                $all[] = $item;
            }
        }
    }

    return dedupe_scraped_products($all, (int)$club['id']);
}

function scrape_villarreal_collections(array $club): array
{
    $collections = [
        ['url' => 'https://shop.villarrealcf.es/collections/primera-equipacion-hombre', 'kit_type' => '1', 'audience' => 'hombre'],
        ['url' => 'https://shop.villarrealcf.es/collections/primera-equipacion-mujer', 'kit_type' => '1', 'audience' => 'mujer'],
        ['url' => 'https://shop.villarrealcf.es/collections/primera-equipacion-nino',  'kit_type' => '1', 'audience' => 'nino'],

        ['url' => 'https://shop.villarrealcf.es/collections/segunda-equipacion-hombre', 'kit_type' => '2', 'audience' => 'hombre'],
        ['url' => 'https://shop.villarrealcf.es/collections/segunda-equipacion-mujer', 'kit_type' => '2', 'audience' => 'mujer'],
        ['url' => 'https://shop.villarrealcf.es/collections/segunda-equipacion-nino',  'kit_type' => '2', 'audience' => 'nino'],

        ['url' => 'https://shop.villarrealcf.es/collections/tercera-equipacion-hombre', 'kit_type' => '3', 'audience' => 'hombre'],
        ['url' => 'https://shop.villarrealcf.es/collections/tercera-equipacion-mujer', 'kit_type' => '3', 'audience' => 'mujer'],
        ['url' => 'https://shop.villarrealcf.es/collections/tercera-equipacion-nino',  'kit_type' => '3', 'audience' => 'nino'],
    ];

    $all = [];
    $seenUrls = [];

    foreach ($collections as $collection) {
        $html = fetch_html($collection['url']);
        [$dom, $xpath] = create_xpath_from_html($html);

        $links = $xpath->query('//a[@href]');
        if (!$links) {
            continue;
        }

        foreach ($links as $link) {
            if (!$link instanceof DOMElement) {
                continue;
            }

            $href = trim($link->getAttribute('href'));
            if ($href === '') {
                continue;
            }

            $productUrl = absolute_url($collection['url'], $href);
            $productUrl = strtok($productUrl, '?') ?: $productUrl;

            if (mb_strpos($productUrl, '/products/') === false) {
                continue;
            }

            if (isset($seenUrls[$productUrl])) {
                continue;
            }

            $seenUrls[$productUrl] = true;

            $contextNode = find_product_card_context($link);
            $title = extract_title_from_node($xpath, $contextNode);

            if ($title === '') {
                $title = trim($link->getAttribute('title'));
            }

            if ($title === '') {
                $title = trim($link->textContent);
            }

            if ($title === '') {
                $title = trim(preg_replace('/\s+/', ' ', $contextNode->textContent));
            }

            if ($title === '') {
                continue;
            }

            $title = normalize_title($title);

            if (!is_valid_target_product($title)) {
                continue;
            }

            $garmentType = detect_garment_type($title);
            $versionType = detect_version_type($title);
            if ($versionType === 'desconocida') {
                $versionType = 'fan';
            }

            $prices = extract_prices_from_node($xpath, $contextNode);
            $priceOriginal = $prices['price_original'] !== null ? (float)$prices['price_original'] : null;
            $priceDiscount = $prices['price_discount'] !== null ? (float)$prices['price_discount'] : null;
            $discountActive = (int)$prices['discount_active'];

            if ($priceOriginal === null || !is_reasonable_price($priceOriginal)) {
                $fallbackText = trim(preg_replace('/\s+/', ' ', $contextNode->textContent));
                $priceOriginal = extract_single_reasonable_price_from_text($fallbackText);
                $priceDiscount = null;
                $discountActive = 0;
            }

            if ($priceOriginal === null || !is_reasonable_price($priceOriginal)) {
                continue;
            }

            $imageUrl = extract_best_image_near_node($xpath, $contextNode, $collection['url']);
            if ($imageUrl === '') {
                $imageUrl = extract_image_from_product_page($productUrl);
            }

            $all[] = [
                'scraped_title' => $title,
                'normalized_title' => normalize_title($title),
                'kit_type' => $collection['kit_type'],
                'audience' => $collection['audience'],
                'garment_type' => $garmentType,
                'version_type' => $versionType,
                'product_url' => $productUrl,
                'image_url' => $imageUrl,
                'price_original' => $priceOriginal,
                'price_discount' => $discountActive ? $priceDiscount : null,
                'discount_active' => $discountActive,
                'source_card_html' => sanitize_card_html($dom->saveHTML($contextNode))
            ];
        }
    }

    return dedupe_scraped_products($all, (int)$club['id']);
}

function scrape_real_betis_collections(array $club): array
{
    $collections = [
        ['label' => '1_hombre', 'url' => 'https://shop.realbetisbalompie.es/collections/primera-equipacion-hombre', 'kit_type' => '1', 'audience' => 'hombre'],
        ['label' => '1_mujer',  'url' => 'https://shop.realbetisbalompie.es/collections/primera-equipacion-mujer',  'kit_type' => '1', 'audience' => 'mujer'],
        ['label' => '1_nino',   'url' => 'https://shop.realbetisbalompie.es/collections/primera-equipacion-ninos',  'kit_type' => '1', 'audience' => 'nino'],

        ['label' => '2_hombre', 'url' => 'https://shop.realbetisbalompie.es/collections/segunda-equipacion-hombre', 'kit_type' => '2', 'audience' => 'hombre'],
        ['label' => '2_mujer',  'url' => 'https://shop.realbetisbalompie.es/collections/segunda-equipacion-mujer',  'kit_type' => '2', 'audience' => 'mujer'],
        ['label' => '2_nino',   'url' => 'https://shop.realbetisbalompie.es/collections/segunda-equipacion-ninos',  'kit_type' => '2', 'audience' => 'nino'],

        ['label' => '3_hombre', 'url' => 'https://shop.realbetisbalompie.es/collections/tercera-equpacion-hombre', 'kit_type' => '3', 'audience' => 'hombre'],
        ['label' => '3_nino',   'url' => 'https://shop.realbetisbalompie.es/collections/tercera-equipacion-ninos',  'kit_type' => '3', 'audience' => 'nino'],
    ];

    $all = [];
    $debug = [];

    foreach ($collections as $collection) {
        $acceptedInCollection = 0;
        $visitedProducts = 0;
        $seenUrlsInThisCollection = [];

        $html = fetch_html($collection['url']);
        [$dom, $xpath] = create_xpath_from_html($html);

        $productLinks = $xpath->query('//a[contains(@href,"/products/")]');
        if (!$productLinks) {
            $debug[] = $collection['label'] . ' | visited=0 | accepted=0 | url=' . $collection['url'];
            continue;
        }

        foreach ($productLinks as $link) {
            if (!$link instanceof DOMElement) {
                continue;
            }

            $href = trim($link->getAttribute('href'));
            if ($href === '') {
                continue;
            }

            $productUrl = absolute_url($collection['url'], $href);
            $productUrl = strtok($productUrl, '?') ?: $productUrl;

            if (isset($seenUrlsInThisCollection[$productUrl])) {
                continue;
            }

            $seenUrlsInThisCollection[$productUrl] = true;
            $visitedProducts++;

            $contextNode = find_product_card_context($link);

            $title = extract_title_from_node($xpath, $contextNode);
            if ($title === '') {
                $title = trim($link->textContent);
            }
            if ($title === '') {
                $title = trim(preg_replace('/\s+/', ' ', $contextNode->textContent));
            }

            $title = normalize_title($title);

            if ($title === '') {
                continue;
            }

            if (!is_valid_target_product($title)) {
                continue;
            }

            $garmentType = detect_garment_type($title);
            if ($garmentType !== 'camiseta') {
                continue;
            }

            $productData = scrape_real_betis_product_page(
                $productUrl,
                $collection['kit_type'],
                $collection['audience'],
                $title
            );

            if ($productData === null) {
                continue;
            }

            $all[] = $productData;
            $acceptedInCollection++;
        }

        $debug[] = $collection['label'] . ' | visited=' . $visitedProducts . ' | accepted=' . $acceptedInCollection . ' | url=' . $collection['url'];
    }

    return reduce_betis_to_base_shirts(
        dedupe_scraped_products($all, (int)$club['id'])
    );
}

function scrape_atletico_madrid_collections(array $club): array
{
    $collections = [
        ['label' => '1_hombre', 'url' => 'https://shop.atleticodemadrid.com/es/equipaciones/primera-equipacion/hombre', 'kit_type' => '1', 'audience' => 'hombre'],
        ['label' => '1_mujer',  'url' => 'https://shop.atleticodemadrid.com/es/equipaciones/primera-equipacion/mujer',  'kit_type' => '1', 'audience' => 'mujer'],
        ['label' => '1_nino',   'url' => 'https://shop.atleticodemadrid.com/es/equipaciones/primera-equipacion/nino',   'kit_type' => '1', 'audience' => 'nino'],

        ['label' => '2_hombre', 'url' => 'https://shop.atleticodemadrid.com/es/equipaciones/segunda-equipacion/hombre', 'kit_type' => '2', 'audience' => 'hombre'],
        ['label' => '2_mujer',  'url' => 'https://shop.atleticodemadrid.com/es/equipaciones/segunda-equipacion/mujer',  'kit_type' => '2', 'audience' => 'mujer'],
        ['label' => '2_nino',   'url' => 'https://shop.atleticodemadrid.com/es/equipaciones/segunda-equipacion/nino',   'kit_type' => '2', 'audience' => 'nino'],

        ['label' => '3_hombre', 'url' => 'https://shop.atleticodemadrid.com/es/equipaciones/tercera-equipacion/hombre', 'kit_type' => '3', 'audience' => 'hombre'],
        ['label' => '3_mujer',  'url' => 'https://shop.atleticodemadrid.com/es/equipaciones/tercera-equipacion/mujer',  'kit_type' => '3', 'audience' => 'mujer'],
        ['label' => '3_nino',   'url' => 'https://shop.atleticodemadrid.com/es/equipaciones/tercera-equipacion/nino',   'kit_type' => '3', 'audience' => 'nino'],
    ];

    $all = [];
    $seenUrls = [];

    foreach ($collections as $collection) {
        $html = fetch_html($collection['url']);
        [$dom, $xpath] = create_xpath_from_html($html);

        // En Atleti los productos van a fichas .html
        $productLinks = $xpath->query('//a[@href and contains(@href, ".html")]');
        if (!$productLinks) {
            continue;
        }

        $seenUrlsInThisCollection = [];

        foreach ($productLinks as $link) {
            if (!$link instanceof DOMElement) {
                continue;
            }

            $href = trim($link->getAttribute('href'));
            if ($href === '') {
                continue;
            }

            $productUrl = absolute_url($collection['url'], $href);
            $productUrl = strtok($productUrl, '?') ?: $productUrl;

            // solo fichas reales de producto
            if (!str_contains($productUrl, '.html')) {
                continue;
            }

            // evita repetir dentro de la misma colección
            if (isset($seenUrlsInThisCollection[$productUrl])) {
                continue;
            }
            $seenUrlsInThisCollection[$productUrl] = true;

            // y evita repetir entre colecciones
            if (isset($seenUrls[$productUrl])) {
                continue;
            }
            $seenUrls[$productUrl] = true;

            $contextNode = find_product_card_context($link);

            $title = extract_title_from_node($xpath, $contextNode);
            if ($title === '') {
                $title = trim($link->getAttribute('title'));
            }
            if ($title === '') {
                $title = trim($link->textContent);
            }
            if ($title === '') {
                $title = trim(preg_replace('/\s+/', ' ', $contextNode->textContent));
            }

            $title = normalize_title($title);

            if ($title === '') {
                continue;
            }

            if (!is_valid_target_product($title)) {
                continue;
            }

            $garmentType = detect_garment_type($title);
            if ($garmentType !== 'camiseta') {
                continue;
            }

            $productData = scrape_atletico_madrid_product_page(
                $productUrl,
                $collection['kit_type'],
                $collection['audience'],
                $title
            );

            if ($productData === null) {
                continue;
            }

            $all[] = $productData;
        }
    }

    return reduce_atletico_to_base_shirts(
        dedupe_scraped_products($all, (int)$club['id'])
    );
}

function scrape_atletico_madrid_product_page(
    string $productUrl,
    string $forcedKitType,
    string $forcedAudience,
    string $fallbackTitle = ''
): ?array {
    try {
        $html = fetch_html($productUrl);
        [$dom, $xpath] = create_xpath_from_html($html);

        $title = '';

        preg_match_all('/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches);

        foreach ($matches[1] as $jsonBlock) {
            $decoded = json_decode(html_entity_decode($jsonBlock), true);

            if (!$decoded) {
                continue;
            }

            $items = [];
            recursive_collect_json_products($decoded, $items);

            foreach ($items as $item) {
                $jsonTitle = normalize_title((string)($item['name'] ?? ''));
                $priceOriginal = null;
                $priceDiscount = null;
                $discountActive = 0;
                $imageUrl = '';

                if ($jsonTitle !== '') {
                    $title = $jsonTitle;
                }

                if (isset($item['image'])) {
                    if (is_array($item['image'])) {
                        $imageUrl = absolute_url($productUrl, (string)($item['image'][0] ?? ''));
                    } else {
                        $imageUrl = absolute_url($productUrl, (string)$item['image']);
                    }
                }

                if (isset($item['offers']['price'])) {
                    $priceOriginal = clean_price_to_float((string)$item['offers']['price']);
                } elseif (isset($item['price'])) {
                    $priceOriginal = clean_price_to_float((string)$item['price']);
                } elseif (isset($item['regular_price'])) {
                    $priceOriginal = clean_price_to_float((string)$item['regular_price']);
                }

                if (isset($item['sale_price'])) {
                    $sale = clean_price_to_float((string)$item['sale_price']);
                    if ($sale !== null && $priceOriginal !== null && $sale < $priceOriginal) {
                        $priceDiscount = $sale;
                        $discountActive = 1;
                    }
                }

                if ($title === '') {
                    $title = $fallbackTitle;
                }

                if ($title === '' || !is_valid_target_product($title)) {
                    return null;
                }

                if ($priceOriginal === null || !is_reasonable_price((float)$priceOriginal)) {
                    break;
                }

                $garmentType = detect_garment_type($title);
                if ($garmentType !== 'camiseta') {
                    return null;
                }

                $versionType = detect_version_type($title);
                if ($versionType === 'desconocida') {
                    $versionType = 'fan';
                }

                if ($imageUrl === '') {
                    $imageUrl = extract_image_from_product_page($productUrl);
                }

                return [
                    'scraped_title' => $title,
                    'normalized_title' => normalize_title($title),
                    'kit_type' => $forcedKitType,
                    'audience' => $forcedAudience,
                    'garment_type' => $garmentType,
                    'version_type' => $versionType,
                    'product_url' => $productUrl,
                    'image_url' => $imageUrl,
                    'price_original' => (float)$priceOriginal,
                    'price_discount' => $priceDiscount !== null ? (float)$priceDiscount : null,
                    'discount_active' => $discountActive,
                    'source_card_html' => ''
                ];
            }
        }

        if ($title === '') {
            $title = $fallbackTitle;
        }

        if ($title === '') {
            $h1 = $xpath->query('//h1');
            if ($h1 && $h1->length > 0) {
                $title = normalize_title(trim($h1->item(0)->textContent));
            }
        }

        if ($title === '' || !is_valid_target_product($title)) {
            return null;
        }

        $garmentType = detect_garment_type($title);
        if ($garmentType !== 'camiseta') {
            return null;
        }

        $priceOriginal = null;
        $priceDiscount = null;
        $discountActive = 0;

        $priceQueries = [
            '//*[contains(@class,"price")]',
            '//*[contains(@class,"money")]',
            '//*[contains(text(),"€")]',
            '//*[contains(text(),"$")]',
            '//*[contains(text(),"Precio")]'
        ];

        $priceTexts = [];

        foreach ($priceQueries as $query) {
            $nodes = $xpath->query($query);
            if (!$nodes) {
                continue;
            }

            foreach ($nodes as $node) {
                $txt = trim($node->textContent);
                if ($txt !== '' && preg_match('/\d+[.,]\d{2}/', $txt)) {
                    $priceTexts[] = $txt;
                }
            }
        }

        $allPrices = [];

        foreach ($priceTexts as $txt) {
            preg_match_all('/\d{1,4}(?:[.,]\d{2})/', $txt, $m);
            foreach ($m[0] as $candidate) {
                $p = clean_price_to_float($candidate);
                if ($p !== null && is_reasonable_price($p)) {
                    $allPrices[] = $p;
                }
            }
        }

        $allPrices = array_values(array_unique($allPrices));
        sort($allPrices);

        if (count($allPrices) === 1) {
            $priceOriginal = $allPrices[0];
        } elseif (count($allPrices) >= 2) {
            $priceDiscount = min($allPrices);
            $priceOriginal = max($allPrices);

            if ($priceDiscount < $priceOriginal) {
                $discountActive = 1;
            } else {
                $priceDiscount = null;
                $discountActive = 0;
            }
        }

        if ($priceOriginal === null || !is_reasonable_price((float)$priceOriginal)) {
            return null;
        }

        $imageUrl = extract_image_from_product_page($productUrl);

        $versionType = detect_version_type($title);
        if ($versionType === 'desconocida') {
            $versionType = 'fan';
        }

        return [
            'scraped_title' => $title,
            'normalized_title' => normalize_title($title),
            'kit_type' => $forcedKitType,
            'audience' => $forcedAudience,
            'garment_type' => $garmentType,
            'version_type' => $versionType,
            'product_url' => $productUrl,
            'image_url' => $imageUrl,
            'price_original' => (float)$priceOriginal,
            'price_discount' => $priceDiscount !== null ? (float)$priceDiscount : null,
            'discount_active' => $discountActive,
            'source_card_html' => ''
        ];
    } catch (Throwable $e) {
        return null;
    }
}

function reduce_atletico_to_base_shirts(array $items): array
{
    $best = [];

    foreach ($items as $item) {
        $kitType = (string)($item['kit_type'] ?? '');
        $audience = (string)($item['audience'] ?? '');
        $garmentType = (string)($item['garment_type'] ?? '');
        $title = mb_strtolower((string)($item['normalized_title'] ?? $item['scraped_title'] ?? ''));
        $versionType = (string)($item['version_type'] ?? 'desconocida');

        if (!in_array($kitType, ['1', '2', '3'], true)) {
            continue;
        }

        if (!in_array($audience, ['hombre', 'mujer', 'nino'], true)) {
            continue;
        }

        if ($garmentType !== 'camiseta') {
            continue;
        }

        /*
        |--------------------------------------------------------------------------
        | Bucket de versión
        |--------------------------------------------------------------------------
        | Queremos conservar:
        | - 1 fan por kit+audience
        | - 1 player/match por kit+audience
        |
        | Todo lo desconocido lo tratamos como fan.
        |--------------------------------------------------------------------------
        */
        $versionBucket = 'fan';

        if (
            $versionType === 'player' ||
            mb_strpos($title, 'match version') !== false ||
            mb_strpos($title, 'match') !== false ||
            mb_strpos($title, 'player version') !== false ||
            mb_strpos($title, 'authentic') !== false ||
            mb_strpos($title, 'pro') !== false ||
            mb_strpos($title, 'elite') !== false
        ) {
            $versionBucket = 'player';
        }

        $score = 0;

        if (!empty($item['image_url'])) {
            $score += 3;
        }

        if (!empty($item['product_url'])) {
            $score += 2;
        }

        if (is_reasonable_price((float)$item['price_original'])) {
            $score += 3;
        }

        if (mb_strpos($title, 'camiseta') !== false) {
            $score += 4;
        }

        if (mb_strpos($title, 'fútbol') !== false || mb_strpos($title, 'futbol') !== false) {
            $score += 2;
        }

        /*
        |--------------------------------------------------------------------------
        | Afinamos el score según bucket
        |--------------------------------------------------------------------------
        */
        if ($versionBucket === 'player') {
            if (
                mb_strpos($title, 'match version') !== false ||
                mb_strpos($title, 'player version') !== false ||
                mb_strpos($title, 'authentic') !== false
            ) {
                $score += 10;
            }
        } else {
            if (
                mb_strpos($title, 'fan') !== false ||
                mb_strpos($title, 'stadium') !== false ||
                mb_strpos($title, 'replica') !== false
            ) {
                $score += 6;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Penalizaciones
        |--------------------------------------------------------------------------
        */
        $penalties = [
            'special edition',
            'limited edition',
            'retro',
            'portero',
            'goalkeeper',
            'manga larga',
            'mini kit',
            'minikit',
            'pre-match',
            'prematch',
            'entrenamiento',
            'training',
        ];

        foreach ($penalties as $penalty) {
            if (mb_strpos($title, $penalty) !== false) {
                $score -= 20;
            }
        }

        $item['_score'] = $score;

        /*
        |--------------------------------------------------------------------------
        | Clave final: kit + audience + bucket de versión
        |--------------------------------------------------------------------------
        */
        $key = $kitType . '|' . $audience . '|' . $versionBucket;

        /*
        |--------------------------------------------------------------------------
        | Normalizamos version_type para que quede bien guardado/mostrado
        |--------------------------------------------------------------------------
        */
        if ($versionBucket === 'player') {
            $item['version_type'] = 'player';
        } else {
            $item['version_type'] = 'fan';
        }

        if (!isset($best[$key]) || $item['_score'] > $best[$key]['_score']) {
            $best[$key] = $item;
        }
    }

    foreach ($best as &$row) {
        unset($row['_score']);
    }

    return array_values($best);
}

function scrape_athletic_club_curated_products(array $club): array
{
    $products = [
        // 1ª
        [
            'url' => 'https://shop.athletic-club.eus/es/products/camiseta-athletic-club-25-26-primera-equipacion-manga-corta',
            'forced_title' => 'Camiseta Athletic Club 1ª equipación hombre',
            'kit_type' => '1',
            'audience' => 'hombre',
            'garment_type' => 'camiseta',
            'version_type' => 'fan',
        ],
        [
            'url' => 'https://shop.athletic-club.eus/es/products/camiseta-mujer-athletic-club-primera-equipacion',
            'forced_title' => 'Camiseta Athletic Club 1ª equipación mujer',
            'kit_type' => '1',
            'audience' => 'mujer',
            'garment_type' => 'camiseta',
            'version_type' => 'fan',
        ],
        [
            'url' => 'https://shop.athletic-club.eus/es/products/camiseta-athletic-club-junior-25-26-primera-equipacion',
            'forced_title' => 'Camiseta Athletic Club 1ª equipación niño',
            'kit_type' => '1',
            'audience' => 'nino',
            'garment_type' => 'camiseta',
            'version_type' => 'fan',
        ],

        // 2ª
        [
            'url' => 'https://shop.athletic-club.eus/es/products/athletic-club-away-short-sleeve-shirt-eu',
            'forced_title' => 'Camiseta Athletic Club 2ª equipación hombre',
            'forced_price_original' => 90.00,
            'kit_type' => '2',
            'audience' => 'hombre',
            'garment_type' => 'camiseta',
            'version_type' => 'fan',
        ],
        [
            'url' => 'https://shop.athletic-club.eus/es/products/camiseta-segunda-equipacion-manga-corta-athletic-club-mujer',
            'forced_title' => 'Camiseta Athletic Club 2ª equipación mujer',
            'kit_type' => '2',
            'audience' => 'mujer',
            'garment_type' => 'camiseta',
            'version_type' => 'fan',
        ],
        [
            'url' => 'https://shop.athletic-club.eus/es/products/camiseta-segunda-equipacion-manga-corta-athletic-club-junior',
            'forced_title' => 'Camiseta Athletic Club 2ª equipación niño',
            'kit_type' => '2',
            'audience' => 'nino',
            'garment_type' => 'camiseta',
            'version_type' => 'fan',
        ],

        // 3ª
        [
            'url' => 'https://shop.athletic-club.eus/es/products/camiseta-tercera-equipacion-manga-corta-athletic-club-hombre',
            'forced_title' => 'Camiseta Athletic Club 3ª equipación hombre',
            'kit_type' => '3',
            'audience' => 'hombre',
            'garment_type' => 'camiseta',
            'version_type' => 'fan',
        ],
        [
            'url' => 'https://shop.athletic-club.eus/es/products/camiseta-manga-corta-tercera-equipacion-athletic-club-junior',
            'forced_title' => 'Camiseta Athletic Club 3ª equipación niño',
            'kit_type' => '3',
            'audience' => 'nino',
            'garment_type' => 'camiseta',
            'version_type' => 'fan',
        ],
    ];

    $all = [];

    foreach ($products as $product) {
        $item = scrape_athletic_club_product_page($product, $club);
        if ($item !== null) {
            $all[] = $item;
        }
    }

    return dedupe_scraped_products($all, (int)$club['id']);
}

function scrape_athletic_club_product_page(array $product, array $club): ?array
{
    $productUrl = trim((string)($product['url'] ?? ''));
    if ($productUrl === '') {
        return null;
    }

    try {
        $html = fetch_html($productUrl);
        [$dom, $xpath] = create_xpath_from_html($html);

        $title = '';
        $imageUrl = '';
        $priceOriginal = null;
        $priceDiscount = null;
        $discountActive = 0;

        $h1 = $xpath->query('//h1');
        if ($h1 && $h1->length > 0) {
            $title = normalize_title(trim($h1->item(0)->textContent));
        }

        if ($title === '' && !empty($product['forced_title'])) {
            $title = normalize_title((string)$product['forced_title']);
        }

        if ($title === '') {
            $metaTitle = $xpath->query('//meta[@property="og:title"]');
            if ($metaTitle && $metaTitle->length > 0 && $metaTitle->item(0) instanceof DOMElement) {
                $title = normalize_title(trim($metaTitle->item(0)->getAttribute('content')));
            }
        }

        if ($title === '') {
            $title = normalize_title((string)($product['forced_title'] ?? ''));
        }

        if ($title === '') {
            return null;
        }

        $metaImage = $xpath->query('//meta[@property="og:image"]');
        if ($metaImage && $metaImage->length > 0 && $metaImage->item(0) instanceof DOMElement) {
            $imageUrl = absolute_url($productUrl, trim($metaImage->item(0)->getAttribute('content')));
        }

        if ($imageUrl === '') {
            $imageUrl = extract_image_from_product_page($productUrl);
        }

        $mainPrices = extract_athletic_club_main_prices($html);
        $priceOriginal = $mainPrices['price_original'] !== null ? (float)$mainPrices['price_original'] : null;
        $priceDiscount = $mainPrices['price_discount'] !== null ? (float)$mainPrices['price_discount'] : null;
        $discountActive = (int)$mainPrices['discount_active'];

        if (!empty($product['forced_price_original'])) {
            $priceOriginal = (float)$product['forced_price_original'];
            $priceDiscount = null;
            $discountActive = 0;
        }

        if ($priceOriginal === null || !is_reasonable_price((float)$priceOriginal)) {
            return null;
        }

        if ($priceDiscount !== null && $priceDiscount >= $priceOriginal) {
            $priceDiscount = null;
            $discountActive = 0;
        }

        if ($discountActive === 1 && $priceDiscount !== null) {
            $difference = $priceOriginal - $priceDiscount;
            if ($difference < 1) {
                $priceDiscount = null;
                $discountActive = 0;
            }
        }

        return [
            'scraped_title' => $title,
            'normalized_title' => normalize_title($title),
            'kit_type' => (string)$product['kit_type'],
            'audience' => (string)$product['audience'],
            'garment_type' => 'camiseta',
            'version_type' => (string)($product['version_type'] ?? 'fan'),
            'product_url' => $productUrl,
            'image_url' => $imageUrl,
            'price_original' => (float)$priceOriginal,
            'price_discount' => $priceDiscount !== null ? (float)$priceDiscount : null,
            'discount_active' => $discountActive,
            'source_card_html' => ''
        ];
    } catch (Throwable $e) {
        return null;
    }
}

function extract_athletic_club_main_prices(string $html): array
{
    $priceOriginal = null;
    $priceDiscount = null;
    $discountActive = 0;

    /*
    |--------------------------------------------------------------------------
    | Nos quedamos solo con la cabecera principal del producto
    |--------------------------------------------------------------------------
    | En Athletic el bloque bueno está al principio, justo después del H1:
    |   # CAMISETA ...
    |   ### €90,00 €90,00 -0%
    |
    | Más abajo aparecen relacionados y otros números que no nos interesan.
    |--------------------------------------------------------------------------
    */
    $mainChunk = $html;

    $startMarkers = [
        '<h1',
        '# CAMISETA',
        'product__title',
        'product-title',
    ];

    $startPos = false;
    foreach ($startMarkers as $marker) {
        $pos = stripos($mainChunk, $marker);
        if ($pos !== false) {
            $startPos = $pos;
            break;
        }
    }

    if ($startPos !== false) {
        $mainChunk = substr($mainChunk, (int)$startPos);
    }

    $cutMarkers = [
        '##  EQUIPACIONES 25/26',
        '## EQUIPACIONES 25/26',
        'Descripción',
        'Entrega',
        'Devoluciones',
        'You May Also Like',
        'Vista rápida',
    ];

    foreach ($cutMarkers as $marker) {
        $pos = stripos($mainChunk, $marker);
        if ($pos !== false) {
            $mainChunk = substr($mainChunk, 0, (int)$pos);
            break;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Primero intentamos precios en formato euro explícito
    |--------------------------------------------------------------------------
    */
    preg_match_all('/€\s*\d{1,4}(?:[.,]\d{2})/u', $mainChunk, $matches);

    $prices = [];

    foreach ($matches[0] as $raw) {
        $price = clean_price_to_float($raw);
        if ($price !== null && is_reasonable_price($price)) {
            $prices[] = $price;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Fallback: si por lo que sea no hay símbolo €
    |--------------------------------------------------------------------------
    */
    if (count($prices) === 0) {
        preg_match_all('/\d{1,4}(?:[.,]\d{2})/u', $mainChunk, $matchesFallback);

        foreach ($matchesFallback[0] as $raw) {
            $price = clean_price_to_float($raw);
            if ($price !== null && is_reasonable_price($price)) {
                $prices[] = $price;
            }
        }
    }

    $prices = array_values(array_unique($prices));
    sort($prices);

    if (count($prices) === 1) {
        $priceOriginal = $prices[0];
    } elseif (count($prices) >= 2) {
        $lowest = min($prices);
        $highest = max($prices);

        /*
        |--------------------------------------------------------------------------
        | Solo contamos descuento si es real y razonable
        |--------------------------------------------------------------------------
        */
        if ($lowest < $highest) {
            $priceOriginal = $highest;
            $priceDiscount = $lowest;
            $discountActive = 1;
        } else {
            $priceOriginal = $highest;
        }
    }

    return [
        'price_original' => $priceOriginal,
        'price_discount' => $priceDiscount,
        'discount_active' => $discountActive
    ];
}

function scrape_mallorca_product_page(
    string $productUrl,
    string $forcedKitType,
    string $forcedAudience,
    string $fallbackTitle = ''
): ?array {
    try {
        $productData = mallorca_get_product_data_from_json($productUrl);
        if (!$productData) {
            return null;
        }

        $title = clean_display_title((string)($productData['title'] ?? ''));
        if ($title === '') {
            $title = clean_display_title($fallbackTitle);
        }

        if ($title === '' || !is_valid_target_product($title)) {
            return null;
        }

        if (detect_garment_type($title) !== 'camiseta') {
            return null;
        }

        $titleLower = mb_strtolower($title);

        if (
            mb_strpos($titleLower, 'edición limitada') !== false ||
            mb_strpos($titleLower, 'edicion limitada') !== false ||
            mb_strpos($titleLower, 'limited edition') !== false ||
            mb_strpos($titleLower, 'conjunto') !== false ||
            mb_strpos($titleLower, 'bebé') !== false ||
            mb_strpos($titleLower, 'bebe') !== false ||
            mb_strpos($titleLower, 'pantalón') !== false ||
            mb_strpos($titleLower, 'pantalon') !== false ||
            mb_strpos($titleLower, 'short') !== false
        ) {
            return null;
        }

        $imageUrl = '';
        if (!empty($productData['images']) && is_array($productData['images'])) {
            $imageUrl = (string)($productData['images'][0] ?? '');
        }

        if ($imageUrl === '' && !empty($productData['featured_image'])) {
            $imageUrl = (string)$productData['featured_image'];
        }

        $variants = $productData['variants'] ?? [];
        if (!is_array($variants) || count($variants) === 0) {
            return null;
        }

        $currentPrices = [];
        $comparePrices = [];

        foreach ($variants as $variant) {
            if (!is_array($variant)) {
                continue;
            }

            $variantTitle = mb_strtolower((string)($variant['title'] ?? ''));

            if (
                mb_strpos($variantTitle, 'jugador') !== false ||
                mb_strpos($variantTitle, 'personaliz') !== false
            ) {
                continue;
            }

            $priceCents = isset($variant['price']) ? (int)$variant['price'] : 0;
            if ($priceCents > 0) {
                $currentPrices[] = round($priceCents / 100, 2);
            }

            $compareCents = isset($variant['compare_at_price']) && $variant['compare_at_price'] !== null
                ? (int)$variant['compare_at_price']
                : 0;

            if ($compareCents > 0) {
                $comparePrices[] = round($compareCents / 100, 2);
            }
        }

        if (count($currentPrices) === 0) {
            return null;
        }

        $currentPrices = array_values(array_unique($currentPrices));
        sort($currentPrices);

        $comparePrices = array_values(array_unique($comparePrices));
        sort($comparePrices);

        $priceOriginal = min($currentPrices);
        $priceDiscount = null;
        $discountActive = 0;

        if (count($comparePrices) > 0) {
            $bestCompare = max($comparePrices);

            if ($bestCompare > $priceOriginal) {
                $priceDiscount = $priceOriginal;
                $priceOriginal = $bestCompare;
                $discountActive = 1;
            }
        }

        if (!is_reasonable_price((float)$priceOriginal)) {
            return null;
        }

        $versionType = detect_version_type($title);
        if ($versionType === 'desconocida') {
            $versionType = 'fan';
        }

        return [
            'scraped_title' => $title,
            'normalized_title' => clean_display_title($title),
            'kit_type' => $forcedKitType,
            'audience' => $forcedAudience,
            'garment_type' => 'camiseta',
            'version_type' => $versionType,
            'product_url' => $productUrl,
            'image_url' => $imageUrl,
            'price_original' => (float)$priceOriginal,
            'price_discount' => $priceDiscount !== null ? (float)$priceDiscount : null,
            'discount_active' => $discountActive,
            'source_card_html' => ''
        ];
    } catch (Throwable $e) {
        return null;
    }
}



function scrape_mallorca_collections(array $club): array
{
    $collections = [
        ['url' => 'https://tienda.rcdmallorca.es/collections/primera-equipacion', 'kit_type' => '1'],
        ['url' => 'https://tienda.rcdmallorca.es/collections/segunda-equipacion', 'kit_type' => '2'],
        ['url' => 'https://tienda.rcdmallorca.es/collections/tercera-equipacion', 'kit_type' => '3'],
    ];

    $all = [];
    $seenUrls = [];

    foreach ($collections as $collection) {
        $html = fetch_html($collection['url']);
        [$dom, $xpath] = create_xpath_from_html($html);

        $links = $xpath->query('//a[contains(@href,"/products/")]');

        if (!$links) {
            continue;
        }

        foreach ($links as $link) {
            if (!$link instanceof DOMElement) {
                continue;
            }

            $href = trim($link->getAttribute('href'));
            $text = normalize_title(trim(preg_replace('/\s+/', ' ', $link->textContent)));

            if ($href === '' || $text === '') {
                continue;
            }

            $productUrl = absolute_url($collection['url'], $href);
            $productUrl = strtok($productUrl, '?') ?: $productUrl;

            if (isset($seenUrls[$productUrl])) {
                continue;
            }

            $textLower = mb_strtolower($text);

            if (mb_strpos($textLower, 'camiseta') === false) {
                continue;
            }

            if (
                mb_strpos($textLower, 'edición limitada') !== false ||
                mb_strpos($textLower, 'edicion limitada') !== false ||
                mb_strpos($textLower, 'limited edition') !== false ||
                mb_strpos($textLower, 'conjunto') !== false ||
                mb_strpos($textLower, 'bebé') !== false ||
                mb_strpos($textLower, 'bebe') !== false ||
                mb_strpos($textLower, 'pantalón') !== false ||
                mb_strpos($textLower, 'pantalon') !== false ||
                mb_strpos($textLower, 'short') !== false
            ) {
                continue;
            }

            $audience = 'hombre';

            if (mb_strpos($textLower, 'mujer') !== false) {
                $audience = 'mujer';
            } elseif (
                mb_strpos($textLower, 'niño') !== false ||
                mb_strpos($textLower, 'nino') !== false ||
                mb_strpos($textLower, 'niña') !== false ||
                mb_strpos($textLower, 'nina') !== false
            ) {
                $audience = 'nino';
            }

            $seenUrls[$productUrl] = true;

            $item = scrape_mallorca_product_page(
                $productUrl,
                (string)$collection['kit_type'],
                $audience,
                $text
            );

            if ($item !== null) {
                $all[] = $item;
            }
        }
    }

    return dedupe_scraped_products($all, (int)$club['id']);
}



function mallorca_get_product_data_from_json(string $productUrl): ?array
{
    $handle = get_shopify_handle_from_product_url($productUrl);
    if ($handle === null) {
        return null;
    }

    $jsonUrl = 'https://tienda.rcdmallorca.es/products/' . $handle . '.js';
    $productData = fetch_json_url($jsonUrl);

    return is_array($productData) ? $productData : null;
}

function scrape_elche_product_page(
    string $productUrl,
    string $forcedKitType,
    string $forcedAudience,
    string $fallbackTitle = ''
): ?array {
    try {
        $html = fetch_html($productUrl);
        [$dom, $xpath] = create_xpath_from_html($html);

        $title = $fallbackTitle;

        $h1 = $xpath->query('//h1');
        if ($h1 && $h1->length > 0) {
            $h1Text = normalize_title(trim(preg_replace('/\s+/', ' ', $h1->item(0)->textContent)));
            if ($h1Text !== '') {
                $title = $h1Text;
            }
        }

        if ($title === '' || !is_valid_target_product($title)) {
            return null;
        }

        if (detect_garment_type($title) !== 'camiseta') {
            return null;
        }

        $titleLower = mb_strtolower($title);
        $versionType = mb_strpos($titleLower, 'vapor') !== false ? 'player' : 'fan';

        $priceOriginal = null;
$priceDiscount = null;
$discountActive = 0;

$priceTexts = [];

$priceNodes = $xpath->query('//*[contains(@class,"price") or contains(text(),"€")]');
if ($priceNodes && $priceNodes->length > 0) {
    foreach ($priceNodes as $node) {
        $txt = trim(preg_replace('/\s+/', ' ', $node->textContent));
        if ($txt !== '' && preg_match('/\d{1,4}(?:[.,]\d{2})/', $txt)) {
            $priceTexts[] = $txt;
        }
    }
}

$allPrices = [];

foreach ($priceTexts as $txt) {
    preg_match_all('/\d{1,4}(?:[.,]\d{2})/', $txt, $matches);

    foreach ($matches[0] as $candidate) {
        $p = clean_price_to_float($candidate);
        if ($p !== null && is_reasonable_price($p)) {
            $allPrices[] = $p;
        }
    }
}

$allPrices = array_values(array_unique($allPrices));
sort($allPrices);

$realShirtPrices = array_values(array_filter($allPrices, static function ($p) {
    return $p >= 50;
}));

if (count($realShirtPrices) >= 2) {
    sort($realShirtPrices);
    $topTwo = array_slice($realShirtPrices, -2);
    $priceOriginal = min($topTwo);
} elseif (count($realShirtPrices) === 1) {
    $priceOriginal = $realShirtPrices[0];
} elseif (count($allPrices) >= 2) {
    $topTwo = array_slice($allPrices, -2);
    $priceOriginal = min($topTwo);
} elseif (count($allPrices) === 1) {
    $priceOriginal = $allPrices[0];
}



        if ($priceOriginal === null || !is_reasonable_price((float)$priceOriginal)) {
            return null;
        }

        $imageUrl = extract_image_from_product_page($productUrl);

        return [
            'scraped_title' => $title,
            'normalized_title' => normalize_title($title),
            'kit_type' => $forcedKitType,
            'audience' => $forcedAudience,
            'garment_type' => 'camiseta',
            'version_type' => $versionType,
            'product_url' => $productUrl,
            'image_url' => $imageUrl,
            'price_original' => (float)$priceOriginal,
            'price_discount' => $priceDiscount,
            'discount_active' => $discountActive,
            'source_card_html' => ''
        ];
    } catch (Throwable $e) {
        return null;
    }
}

function find_elche_price_context(DOMNode $node): DOMNode
{
    $current = $node;

    for ($i = 0; $i < 12; $i++) {
        if (!$current->parentNode instanceof DOMNode) {
            break;
        }

        $current = $current->parentNode;

        $text = trim(preg_replace('/\s+/', ' ', $current->textContent ?? ''));

        if (
            $text !== '' &&
            str_contains($text, '€') &&
            (
                str_contains(mb_strtolower($text), 'camiseta') ||
                str_contains(mb_strtolower($text), 'short') ||
                str_contains(mb_strtolower($text), 'medias')
            )
        ) {
            return $current;
        }
    }

    return $node;
}


function scrape_elche_collections(array $club): array
{
    $collections = [
        ['url' => 'https://tienda.elchecf.es/cat/equipaciones/1o-equipacion/', 'kit_type' => '1'],
        ['url' => 'https://tienda.elchecf.es/cat/equipaciones/2o-equipacion/', 'kit_type' => '2'],
        ['url' => 'https://tienda.elchecf.es/cat/equipaciones/3o-equipacion/', 'kit_type' => '3'],
    ];

    $all = [];
    $seenUrls = [];

    foreach ($collections as $collection) {
        $html = fetch_html($collection['url']);
        [$dom, $xpath] = create_xpath_from_html($html);

        $links = $xpath->query('//a[contains(@href,"/shop/")]');

        if (!$links || $links->length === 0) {
            continue;
        }

        foreach ($links as $link) {
            if (!$link instanceof DOMElement) {
                continue;
            }

            $href = trim($link->getAttribute('href'));
            if ($href === '') {
                continue;
            }

            $productUrl = absolute_url($collection['url'], $href);
            $productUrl = strtok($productUrl, '?') ?: $productUrl;

            if (
                $productUrl === 'https://tienda.elchecf.es/shop/' ||
                isset($seenUrls[$productUrl])
            ) {
                continue;
            }

            $title = normalize_title(trim(preg_replace('/\s+/', ' ', $link->textContent)));

            if ($title === '' || $title === '+') {
                continue;
            }

            $titleLower = mb_strtolower($title);

            if (mb_strpos($titleLower, 'camiseta') === false) {
                continue;
            }

            if (
                mb_strpos($titleLower, 'short') !== false ||
                mb_strpos($titleLower, 'medias') !== false ||
                mb_strpos($titleLower, 'portero') !== false ||
                mb_strpos($titleLower, 'manga larga') !== false
            ) {
                continue;
            }

            $contextNode = find_elche_price_context($link);
            $contextText = trim(preg_replace('/\s+/', ' ', $contextNode->textContent));

            preg_match_all('/\d{1,4}(?:[.,]\d{2})/', $contextText, $matches);

            $prices = [];
            foreach ($matches[0] as $candidate) {
                $p = clean_price_to_float($candidate);
                if ($p !== null && is_reasonable_price($p)) {
                    $prices[] = $p;
                }
            }

            $prices = array_values(array_unique($prices));
            sort($prices);

            $priceOriginal = null;

            if (count($prices) >= 2) {
                $topTwo = array_slice($prices, -2);
                $priceOriginal = min($topTwo);
            } elseif (count($prices) === 1) {
                $priceOriginal = $prices[0];
            }

            if ($priceOriginal === null || !is_reasonable_price((float)$priceOriginal)) {
                continue;
            }

            $audience = detect_audience($title);
            if ($audience === 'desconocido') {
                if (
                    mb_strpos($titleLower, 'junior') !== false ||
                    mb_strpos($titleLower, 'niño') !== false ||
                    mb_strpos($titleLower, 'nino') !== false
                ) {
                    $audience = 'nino';
                } else {
                    $audience = 'hombre';
                }
            }

            $versionType = mb_strpos($titleLower, 'vapor') !== false ? 'player' : 'fan';

            $imageUrl = extract_image_from_product_page($productUrl);

            $seenUrls[$productUrl] = true;

            $all[] = [
                'scraped_title' => $title,
                'normalized_title' => normalize_title($title),
                'kit_type' => (string)$collection['kit_type'],
                'audience' => $audience,
                'garment_type' => 'camiseta',
                'version_type' => $versionType,
                'product_url' => $productUrl,
                'image_url' => $imageUrl,
                'price_original' => (float)$priceOriginal,
                'price_discount' => null,
                'discount_active' => 0,
                'source_card_html' => sanitize_card_html($dom->saveHTML($contextNode))
            ];
        }
    }

    return dedupe_scraped_products($all, (int)$club['id']);
}

function scrape_rayo_vallecano_collection(array $club): array
{
    $html = fetch_html('https://tiendarayovallecano.es/10-equipacion-oficial');
    [$dom, $xpath] = create_xpath_from_html($html);

    $links = $xpath->query('//h3/a[@href]');
    $all = [];
    $seenUrls = [];

    if (!$links || $links->length === 0) {
        return [];
    }

    foreach ($links as $link) {
        if (!$link instanceof DOMElement) {
            continue;
        }

        $href = trim($link->getAttribute('href'));
        $title = normalize_title(trim(preg_replace('/\s+/', ' ', $link->textContent)));

        if ($href === '' || $title === '') {
            continue;
        }

        $titleLower = mb_strtolower($title);

        if (mb_strpos($titleLower, 'camiseta') === false) {
            continue;
        }

        if (
            mb_strpos($titleLower, 'pant') !== false ||
            mb_strpos($titleLower, 'medias') !== false ||
            mb_strpos($titleLower, 'sr 21/22') !== false
        ) {
            continue;
        }

        $productUrl = absolute_url('https://tiendarayovallecano.es/10-equipacion-oficial', $href);
        $productUrl = strtok($productUrl, '?') ?: $productUrl;

        if (isset($seenUrls[$productUrl])) {
            continue;
        }

        $contextNode = find_product_card_context($link);
        $contextText = trim(preg_replace('/\s+/', ' ', $contextNode->textContent));

        $priceOriginal = null;
        if (preg_match('/(\d{1,4},\d{2})\s*€/u', $contextText, $m)) {
            $single = clean_price_to_float($m[1]);
            if ($single !== null) {
                $priceOriginal = $single;
            }
        }

        if ($priceOriginal === null || !is_reasonable_price((float)$priceOriginal)) {
            continue;
        }

        $kitType = null;
        if (mb_strpos($titleLower, '1ª') !== false || mb_strpos($titleLower, '1a') !== false) {
            $kitType = '1';
        } elseif (mb_strpos($titleLower, '2ª') !== false || mb_strpos($titleLower, '2a') !== false) {
            $kitType = '2';
        } elseif (mb_strpos($titleLower, '3ª') !== false || mb_strpos($titleLower, '3a') !== false) {
            $kitType = '3';
        }

        if ($kitType === null) {
            continue;
        }

        $imageUrl = extract_best_image_near_node($xpath, $contextNode, 'https://tiendarayovallecano.es/10-equipacion-oficial');
        if ($imageUrl === '') {
            $imageUrl = extract_image_from_product_page($productUrl);
        }

        $seenUrls[$productUrl] = true;

        $all[] = [
            'scraped_title' => $title,
            'normalized_title' => normalize_title($title),
            'kit_type' => $kitType,
            'audience' => 'hombre',
            'garment_type' => 'camiseta',
            'version_type' => 'fan',
            'product_url' => $productUrl,
            'image_url' => $imageUrl,
            'price_original' => (float)$priceOriginal,
            'price_discount' => null,
            'discount_active' => 0,
            'source_card_html' => sanitize_card_html($dom->saveHTML($contextNode))
        ];
    }

    return dedupe_scraped_products($all, (int)$club['id']);
}

function find_celta_product_context(DOMNode $node): DOMNode
{
    $current = $node;

    for ($i = 0; $i < 10; $i++) {
        if (!$current->parentNode instanceof DOMNode) {
            break;
        }

        $current = $current->parentNode;
        $text = trim(preg_replace('/\s+/', ' ', $current->textContent ?? ''));

        if (
            $text !== '' &&
            str_contains($text, '€') &&
            stripos($text, 'Comprar ahora') !== false &&
            stripos($text, 'Aplicar filtros') === false &&
            stripos($text, '10,00 € - 19,99 €') === false
        ) {
            return $current;
        }
    }

    return $node;
}

function scrape_celta_collections(array $club): array
{
    $collections = [
        ['url' => 'https://shop.rccelta.es/es/equipaciones-25-26/primera-equipacion.html', 'kit_type' => '1'],
        ['url' => 'https://shop.rccelta.es/es/equipaciones-25-26/segunda-equipacion.html', 'kit_type' => '2'],
        ['url' => 'https://shop.rccelta.es/es/equipaciones-25-26/tercera-equipacion.html', 'kit_type' => '3'],
    ];

    $all = [];
    $seenUrls = [];

    foreach ($collections as $collection) {
        $html = fetch_html($collection['url']);
        [$dom, $xpath] = create_xpath_from_html($html);

        $titleLinks = $xpath->query('//a[@href and normalize-space(text()) != ""]');

        if (!$titleLinks || $titleLinks->length === 0) {
            continue;
        }

        foreach ($titleLinks as $link) {
            if (!$link instanceof DOMElement) {
                continue;
            }

            $title = normalize_title(trim(preg_replace('/\s+/', ' ', $link->textContent)));
            if ($title === '') {
                continue;
            }

            $titleLower = mb_strtolower($title);

            if (mb_strpos($titleLower, 'camiseta') === false) {
                continue;
            }

            if (
                mb_strpos($titleLower, 'uefa') !== false ||
                mb_strpos($titleLower, 'manga larga') !== false ||
                mb_strpos($titleLower, 'minikit') !== false ||
                mb_strpos($titleLower, 'mini kit') !== false ||
                mb_strpos($titleLower, 'pantalón') !== false ||
                mb_strpos($titleLower, 'pantalon') !== false ||
                mb_strpos($titleLower, 'medias') !== false
            ) {
                continue;
            }

            $href = trim($link->getAttribute('href'));
            if ($href === '') {
                continue;
            }

            $productUrl = absolute_url($collection['url'], $href);
            $productUrl = strtok($productUrl, '?') ?: $productUrl;

            if ($productUrl === '' || isset($seenUrls[$productUrl])) {
                continue;
            }

            $contextNode = find_celta_product_context($link);
            $contextText = trim(preg_replace('/\s+/', ' ', $contextNode->textContent));

            preg_match_all('/\d{1,4}(?:[.,]\d{2})\s*€/u', $contextText, $matches);

            $prices = [];
            foreach ($matches[0] as $raw) {
                $price = clean_price_to_float($raw);
                if ($price !== null && is_reasonable_price($price)) {
                    $prices[] = $price;
                }
            }

            $prices = array_values(array_unique($prices));
            sort($prices);

            $priceOriginal = null;
            $priceDiscount = null;
            $discountActive = 0;

            if (count($prices) === 1) {
                $priceOriginal = $prices[0];
            } elseif (count($prices) >= 2) {
                $lowest = min($prices);
                $highest = max($prices);

                if ($lowest < $highest) {
                    $priceOriginal = $highest;
                    $priceDiscount = $lowest;
                    $discountActive = 1;
                } else {
                    $priceOriginal = $highest;
                }
            }

            if ($priceOriginal === null || !is_reasonable_price((float)$priceOriginal)) {
                continue;
            }

            $audience = 'hombre';
            if (
                mb_strpos($titleLower, 'mujer') !== false ||
                mb_strpos($titleLower, 'femenina') !== false ||
                mb_strpos($titleLower, 'femenino') !== false
            ) {
                $audience = 'mujer';
            } elseif (
                mb_strpos($titleLower, 'infantil') !== false ||
                mb_strpos($titleLower, 'niño') !== false ||
                mb_strpos($titleLower, 'nino') !== false
            ) {
                $audience = 'nino';
            }

            $imageUrl = extract_best_image_near_node($xpath, $contextNode, $collection['url']);
            if ($imageUrl === '') {
                $imageUrl = extract_image_from_product_page($productUrl);
            }

            $seenUrls[$productUrl] = true;

            $all[] = [
                'scraped_title' => $title,
                'normalized_title' => normalize_title($title),
                'kit_type' => (string)$collection['kit_type'],
                'audience' => $audience,
                'garment_type' => 'camiseta',
                'version_type' => 'fan',
                'product_url' => $productUrl,
                'image_url' => $imageUrl,
                'price_original' => (float)$priceOriginal,
                'price_discount' => $priceDiscount !== null ? (float)$priceDiscount : null,
                'discount_active' => $discountActive,
                'source_card_html' => sanitize_card_html($dom->saveHTML($contextNode))
            ];
        }
    }

    return dedupe_scraped_products($all, (int)$club['id']);
}

function find_girona_product_context(DOMNode $node): DOMNode
{
    $current = $node;

    for ($i = 0; $i < 10; $i++) {
        if (!$current->parentNode instanceof DOMNode) {
            break;
        }

        $current = $current->parentNode;
        $text = trim(preg_replace('/\s+/', ' ', $current->textContent ?? ''));

        if (
            $text !== '' &&
            str_contains($text, '€') &&
            (
                stripos($text, 'Añadir al carrito') !== false ||
                stripos($text, 'Seleccionar opciones') !== false ||
                stripos($text, 'Leer más') !== false
            )
        ) {
            return $current;
        }
    }

    return $node;
}

function scrape_girona_collections(array $club): array
{
    $collections = [
        ['url' => 'https://botiga.gironafc.cat/es/categoria-shop/equipaciones-es/primera-equipacion/', 'kit_type' => '1'],
        ['url' => 'https://botiga.gironafc.cat/es/categoria-shop/equipaciones-es/segunda-equipacion-es-2/', 'kit_type' => '2'],
        ['url' => 'https://botiga.gironafc.cat/es/categoria-shop/equipaciones-es/tercera-equipacion-es-2/', 'kit_type' => '3'],
    ];

    $all = [];
    $seenUrls = [];

    foreach ($collections as $collection) {
        $html = fetch_html($collection['url']);
        [$dom, $xpath] = create_xpath_from_html($html);

        $links = $xpath->query('//h2[contains(@class,"woocommerce-loop-product__title")]/a[@href] | //a[contains(@class,"woocommerce-LoopProduct-link")]//*[self::h2 or self::h3]/parent::a[@href]');

        if (!$links || $links->length === 0) {
            continue;
        }

        foreach ($links as $link) {
            if (!$link instanceof DOMElement) {
                continue;
            }

            $href = trim($link->getAttribute('href'));
            if ($href === '') {
                continue;
            }

            $productUrl = absolute_url($collection['url'], $href);
            $productUrl = strtok($productUrl, '?') ?: $productUrl;

            if (
                $productUrl === '' ||
                isset($seenUrls[$productUrl]) ||
                $productUrl === $collection['url']
            ) {
                continue;
            }

            $contextNode = find_girona_product_context($link);
            $contextText = trim(preg_replace('/\s+/', ' ', $contextNode->textContent));

            $title = extract_title_from_node($xpath, $contextNode);

            if ($title === '' && $link->hasAttribute('title')) {
                $title = normalize_title(trim($link->getAttribute('title')));
            }

            if ($title === '') {
                $title = normalize_title(trim(preg_replace('/\s+/', ' ', $link->textContent)));
            }

            if ($title === '' || mb_strlen($title) < 6) {
                if (preg_match('/(Camiseta.*?)(?=(?:Original price|Current price|Solo camiseta|Fan version|Descuento|Oferta|€))/iu', $contextText, $m)) {
                    $title = normalize_title(trim($m[1]));
                }
            }

            if ($title === '') {
                continue;
            }

            $title = clean_display_title($title);
            $title = preg_replace('/\b(?:Original price|Current price|was|is|Solo camiseta|Fan version|Descuento|Oferta)\b.*$/iu', '', $title);
            $title = preg_replace('/\s{2,}/u', ' ', $title);
            $title = trim($title, " \t\n\r\0\x0B-–/");

            if ($title === '') {
                continue;
            }

            $titleLower = mb_strtolower($title);

            if (mb_strpos($titleLower, 'camiseta') === false) {
                continue;
            }

            if (
                mb_strpos($titleLower, 'portero') !== false ||
                mb_strpos($titleLower, 'manga larga') !== false ||
                mb_strpos($titleLower, 'pantalón') !== false ||
                mb_strpos($titleLower, 'pantalon') !== false ||
                mb_strpos($titleLower, 'short') !== false ||
                mb_strpos($titleLower, 'medias') !== false ||
                mb_strpos($titleLower, 'calcet') !== false ||
                mb_strpos($titleLower, 'minikit') !== false ||
                mb_strpos($titleLower, 'retro') !== false ||
                mb_strpos($titleLower, 'mini kit') !== false
            ) {
                continue;
            }

            preg_match_all('/\d{1,4}(?:[.,]\d{2})\s*€/u', $contextText, $matches);

            $prices = [];
            foreach ($matches[0] as $raw) {
                $price = clean_price_to_float($raw);
                if ($price !== null && is_reasonable_price($price)) {
                    $prices[] = $price;
                }
            }

            $prices = array_values(array_unique($prices));
            sort($prices);

            $priceOriginal = null;
            $priceDiscount = null;
            $discountActive = 0;

            if (count($prices) === 1) {
                $priceOriginal = $prices[0];
            } elseif (count($prices) >= 2) {
                $lowest = min($prices);
                $highest = max($prices);

                if ($lowest < $highest) {
                    $priceOriginal = $highest;
                    $priceDiscount = $lowest;
                    $discountActive = 1;
                } else {
                    $priceOriginal = $highest;
                }
            }

            if ($priceOriginal === null || !is_reasonable_price((float)$priceOriginal)) {
                continue;
            }

            $audience = 'hombre';
            if (
                mb_strpos($titleLower, 'mujer') !== false ||
                mb_strpos($titleLower, 'woman') !== false ||
                mb_strpos($titleLower, 'female') !== false ||
                mb_strpos($titleLower, 'femenina') !== false ||
                mb_strpos($titleLower, 'femenino') !== false
            ) {
                $audience = 'mujer';
            } elseif (
                mb_strpos($titleLower, 'infantil') !== false ||
                mb_strpos($titleLower, 'junior') !== false ||
                mb_strpos($titleLower, 'niño') !== false ||
                mb_strpos($titleLower, 'nino') !== false
            ) {
                $audience = 'nino';
            }

            $imageUrl = extract_best_image_near_node($xpath, $contextNode, $collection['url']);
            if ($imageUrl === '') {
                $imageUrl = extract_image_from_product_page($productUrl);
            }

            $seenUrls[$productUrl] = true;

            $all[] = [
                'scraped_title' => $title,
                'normalized_title' => normalize_title($title),
                'kit_type' => (string)$collection['kit_type'],
                'audience' => $audience,
                'garment_type' => 'camiseta',
                'version_type' => 'fan',
                'product_url' => $productUrl,
                'image_url' => $imageUrl,
                'price_original' => (float)$priceOriginal,
                'price_discount' => $priceDiscount !== null ? (float)$priceDiscount : null,
                'discount_active' => $discountActive,
                'source_card_html' => sanitize_card_html($dom->saveHTML($contextNode))
            ];
        }
    }

    return dedupe_scraped_products($all, (int)$club['id']);
}


function find_sevilla_product_context(DOMNode $node): DOMNode
{
    $current = $node;

    for ($i = 0; $i < 6; $i++) {
        if (!$current->parentNode instanceof DOMNode) {
            break;
        }

        $current = $current->parentNode;

        if ($current instanceof DOMElement) {
            $classAttr = mb_strtolower(trim($current->getAttribute('class')));

            if (
                str_contains($classAttr, 'product') ||
                str_contains($classAttr, 'grid') ||
                str_contains($classAttr, 'card') ||
                str_contains($classAttr, 'item')
            ) {
                return $current;
            }
        }
    }

    return $node;
}

function scrape_sevilla_collections(array $club): array
{
    $collections = [
        ['url' => 'https://shop.sevillafc.es/collections/1-equipacion', 'kit_type' => '1'],
        ['url' => 'https://shop.sevillafc.es/collections/2-equipacion', 'kit_type' => '2'],
        ['url' => 'https://shop.sevillafc.es/collections/3-equipacion', 'kit_type' => '3'],
    ];

    $all = [];
    $seenUrls = [];

    foreach ($collections as $collection) {
        $html = fetch_html($collection['url']);
        [$dom, $xpath] = create_xpath_from_html($html);

        $links = $xpath->query('//a[contains(@href,"/products/")]');

        if (!$links || $links->length === 0) {
            continue;
        }

        foreach ($links as $link) {
            if (!$link instanceof DOMElement) {
                continue;
            }

            $href = trim($link->getAttribute('href'));
            if ($href === '') {
                continue;
            }

            $productUrl = absolute_url($collection['url'], $href);
            $productUrl = strtok($productUrl, '?') ?: $productUrl;

            if (
                $productUrl === '' ||
                isset($seenUrls[$productUrl]) ||
                $productUrl === $collection['url']
            ) {
                continue;
            }

            $contextNode = find_sevilla_product_context($link);
            $contextText = trim(preg_replace('/\s+/', ' ', $contextNode->textContent));

            $title = extract_title_from_node($xpath, $contextNode);

            if ($title === '') {
                if ($link->hasAttribute('title')) {
                    $title = normalize_title(trim($link->getAttribute('title')));
                } else {
                    $title = normalize_title(trim(preg_replace('/\s+/', ' ', $link->textContent)));
                }
            }

            if ($title === '') {
                continue;
            }

            $titleLower = mb_strtolower($title);
            $contextLower = mb_strtolower($contextText);

            if (mb_strpos($titleLower, 'camiseta') === false) {
                continue;
            }

            if (
                mb_strpos($titleLower, 'retro') !== false ||
                mb_strpos($titleLower, '136') !== false ||
                mb_strpos($titleLower, 'edición limitada') !== false ||
                mb_strpos($titleLower, 'edicion limitada') !== false ||
                mb_strpos($titleLower, 'limited edition') !== false ||
                mb_strpos($titleLower, 'rocio osorno') !== false ||
                mb_strpos($titleLower, 'prematch') !== false ||
                mb_strpos($titleLower, 'entrenamiento') !== false ||
                mb_strpos($titleLower, 'portero') !== false ||
                mb_strpos($titleLower, 'manga larga') !== false ||
                mb_strpos($titleLower, 'pantalón') !== false ||
                mb_strpos($titleLower, 'pantalon') !== false ||
                mb_strpos($titleLower, 'short') !== false ||
                mb_strpos($titleLower, 'shorts') !== false ||
                mb_strpos($titleLower, 'medias') !== false ||
                mb_strpos($titleLower, 'calcet') !== false ||
                mb_strpos($titleLower, 'mini kit') !== false ||
                mb_strpos($titleLower, 'minikit') !== false ||
                mb_strpos($titleLower, 'conjunto') !== false ||
                mb_strpos($titleLower, 'dorsal ciudad') !== false
            ) {
                continue;
            }

            preg_match_all('/(?:€\s*\d{1,4}(?:[.,]\d{2})|\d{1,4}(?:[.,]\d{2})\s*€)/u', $contextText, $matches);

            $prices = [];
            foreach ($matches[0] as $raw) {
                $price = clean_price_to_float($raw);
                if ($price !== null && is_reasonable_price($price)) {
                    $prices[] = $price;
                }
            }

            $prices = array_values(array_unique($prices));
            sort($prices);

            $priceOriginal = null;
            $priceDiscount = null;
            $discountActive = 0;

            if (count($prices) === 1) {
                $priceOriginal = $prices[0];
            } elseif (count($prices) >= 2) {
                $lowest = min($prices);
                $highest = max($prices);

                if ($lowest < $highest) {
                    $priceOriginal = $highest;
                    $priceDiscount = $lowest;
                    $discountActive = 1;
                } else {
                    $priceOriginal = $highest;
                }
            }

            if ($priceOriginal === null || !is_reasonable_price((float)$priceOriginal)) {
                continue;
            }

            $audience = 'hombre';
            if (
                mb_strpos($titleLower, 'mujer') !== false ||
                mb_strpos($titleLower, 'woman') !== false ||
                mb_strpos($titleLower, 'female') !== false ||
                mb_strpos($titleLower, 'femenina') !== false ||
                mb_strpos($titleLower, 'femenino') !== false
            ) {
                $audience = 'mujer';
            } elseif (
                mb_strpos($titleLower, 'infantil') !== false ||
                mb_strpos($titleLower, 'junior') !== false ||
                mb_strpos($titleLower, 'niño') !== false ||
                mb_strpos($titleLower, 'nino') !== false
            ) {
                $audience = 'nino';
            } elseif (
                mb_strpos($titleLower, 'adulto') !== false ||
                mb_strpos($contextLower, 'adulto') !== false
            ) {
                $audience = 'hombre';
            }

            $imageUrl = extract_image_from_product_page($productUrl);
            if ($imageUrl === '') {
                $imageUrl = extract_best_image_near_node($xpath, $contextNode, $collection['url']);
            }

            $seenUrls[$productUrl] = true;

            $all[] = [
                'scraped_title' => $title,
                'normalized_title' => normalize_title($title),
                'kit_type' => (string)$collection['kit_type'],
                'audience' => $audience,
                'garment_type' => 'camiseta',
                'version_type' => 'fan',
                'product_url' => $productUrl,
                'image_url' => $imageUrl,
                'price_original' => (float)$priceOriginal,
                'price_discount' => $priceDiscount !== null ? (float)$priceDiscount : null,
                'discount_active' => $discountActive,
                'source_card_html' => sanitize_card_html($dom->saveHTML($contextNode))
            ];
        }
    }

    return dedupe_scraped_products($all, (int)$club['id']);
}

function scrape_sevilla_product_page_curated(array $product): ?array
{
    $productUrl = trim((string)($product['url'] ?? ''));
    if ($productUrl === '') {
        return null;
    }

    try {
        $html = fetch_html($productUrl);
        [$dom, $xpath] = create_xpath_from_html($html);

        $title = '';
        $imageUrl = '';
        $priceOriginal = null;
        $priceDiscount = null;
        $discountActive = 0;

        $h1 = $xpath->query('//h1');
        if ($h1 && $h1->length > 0) {
            $title = normalize_title(trim(preg_replace('/\s+/', ' ', $h1->item(0)->textContent)));
        }

        if ($title === '' && !empty($product['forced_title'])) {
            $title = normalize_title((string)$product['forced_title']);
        }

        if ($title === '') {
            $metaTitle = $xpath->query('//meta[@property="og:title"]');
            if ($metaTitle && $metaTitle->length > 0 && $metaTitle->item(0) instanceof DOMElement) {
                $title = normalize_title(trim($metaTitle->item(0)->getAttribute('content')));
            }
        }

        if ($title === '' || !is_valid_target_product($title)) {
            return null;
        }

        if (detect_garment_type($title) !== 'camiseta') {
            return null;
        }

        $titleLower = mb_strtolower($title);

        if (
            mb_strpos($titleLower, 'retro') !== false ||
            mb_strpos($titleLower, '136') !== false ||
            mb_strpos($titleLower, 'edición limitada') !== false ||
            mb_strpos($titleLower, 'edicion limitada') !== false ||
            mb_strpos($titleLower, 'limited edition') !== false ||
            mb_strpos($titleLower, 'rocio osorno') !== false ||
            mb_strpos($titleLower, 'prematch') !== false ||
            mb_strpos($titleLower, 'entrenamiento') !== false ||
            mb_strpos($titleLower, 'portero') !== false ||
            mb_strpos($titleLower, 'manga larga') !== false ||
            mb_strpos($titleLower, 'pantalón') !== false ||
            mb_strpos($titleLower, 'pantalon') !== false ||
            mb_strpos($titleLower, 'short') !== false ||
            mb_strpos($titleLower, 'shorts') !== false ||
            mb_strpos($titleLower, 'medias') !== false ||
            mb_strpos($titleLower, 'calcet') !== false ||
            mb_strpos($titleLower, 'mini kit') !== false ||
            mb_strpos($titleLower, 'minikit') !== false ||
            mb_strpos($titleLower, 'conjunto') !== false ||
            mb_strpos($titleLower, 'dorsal ciudad') !== false
        ) {
            return null;
        }

        /*
        |----------------------------------------------------------------------
        | 1) Intentamos primero con JSON-LD del producto
        |----------------------------------------------------------------------
        */
        preg_match_all('/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches);

        foreach ($matches[1] as $jsonBlock) {
            $decoded = json_decode(html_entity_decode($jsonBlock), true);

            if (!$decoded) {
                continue;
            }

            $items = [];
            recursive_collect_json_products($decoded, $items);

            foreach ($items as $item) {
                $jsonTitle = normalize_title((string)($item['name'] ?? ''));

                if ($jsonTitle !== '' && mb_strtolower($jsonTitle) !== $titleLower) {
                    continue;
                }

                if (isset($item['offers']['price'])) {
                    $priceOriginal = clean_price_to_float((string)$item['offers']['price']);
                } elseif (isset($item['price'])) {
                    $priceOriginal = clean_price_to_float((string)$item['price']);
                } elseif (isset($item['regular_price'])) {
                    $priceOriginal = clean_price_to_float((string)$item['regular_price']);
                }

                if (isset($item['sale_price'])) {
                    $sale = clean_price_to_float((string)$item['sale_price']);
                    if ($sale !== null && $priceOriginal !== null && $sale < $priceOriginal) {
                        $priceDiscount = $sale;
                        $discountActive = 1;
                    }
                }

                if (isset($item['offers']['highPrice']) && isset($item['offers']['lowPrice'])) {
                    $high = clean_price_to_float((string)$item['offers']['highPrice']);
                    $low = clean_price_to_float((string)$item['offers']['lowPrice']);

                    if ($high !== null && $low !== null) {
                        if ($low < $high) {
                            $priceOriginal = $high;
                            $priceDiscount = $low;
                            $discountActive = 1;
                        } else {
                            $priceOriginal = $high;
                        }
                    }
                }

                if ($priceOriginal !== null && is_reasonable_price((float)$priceOriginal)) {
                    break 2;
                }
            }
        }

        /*
        |----------------------------------------------------------------------
        | 2) Fallback: cogemos solo el bloque principal alrededor del H1
        |----------------------------------------------------------------------
        */
        if ($priceOriginal === null || !is_reasonable_price((float)$priceOriginal)) {
            $mainChunk = $html;

            $startMarkers = [
                '<h1',
                $title,
                'Precio regular',
                'product__title',
                'product-title',
            ];

            $startPos = false;
            foreach ($startMarkers as $marker) {
                if ($marker === '') {
                    continue;
                }

                $pos = stripos($mainChunk, $marker);
                if ($pos !== false) {
                    $startPos = $pos;
                    break;
                }
            }

            if ($startPos !== false) {
                $mainChunk = substr($mainChunk, (int)$startPos);
            }

            $cutMarkers = [
                'Descripción',
                'Productos relacionados',
                'También te puede gustar',
                'You may also like',
                'Vista rápida',
                'Añadir al carrito',
            ];

            foreach ($cutMarkers as $marker) {
                $pos = stripos($mainChunk, $marker);
                if ($pos !== false) {
                    $mainChunk = substr($mainChunk, 0, (int)$pos);
                    break;
                }
            }

            preg_match_all('/(?:€\s*\d{1,4}(?:[.,]\d{2})|\d{1,4}(?:[.,]\d{2})\s*€)/u', $mainChunk, $priceMatches);

            $prices = [];
            foreach ($priceMatches[0] as $raw) {
                $price = clean_price_to_float($raw);
                if ($price !== null && is_reasonable_price($price)) {
                    $prices[] = $price;
                }
            }

            $prices = array_values(array_unique($prices));
            sort($prices);

            if (count($prices) === 1) {
                $priceOriginal = $prices[0];
                $priceDiscount = null;
                $discountActive = 0;
            } elseif (count($prices) >= 2) {
                $lowest = min($prices);
                $highest = max($prices);

                if ($lowest < $highest) {
                    $priceOriginal = $highest;
                    $priceDiscount = $lowest;
                    $discountActive = 1;
                } else {
                    $priceOriginal = $highest;
                    $priceDiscount = null;
                    $discountActive = 0;
                }
            }
        }

        if ($priceOriginal === null || !is_reasonable_price((float)$priceOriginal)) {
            return null;
        }

        $metaImage = $xpath->query('//meta[@property="og:image"]');
        if ($metaImage && $metaImage->length > 0 && $metaImage->item(0) instanceof DOMElement) {
            $imageUrl = absolute_url($productUrl, trim($metaImage->item(0)->getAttribute('content')));
        }

        if ($imageUrl === '') {
            $imageUrl = extract_image_from_product_page($productUrl);
        }

        return [
            'scraped_title' => $title,
            'normalized_title' => normalize_title($title),
            'kit_type' => (string)$product['kit_type'],
            'audience' => (string)$product['audience'],
            'garment_type' => 'camiseta',
            'version_type' => 'fan',
            'product_url' => $productUrl,
            'image_url' => $imageUrl,
            'price_original' => (float)$priceOriginal,
            'price_discount' => $priceDiscount !== null ? (float)$priceDiscount : null,
            'discount_active' => $discountActive,
            'source_card_html' => ''
        ];
    } catch (Throwable $e) {
        return null;
    }
}


function scrape_sevilla_curated_products(array $club): array
{
    $products = [
        [
            'url' => 'https://shop.sevillafc.es/products/camiseta-adulto-1-sevilla-fc-25-26-blanca',
            'forced_title' => 'Camiseta adulto 1ª Sevilla FC blanca',
            'kit_type' => '1',
            'audience' => 'hombre',
        ],
        [
            'url' => 'https://shop.sevillafc.es/products/camiseta-mujer-1-sevilla-fc-25-26-blanca',
            'forced_title' => 'Camiseta mujer 1ª Sevilla FC blanca',
            'kit_type' => '1',
            'audience' => 'mujer',
        ],
        [
            'url' => 'https://shop.sevillafc.es/products/camiseta-nino-1-sevilla-fc-25-26-blanca',
            'forced_title' => 'Camiseta niño 1ª Sevilla FC blanca',
            'kit_type' => '1',
            'audience' => 'nino',
        ],
        [
            'url' => 'https://shop.sevillafc.es/products/camiseta-adulto-2-sevilla-fc-25-26-roja',
            'forced_title' => 'Camiseta adulto 2ª Sevilla FC roja',
            'kit_type' => '2',
            'audience' => 'hombre',
        ],
        [
            'url' => 'https://shop.sevillafc.es/products/camiseta-mujer-2-sevilla-fc-25-26-roja',
            'forced_title' => 'Camiseta mujer 2ª Sevilla FC roja',
            'kit_type' => '2',
            'audience' => 'mujer',
        ],
        [
            'url' => 'https://shop.sevillafc.es/products/camiseta-nino-2-sevilla-fc-25-26-roja',
            'forced_title' => 'Camiseta niño 2ª Sevilla FC roja',
            'kit_type' => '2',
            'audience' => 'nino',
        ],
        [
            'url' => 'https://shop.sevillafc.es/products/camiseta-adulto-3%C2%AA-sevilla-fc-25-26-negra',
            'forced_title' => 'Camiseta adulto 3ª Sevilla FC negra',
            'kit_type' => '3',
            'audience' => 'hombre',
        ],
        [
            'url' => 'https://shop.sevillafc.es/products/camiseta-adulto-3%C2%AA-sevilla-fc-25-26-negra-1',
            'forced_title' => 'Camiseta mujer 3ª Sevilla FC negra',
            'kit_type' => '3',
            'audience' => 'mujer',
        ],
        [
            'url' => 'https://shop.sevillafc.es/products/camiseta-nino-3%C2%AA-sevilla-fc-25-26-negra',
            'forced_title' => 'Camiseta niño 3ª Sevilla FC negra',
            'kit_type' => '3',
            'audience' => 'nino',
        ],
    ];

    $all = [];

    foreach ($products as $product) {
        $item = scrape_sevilla_product_page_curated($product);
        if ($item !== null) {
            $all[] = $item;
        }
    }

    return dedupe_scraped_products($all, (int)$club['id']);
}

function find_alaves_product_context(DOMNode $node): DOMNode
{
    $current = $node;

    for ($i = 0; $i < 10; $i++) {
        if (!$current->parentNode instanceof DOMNode) {
            break;
        }

        $current = $current->parentNode;
        $text = trim(preg_replace('/\s+/', ' ', $current->textContent ?? ''));

        if (
            $text !== '' &&
            str_contains($text, '€') &&
            (
                stripos($text, 'Añadir al carrito') !== false ||
                stripos($text, 'Agotado') !== false
            )
        ) {
            return $current;
        }
    }

    return $node;
}

function scrape_alaves_collections(array $club): array
{
    $collections = [
        ['url' => 'https://www.baskoniaalavesstore.com/deportivo-alaves/equipaciones/primera-equipacion-25-26', 'kit_type' => '1'],
        ['url' => 'https://www.baskoniaalavesstore.com/deportivo-alaves/equipaciones/segunda-equipacion-25-26', 'kit_type' => '2'],
        ['url' => 'https://www.baskoniaalavesstore.com/deportivo-alaves/equipaciones/tercera-equipacion-25-26', 'kit_type' => '3'],
    ];

    $all = [];
    $seenUrls = [];

    foreach ($collections as $collection) {
        $html = fetch_html($collection['url']);
        [$dom, $xpath] = create_xpath_from_html($html);

        $links = $xpath->query('//a[@href]');

        if (!$links || $links->length === 0) {
            continue;
        }

        foreach ($links as $link) {
            if (!$link instanceof DOMElement) {
                continue;
            }

            $href = trim($link->getAttribute('href'));
            if ($href === '') {
                continue;
            }

            $productUrl = absolute_url($collection['url'], $href);
            $productUrl = strtok($productUrl, '?') ?: $productUrl;

            if (
                $productUrl === '' ||
                isset($seenUrls[$productUrl]) ||
                mb_strpos($productUrl, '.html') === false
            ) {
                continue;
            }

            $contextNode = find_alaves_product_context($link);
            $contextText = trim(preg_replace('/\s+/', ' ', $contextNode->textContent));

            $title = extract_title_from_node($xpath, $contextNode);

            if ($title === '' && $link->hasAttribute('title')) {
                $title = normalize_title(trim($link->getAttribute('title')));
            }

            if ($title === '') {
                $title = normalize_title(trim(preg_replace('/\s+/', ' ', $link->textContent)));
            }

            if ($title === '' && preg_match('/(Camiseta.*?)(?=\d{1,4}(?:[.,]\d{2})\s*€)/iu', $contextText, $m)) {
                $title = normalize_title(trim($m[1]));
            }

            if ($title === '') {
                continue;
            }

            $title = clean_display_title($title);
            $titleLower = mb_strtolower($title);

            if (mb_strpos($titleLower, 'camiseta') === false) {
                continue;
            }

            if (
                mb_strpos($titleLower, 'no front sponsors') !== false ||
                mb_strpos($titleLower, 'sin sponsor') !== false ||
                mb_strpos($titleLower, 'sin sponsors') !== false ||
                mb_strpos($titleLower, 'limpia') !== false ||
                mb_strpos($titleLower, 'short') !== false ||
                mb_strpos($titleLower, 'pantalón') !== false ||
                mb_strpos($titleLower, 'pantalon') !== false ||
                mb_strpos($titleLower, 'medias') !== false ||
                mb_strpos($titleLower, 'calcet') !== false ||
                mb_strpos($titleLower, 'prepartido') !== false ||
				mb_strpos($titleLower, 'réplica') !== false ||
                mb_strpos($titleLower, 'retro') !== false
            ) {
                continue;
            }

            preg_match_all('/\d{1,4}(?:[.,]\d{2})\s*€/u', $contextText, $matches);

            $prices = [];
            foreach ($matches[0] as $raw) {
                $price = clean_price_to_float($raw);
                if ($price !== null && is_reasonable_price($price)) {
                    $prices[] = $price;
                }
            }

            $prices = array_values(array_unique($prices));
            sort($prices);

            $priceOriginal = null;
            $priceDiscount = null;
            $discountActive = 0;

            if (count($prices) === 1) {
                $priceOriginal = $prices[0];
            } elseif (count($prices) >= 2) {
                $lowest = min($prices);
                $highest = max($prices);

                if ($lowest < $highest) {
                    $priceOriginal = $highest;
                    $priceDiscount = $lowest;
                    $discountActive = 1;
                } else {
                    $priceOriginal = $highest;
                }
            }

            if ($priceOriginal === null || !is_reasonable_price((float)$priceOriginal)) {
                continue;
            }

            $audience = 'hombre';
            if (
                mb_strpos($titleLower, 'junior') !== false ||
                mb_strpos($titleLower, 'niño') !== false ||
                mb_strpos($titleLower, 'nino') !== false
            ) {
                $audience = 'nino';
            }

            $imageUrl = extract_best_image_near_node($xpath, $contextNode, $collection['url']);
            if ($imageUrl === '') {
                $imageUrl = extract_image_from_product_page($productUrl);
            }

            $seenUrls[$productUrl] = true;

            $all[] = [
                'scraped_title' => $title,
                'normalized_title' => normalize_title($title),
                'kit_type' => (string)$collection['kit_type'],
                'audience' => $audience,
                'garment_type' => 'camiseta',
                'version_type' => 'fan',
                'product_url' => $productUrl,
                'image_url' => $imageUrl,
                'price_original' => (float)$priceOriginal,
                'price_discount' => $priceDiscount !== null ? (float)$priceDiscount : null,
                'discount_active' => $discountActive,
                'source_card_html' => sanitize_card_html($dom->saveHTML($contextNode))
            ];
        }
    }

    return dedupe_scraped_products($all, (int)$club['id']);
}

function find_levante_product_context(DOMNode $node): DOMNode
{
    $current = $node;

    for ($i = 0; $i < 10; $i++) {
        if (!$current->parentNode instanceof DOMNode) {
            break;
        }

        $current = $current->parentNode;
        $text = trim(preg_replace('/\s+/', ' ', $current->textContent ?? ''));

        if (
            $text !== '' &&
            str_contains($text, '€') &&
            (
                stripos($text, 'Compra rápida') !== false ||
                stripos($text, 'Precio habitual') !== false ||
                stripos($text, 'Precio de oferta') !== false
            )
        ) {
            return $current;
        }
    }

    return $node;
}

function scrape_levante_collections(array $club): array
{
    $collections = [
        ['url' => 'https://tienda.levanteud.com/es/collections/primera-equipacion-levante-ud', 'kit_type' => '1'],
        ['url' => 'https://tienda.levanteud.com/es/collections/segunda-equipacion-levante-ud', 'kit_type' => '2'],
        ['url' => 'https://tienda.levanteud.com/es/collections/tercera-equipacion-levante-ud', 'kit_type' => '3'],
    ];

    $all = [];
    $seenUrls = [];

    foreach ($collections as $collection) {
        $html = fetch_html($collection['url']);
        [$dom, $xpath] = create_xpath_from_html($html);

        $links = $xpath->query('//a[@href]');

        if (!$links || $links->length === 0) {
            continue;
        }

        foreach ($links as $link) {
            if (!$link instanceof DOMElement) {
                continue;
            }

            $href = trim($link->getAttribute('href'));
            if ($href === '') {
                continue;
            }

            $productUrl = absolute_url($collection['url'], $href);
            $productUrl = strtok($productUrl, '?') ?: $productUrl;

            if (
                $productUrl === '' ||
                isset($seenUrls[$productUrl]) ||
                mb_strpos($productUrl, '/products/') === false
            ) {
                continue;
            }

            $contextNode = find_levante_product_context($link);
            $contextText = trim(preg_replace('/\s+/', ' ', $contextNode->textContent));

            $title = extract_title_from_node($xpath, $contextNode);

            if ($title === '' && $link->hasAttribute('title')) {
                $title = normalize_title(trim($link->getAttribute('title')));
            }

            if ($title === '') {
                $title = normalize_title(trim(preg_replace('/\s+/', ' ', $link->textContent)));
            }

            if ($title === '' && preg_match('/(Camiseta.*?)(?=Precio habitual|Precio de oferta|Descuento|€)/iu', $contextText, $m)) {
                $title = normalize_title(trim($m[1]));
            }

            if ($title === '') {
                continue;
            }

            $title = clean_display_title($title);
            $title = preg_replace('/\b(?:Precio habitual|Precio de oferta|Descuento|Oferta Flash|Compra rápida)\b.*$/iu', '', $title);
            $title = preg_replace('/\s{2,}/u', ' ', $title);
            $title = trim($title, " \t\n\r\0\x0B-–/");

            if ($title === '') {
                continue;
            }

            $titleLower = mb_strtolower($title);

            if (mb_strpos($titleLower, 'camiseta') === false) {
                continue;
            }

            if (
                mb_strpos($titleLower, 'prematch') !== false ||
                mb_strpos($titleLower, 'pre match') !== false ||
                mb_strpos($titleLower, 'prepartido') !== false ||
                mb_strpos($titleLower, 'pre-partido') !== false ||
                mb_strpos($titleLower, 'portero') !== false ||
                mb_strpos($titleLower, 'manga larga') !== false ||
                mb_strpos($titleLower, 'short') !== false ||
                mb_strpos($titleLower, 'pantalón') !== false ||
                mb_strpos($titleLower, 'pantalon') !== false ||
                mb_strpos($titleLower, 'medias') !== false ||
                mb_strpos($titleLower, 'calcet') !== false ||
                mb_strpos($titleLower, 'retro') !== false
            ) {
                continue;
            }

            preg_match_all('/\d{1,4}(?:[.,]\d{2})\s*€/u', $contextText, $matches);

            $prices = [];
            foreach ($matches[0] as $raw) {
                $price = clean_price_to_float($raw);
                if ($price !== null && is_reasonable_price($price)) {
                    $prices[] = $price;
                }
            }

            $prices = array_values(array_unique($prices));
            sort($prices);

            $priceOriginal = null;
            $priceDiscount = null;
            $discountActive = 0;

            if (count($prices) === 1) {
                $priceOriginal = $prices[0];
            } elseif (count($prices) >= 2) {
                $lowest = min($prices);
                $highest = max($prices);

                if ($lowest < $highest) {
                    $priceOriginal = $highest;
                    $priceDiscount = $lowest;
                    $discountActive = 1;
                } else {
                    $priceOriginal = $highest;
                }
            }

            if ($priceOriginal === null || !is_reasonable_price((float)$priceOriginal)) {
                continue;
            }

            $audience = 'hombre';
            if (
                mb_strpos($titleLower, 'femenino') !== false ||
                mb_strpos($titleLower, 'femenina') !== false ||
                mb_strpos($titleLower, 'mujer') !== false
            ) {
                $audience = 'mujer';
            } elseif (
                mb_strpos($titleLower, 'junior') !== false ||
                mb_strpos($titleLower, 'júnior') !== false ||
                mb_strpos($titleLower, 'infantil') !== false
            ) {
                $audience = 'nino';
            }

            $imageUrl = extract_best_image_near_node($xpath, $contextNode, $collection['url']);
            if ($imageUrl === '') {
                $imageUrl = extract_image_from_product_page($productUrl);
            }

            $seenUrls[$productUrl] = true;

            $all[] = [
                'scraped_title' => $title,
                'normalized_title' => normalize_title($title),
                'kit_type' => (string)$collection['kit_type'],
                'audience' => $audience,
                'garment_type' => 'camiseta',
                'version_type' => 'fan',
                'product_url' => $productUrl,
                'image_url' => $imageUrl,
                'price_original' => (float)$priceOriginal,
                'price_discount' => $priceDiscount !== null ? (float)$priceDiscount : null,
                'discount_active' => $discountActive,
                'source_card_html' => sanitize_card_html($dom->saveHTML($contextNode))
            ];
        }
    }

    return dedupe_scraped_products($all, (int)$club['id']);
}

function scrape_osasuna_python(array $club): array
{
    $script = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'osasuna_scraper.py';

    $pythonCandidates = PHP_OS_FAMILY === 'Windows'
        ? ['python', 'py -3']
        : ['python3', 'python'];

    $lastOutput = '';

    foreach ($pythonCandidates as $pythonBin) {
        $command = $pythonBin . ' ' . escapeshellarg($script) . ' 2>&1';
        $output = shell_exec($command);

        if (!is_string($output) || trim($output) === '') {
            continue;
        }

        $lastOutput = trim($output);
        $decoded = json_decode($lastOutput, true);

        if (!is_array($decoded)) {
            continue;
        }

        if (empty($decoded['ok'])) {
            $message = (string)($decoded['error'] ?? 'Error desconocido en scraper Python de Osasuna.');
            $details = (string)($decoded['details'] ?? '');
            throw new RuntimeException($message . ($details !== '' ? ' | ' . $details : ''));
        }

        $items = [];

        foreach (($decoded['items'] ?? []) as $item) {
            $title = normalize_title((string)($item['scraped_title'] ?? ''));

            if ($title === '' || !is_valid_target_product($title)) {
                continue;
            }

            $priceOriginal = isset($item['price_original']) ? (float)$item['price_original'] : null;
            if ($priceOriginal === null || !is_reasonable_price($priceOriginal)) {
                continue;
            }

            $items[] = [
                'scraped_title' => $title,
                'normalized_title' => normalize_title($title),
                'kit_type' => (string)($item['kit_type'] ?? ''),
                'audience' => (string)($item['audience'] ?? 'desconocido'),
                'garment_type' => 'camiseta',
                'version_type' => 'fan',
                'product_url' => (string)($item['product_url'] ?? ''),
                'image_url' => (string)($item['image_url'] ?? ''),
                'price_original' => $priceOriginal,
                'price_discount' => isset($item['price_discount']) && $item['price_discount'] !== null ? (float)$item['price_discount'] : null,
                'discount_active' => (int)($item['discount_active'] ?? 0),
                'source_card_html' => '',
            ];
        }

        return dedupe_scraped_products($items, (int)$club['id']);
    }

    throw new RuntimeException(
        'No se pudo ejecutar el scraper Python de Osasuna.' .
        ($lastOutput !== '' ? ' | ' . $lastOutput : '')
    );
}

function scrape_osasuna_product_page_curated(array $product): ?array
{
    $productUrl = trim((string)($product['url'] ?? ''));
    if ($productUrl === '') {
        return null;
    }

    try {
        $html = fetch_html($productUrl);
        [$dom, $xpath] = create_xpath_from_html($html);

        $title = '';
        $imageUrl = '';
        $priceOriginal = null;
        $priceDiscount = null;
        $discountActive = 0;

        $h1 = $xpath->query('//h1');
        if ($h1 && $h1->length > 0) {
            $title = normalize_title(trim(preg_replace('/\s+/', ' ', $h1->item(0)->textContent)));
        }

        if ($title === '' && !empty($product['forced_title'])) {
            $title = normalize_title((string)$product['forced_title']);
        }

        if ($title === '') {
            $metaTitle = $xpath->query('//meta[@property="og:title"]');
            if ($metaTitle && $metaTitle->length > 0 && $metaTitle->item(0) instanceof DOMElement) {
                $title = normalize_title(trim($metaTitle->item(0)->getAttribute('content')));
            }
        }

        if ($title === '' || !is_valid_target_product($title)) {
            return null;
        }

        $titleLower = mb_strtolower($title);

        if (
            mb_strpos($titleLower, 'niña') !== false ||
            mb_strpos($titleLower, 'nina') !== false ||
            mb_strpos($titleLower, 'girl') !== false ||
            mb_strpos($titleLower, 'girls') !== false ||
            mb_strpos($titleLower, 'portero') !== false ||
            mb_strpos($titleLower, 'goalkeeper') !== false ||
            mb_strpos($titleLower, 'short') !== false ||
            mb_strpos($titleLower, 'pantalón') !== false ||
            mb_strpos($titleLower, 'pantalon') !== false ||
            mb_strpos($titleLower, 'calcet') !== false ||
            mb_strpos($titleLower, 'media') !== false ||
            mb_strpos($titleLower, 'medias') !== false ||
            mb_strpos($titleLower, 'retro') !== false ||
            mb_strpos($titleLower, 'sudadera') !== false ||
            mb_strpos($titleLower, 'chaqueta') !== false
        ) {
            return null;
        }

        preg_match_all('/(?:€\s*\d{1,4}(?:[.,]\d{2})|\d{1,4}(?:[.,]\d{2})\s*€)/u', $html, $matches);

        $prices = [];
        foreach ($matches[0] as $raw) {
            $price = clean_price_to_float($raw);
            if ($price !== null && is_reasonable_price($price)) {
                $prices[] = $price;
            }
        }

        $prices = array_values(array_unique($prices));
        sort($prices);

        if (count($prices) === 1) {
            $priceOriginal = $prices[0];
        } elseif (count($prices) >= 2) {
            $lowest = min($prices);
            $highest = max($prices);

            if ($lowest < $highest) {
                $priceOriginal = $highest;
                $priceDiscount = $lowest;
                $discountActive = 1;
            } else {
                $priceOriginal = $highest;
            }
        }

        if ($priceOriginal === null || !is_reasonable_price((float)$priceOriginal)) {
            return null;
        }

        $metaImage = $xpath->query('//meta[@property="og:image"]');
        if ($metaImage && $metaImage->length > 0 && $metaImage->item(0) instanceof DOMElement) {
            $imageUrl = absolute_url($productUrl, trim($metaImage->item(0)->getAttribute('content')));
        }

        if ($imageUrl === '') {
            $imageUrl = extract_image_from_product_page($productUrl);
        }

        $versionType = detect_version_type($title);
        if ($versionType === 'desconocida') {
            $versionType = 'fan';
        }

        return [
            'scraped_title' => $title,
            'normalized_title' => normalize_title($title),
            'kit_type' => (string)$product['kit_type'],
            'audience' => (string)$product['audience'],
            'garment_type' => 'camiseta',
            'version_type' => $versionType,
            'product_url' => $productUrl,
            'image_url' => $imageUrl,
            'price_original' => (float)$priceOriginal,
            'price_discount' => $priceDiscount !== null ? (float)$priceDiscount : null,
            'discount_active' => $discountActive,
            'source_card_html' => ''
        ];
    } catch (Throwable $e) {
        return null;
    }
}




function scrape_club(array $club): array
{
    $clubName = mb_strtolower(trim($club['club_name']));

    if ($clubName === 'real madrid') {
        return scrape_real_madrid_collection($club);
    }

    if ($clubName === 'real oviedo') {
        return scrape_real_oviedo_collection($club);
    }
	
	if ($clubName === 'espanyol') {
        return scrape_espanyol_collections($club);
    }
	
    if ($clubName === 'villarreal') {
        return scrape_villarreal_collections($club);
    }

    if ($clubName === 'real betis') {
        return scrape_real_betis_collections($club);
    }

    if ($clubName === 'atletico de madrid') {
        return scrape_atletico_madrid_collections($club);
    }

    if ($clubName === 'athletic club') {
        return scrape_athletic_club_curated_products($club);
    }
	
	if ($clubName === 'mallorca') {
        return scrape_mallorca_collections($club);
    }
	
	if ($clubName === 'elche') {
    return scrape_elche_collections($club);
}
	
	if ($clubName === 'rayo vallecano') {
    return scrape_rayo_vallecano_collection($club);
}
	
	if ($clubName === 'celta') {
    return scrape_celta_collections($club);
}

    if ($clubName === 'girona') {
    return scrape_girona_collections($club);
}
	
	if ($clubName === 'sevilla') {
    return scrape_sevilla_curated_products($club);
}
	
	if ($clubName === 'alavés' || $clubName === 'alaves') {
    return scrape_alaves_collections($club);
}
	
	if ($clubName === 'levante') {
    return scrape_levante_collections($club);
}

    $html = fetch_html($club['store_url']);

    $products = array_merge(
        scrape_from_dom_cards($club, $html),
        scrape_from_json_ld($club, $html),
        scrape_from_embedded_json($club, $html)
    );

    return dedupe_scraped_products($products, (int)$club['id']);
}

function scrape_real_betis_product_page(
    string $productUrl,
    string $forcedKitType,
    string $forcedAudience,
    string $fallbackTitle = ''
): ?array {
    try {
        $html = fetch_html($productUrl);
        [$dom, $xpath] = create_xpath_from_html($html);

        $title = '';

        preg_match_all('/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches);

        foreach ($matches[1] as $jsonBlock) {
            $decoded = json_decode(html_entity_decode($jsonBlock), true);

            if (!$decoded) {
                continue;
            }

            $items = [];
            recursive_collect_json_products($decoded, $items);

            foreach ($items as $item) {
                $jsonTitle = normalize_title((string)($item['name'] ?? ''));
                $priceOriginal = null;
                $priceDiscount = null;
                $discountActive = 0;
                $imageUrl = '';

                if ($jsonTitle !== '') {
                    $title = $jsonTitle;
                }

                if (isset($item['image'])) {
                    if (is_array($item['image'])) {
                        $imageUrl = absolute_url($productUrl, (string)($item['image'][0] ?? ''));
                    } else {
                        $imageUrl = absolute_url($productUrl, (string)$item['image']);
                    }
                }

                if (isset($item['offers']['price'])) {
                    $priceOriginal = clean_price_to_float((string)$item['offers']['price']);
                } elseif (isset($item['price'])) {
                    $priceOriginal = clean_price_to_float((string)$item['price']);
                } elseif (isset($item['regular_price'])) {
                    $priceOriginal = clean_price_to_float((string)$item['regular_price']);
                }

                if (isset($item['sale_price'])) {
                    $sale = clean_price_to_float((string)$item['sale_price']);
                    if ($sale !== null && $priceOriginal !== null && $sale < $priceOriginal) {
                        $priceDiscount = $sale;
                        $discountActive = 1;
                    }
                }

                if ($title === '') {
                    $title = $fallbackTitle;
                }

                if ($title === '' || !is_valid_target_product($title)) {
                    return null;
                }

                if ($priceOriginal === null || !is_reasonable_price((float)$priceOriginal)) {
                    break;
                }

                $garmentType = detect_garment_type($title);
                if ($garmentType !== 'camiseta') {
                    return null;
                }

                $versionType = detect_version_type($title);
                if ($versionType === 'desconocida') {
                    $versionType = 'fan';
                }

                if ($imageUrl === '') {
                    $imageUrl = extract_image_from_product_page($productUrl);
                }

                return [
                    'scraped_title' => $title,
                    'normalized_title' => normalize_title($title),
                    'kit_type' => $forcedKitType,
                    'audience' => $forcedAudience,
                    'garment_type' => $garmentType,
                    'version_type' => $versionType,
                    'product_url' => $productUrl,
                    'image_url' => $imageUrl,
                    'price_original' => (float)$priceOriginal,
                    'price_discount' => $priceDiscount !== null ? (float)$priceDiscount : null,
                    'discount_active' => $discountActive,
                    'source_card_html' => ''
                ];
            }
        }

        if ($title === '') {
            $title = $fallbackTitle;
        }

        if ($title === '') {
            $h1 = $xpath->query('//h1');
            if ($h1 && $h1->length > 0) {
                $title = normalize_title(trim($h1->item(0)->textContent));
            }
        }

        if ($title === '' || !is_valid_target_product($title)) {
            return null;
        }

        $garmentType = detect_garment_type($title);
        if ($garmentType !== 'camiseta') {
            return null;
        }

        $priceOriginal = null;
        $priceDiscount = null;
        $discountActive = 0;

        $priceQueries = [
            '//*[contains(@class,"price")]',
            '//*[contains(@class,"money")]',
            '//*[contains(text(),"€")]'
        ];

        $priceTexts = [];

        foreach ($priceQueries as $query) {
            $nodes = $xpath->query($query);
            if (!$nodes) {
                continue;
            }

            foreach ($nodes as $node) {
                $txt = trim($node->textContent);
                if ($txt !== '' && preg_match('/\d+[.,]\d{2}/', $txt)) {
                    $priceTexts[] = $txt;
                }
            }
        }

        $allPrices = [];

        foreach ($priceTexts as $txt) {
            preg_match_all('/\d{1,4}(?:[.,]\d{2})/', $txt, $m);
            foreach ($m[0] as $candidate) {
                $p = clean_price_to_float($candidate);
                if ($p !== null && is_reasonable_price($p)) {
                    $allPrices[] = $p;
                }
            }
        }

        $allPrices = array_values(array_unique($allPrices));
        sort($allPrices);

        if (count($allPrices) === 1) {
            $priceOriginal = $allPrices[0];
        } elseif (count($allPrices) >= 2) {
            $priceDiscount = min($allPrices);
            $priceOriginal = max($allPrices);

            if ($priceDiscount < $priceOriginal) {
                $discountActive = 1;
            } else {
                $priceDiscount = null;
                $discountActive = 0;
            }
        }

        if ($priceOriginal === null || !is_reasonable_price((float)$priceOriginal)) {
            return null;
        }

        $imageUrl = extract_image_from_product_page($productUrl);

        $versionType = detect_version_type($title);
        if ($versionType === 'desconocida') {
            $versionType = 'fan';
        }

        return [
            'scraped_title' => $title,
            'normalized_title' => normalize_title($title),
            'kit_type' => $forcedKitType,
            'audience' => $forcedAudience,
            'garment_type' => $garmentType,
            'version_type' => $versionType,
            'product_url' => $productUrl,
            'image_url' => $imageUrl,
            'price_original' => (float)$priceOriginal,
            'price_discount' => $priceDiscount !== null ? (float)$priceDiscount : null,
            'discount_active' => $discountActive,
            'source_card_html' => ''
        ];
    } catch (Throwable $e) {
        return null;
    }
}

function reduce_betis_to_base_shirts(array $items): array
{
    $best = [];

    foreach ($items as $item) {
        $kitType = (string)($item['kit_type'] ?? '');
        $audience = (string)($item['audience'] ?? '');
        $garmentType = (string)($item['garment_type'] ?? '');
        $title = mb_strtolower((string)($item['normalized_title'] ?? $item['scraped_title'] ?? ''));

        if (!in_array($kitType, ['1', '2', '3'], true)) {
            continue;
        }

        if (!in_array($audience, ['hombre', 'mujer', 'nino'], true)) {
            continue;
        }

        if ($garmentType !== 'camiseta') {
            continue;
        }

        $score = 0;

        if (!empty($item['image_url'])) {
            $score += 3;
        }

        if (!empty($item['product_url'])) {
            $score += 2;
        }

        if (is_reasonable_price((float)$item['price_original'])) {
            $score += 3;
        }

        if (mb_strpos($title, 'camiseta') !== false) {
            $score += 4;
        }

        if (mb_strpos($title, 'fútbol') !== false || mb_strpos($title, 'futbol') !== false) {
            $score += 2;
        }

        $penalties = [
            'forever green',
            'uel',
            'uefa',
            'conference league',
            'final',
            'retro',
            'expo 92',
            'manga larga',
            'authentic',
            'player',
            'portero',
            'mini kit',
            'minikit',
        ];

        foreach ($penalties as $penalty) {
            if (mb_strpos($title, $penalty) !== false) {
                $score -= 20;
            }
        }

        $item['_score'] = $score;
        $key = $kitType . '|' . $audience;

        if (!isset($best[$key]) || $item['_score'] > $best[$key]['_score']) {
            $best[$key] = $item;
        }
    }

    foreach ($best as &$row) {
        unset($row['_score']);
    }

    return array_values($best);
}

function save_scraped_products(int $clubId, array $items): array
{
    $db = db();

    $created = 0;
    $updated = 0;

    $db->begin_transaction();

    try {
        $runStmt = $db->prepare("INSERT INTO scrape_runs (club_id, status, started_at) VALUES (?, 'running', NOW())");
        $runStmt->bind_param('i', $clubId);
        $runStmt->execute();
        $scrapeRunId = (int)$db->insert_id;
        $runStmt->close();

        $existsStmt = $db->prepare("SELECT id FROM products WHERE source_hash = ?");

        $upsertStmt = $db->prepare("
            INSERT INTO products
            (
                club_id,
                source_hash,
                scraped_title,
                normalized_title,
                kit_type,
                audience,
                garment_type,
                version_type,
                product_url,
                image_url,
                price_original,
                price_discount,
                discount_active,
                source_card_html,
                last_seen_at,
                is_active
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 1)
            ON DUPLICATE KEY UPDATE
                scraped_title = VALUES(scraped_title),
                normalized_title = VALUES(normalized_title),
                kit_type = VALUES(kit_type),
                audience = VALUES(audience),
                garment_type = VALUES(garment_type),
                version_type = VALUES(version_type),
                product_url = VALUES(product_url),
                image_url = VALUES(image_url),
                price_original = VALUES(price_original),
                price_discount = VALUES(price_discount),
                discount_active = VALUES(discount_active),
                source_card_html = VALUES(source_card_html),
                last_seen_at = NOW(),
                is_active = 1
        ");

        $historyStmt = $db->prepare("
            INSERT INTO price_history
            (product_id, scrape_run_id, price_original, price_discount, discount_active, captured_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");

        foreach ($items as $item) {
            $hash = $item['source_hash'];

            $existsStmt->bind_param('s', $hash);
            $existsStmt->execute();
            $existsRes = $existsStmt->get_result();
            $alreadyExists = $existsRes->fetch_assoc();

            $scrapedTitle = $item['scraped_title'];
            $normalizedTitle = $item['normalized_title'];
            $kitType = $item['kit_type'];
            $audience = $item['audience'] ?? 'desconocido';
            $garmentType = $item['garment_type'] ?? 'otra';
            $versionType = $item['version_type'] ?? 'desconocida';
            $productUrl = $item['product_url'];
            $imageUrl = $item['image_url'];
            $priceOriginal = (float)$item['price_original'];
            $priceDiscount = $item['price_discount'] !== null ? (float)$item['price_discount'] : null;
            $discountActive = (int)$item['discount_active'];
            $sourceCardHtml = $item['source_card_html'];

            $upsertStmt->bind_param(
                'isssssssssddis',
                $clubId,
                $hash,
                $scrapedTitle,
                $normalizedTitle,
                $kitType,
                $audience,
                $garmentType,
                $versionType,
                $productUrl,
                $imageUrl,
                $priceOriginal,
                $priceDiscount,
                $discountActive,
                $sourceCardHtml
            );
            $upsertStmt->execute();

            if ($alreadyExists) {
                $productId = (int)$alreadyExists['id'];
                $updated++;
            } else {
                $productId = (int)$db->insert_id;
                $created++;
            }

            $historyStmt->bind_param(
                'iiddi',
                $productId,
                $scrapeRunId,
                $priceOriginal,
                $priceDiscount,
                $discountActive
            );
            $historyStmt->execute();
        }

        $currentHashes = array_column($items, 'source_hash');

        if (count($currentHashes) > 0) {
            $placeholders = implode(',', array_fill(0, count($currentHashes), '?'));
            $types = str_repeat('s', count($currentHashes));

            $sql = "
                UPDATE products
                SET is_active = 0
                WHERE club_id = ?
                  AND source_hash NOT IN ($placeholders)
            ";

            $stmtDeactivate = $db->prepare($sql);

            $bindTypes = 'i' . $types;
            $bindValues = array_merge([$clubId], $currentHashes);

            $refs = [];
            foreach ($bindValues as $k => $v) {
                $refs[$k] = &$bindValues[$k];
            }

            array_unshift($refs, $bindTypes);

            call_user_func_array([$stmtDeactivate, 'bind_param'], $refs);
            $stmtDeactivate->execute();
            $stmtDeactivate->close();
        }

        $status = count($items) > 0 ? 'success' : 'partial';
        $notes = 'Productos procesados: ' . count($items);

        $finishStmt = $db->prepare("UPDATE scrape_runs SET status = ?, notes = ?, finished_at = NOW() WHERE id = ?");
        $finishStmt->bind_param('ssi', $status, $notes, $scrapeRunId);
        $finishStmt->execute();
        $finishStmt->close();

        $existsStmt->close();
        $upsertStmt->close();
        $historyStmt->close();

        $db->commit();

        return [
            'created' => $created,
            'updated' => $updated,
            'total' => count($items)
        ];
    } catch (Throwable $e) {
        $db->rollback();

        $errorStmt = $db->prepare("
            INSERT INTO scrape_runs (club_id, status, notes, started_at, finished_at)
            VALUES (?, 'error', ?, NOW(), NOW())
        ");
        $msg = mb_substr($e->getMessage(), 0, 65000);
        $errorStmt->bind_param('is', $clubId, $msg);
        $errorStmt->execute();
        $errorStmt->close();

        throw $e;
    }
}