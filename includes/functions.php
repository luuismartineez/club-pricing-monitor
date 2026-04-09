<?php
declare(strict_types=1);

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function get_param(string $key, string $default = ''): string
{
    return isset($_GET[$key]) ? trim((string)$_GET[$key]) : $default;
}

function post_param(string $key, string $default = ''): string
{
    return isset($_POST[$key]) ? trim((string)$_POST[$key]) : $default;
}

function flash_set(string $type, string $message): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message
    ];
}

function flash_get(): ?array
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!isset($_SESSION['flash'])) {
        return null;
    }

    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);

    return $flash;
}

function normalize_title(string $title): string
{
    $title = html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $title = trim($title);

    // quitar precios incrustados
    $title = preg_replace('/\b\d{1,4}(?:[.,]\d{2})\s*€\b/u', '', $title);

    // quitar temporadas
    $title = preg_replace('/\b20\d{2}[\/\-]20?\d{2}\b/u', '', $title);
    $title = preg_replace('/\b\d{2}[\/\-]\d{2}\b/u', '', $title);
	$title = preg_replace('/[\/\-]\d{2}\b/u', '', $title);
    $title = preg_replace('/\b20\d{2}\b/u', '', $title);
    $title = preg_replace('/\btemporada\b/iu', '', $title);

    // quitar textos de botones/acciones de ecommerce
    $noiseWords = [
        'añadir',
        'anadir',
        'agregada',
        'agregado',
        'agregar',
        'comprar',
        'buy',
        'add to cart',
        'add',
        'cart'
    ];

    foreach ($noiseWords as $word) {
        $title = preg_replace('/\b' . preg_quote($word, '/') . '\b/iu', '', $title);
    }

    // quitar "ninguno / ninguno", "none / none", etc.
    $title = preg_replace('/(?:none|ninguno)(?:\s*\/\s*(?:none|ninguno))+?/iu', '', $title);

    // quitar sufijos tipo talla + opciones: " - XS / Ninguno / Ninguno"
    $title = preg_replace(
        '/\s*[-–]\s*(?:XXS|XS|S|M|L|XL|XXL|2XL|3XL|4XL)(?:\s*\/\s*(?:none|ninguno|sin nombre|sin numero|sin número|[a-záéíóúñ]+))*$/iu',
        '',
        $title
    );

    // quitar secuencias repetidas del mismo nombre
    $title = preg_replace('/\b(Camiseta(?:\s+[A-Za-zÁÉÍÓÚáéíóúÑñ0-9ªº1-3\-]+){1,8})\s+\1\b/iu', '$1', $title);

    // limpiar separadores raros
    $title = preg_replace('/\s{2,}/u', ' ', $title);
    $title = preg_replace('/\s+\/\s+/u', ' / ', $title);

    return trim($title, " \t\n\r\0\x0B-–/");
}

function clean_price_to_float(?string $value): ?float
{
    if ($value === null) {
        return null;
    }

    $value = trim($value);

    if ($value === '') {
        return null;
    }

    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = str_replace(['€', 'EUR', '&euro;', ' '], '', $value);
    $value = preg_replace('/[^0-9,.\-]/', '', $value);

    if (substr_count($value, ',') > 0 && substr_count($value, '.') > 0) {
        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);
    } elseif (substr_count($value, ',') > 0) {
        $value = str_replace(',', '.', $value);
    }

    if ($value === '' || !is_numeric($value)) {
        return null;
    }

    return round((float)$value, 2);
}

function format_price(?float $price): string
{
    if ($price === null) {
        return '-';
    }

    return number_format($price, 2, ',', '.') . ' €';
}

function current_page(string $filename): bool
{
    return basename($_SERVER['PHP_SELF']) === $filename;
}

function build_url_with_filters(array $overrides = []): string
{
    $params = $_GET;

    foreach ($overrides as $key => $value) {
        if ($value === null) {
            unset($params[$key]);
        } else {
            $params[$key] = $value;
        }
    }

    $query = http_build_query($params);

    return basename($_SERVER['PHP_SELF']) . ($query ? '?' . $query : '');
}

function kit_label(string $kit): string
{
    return match($kit) {
        '1' => '1ª equipación',
        '2' => '2ª equipación',
        '3' => '3ª equipación',
        default => 'Sin clasificar',
    };
}


function detect_kit_type(string $title, array $club): ?string
{
    $titleLower = mb_strtolower($title);

    $kits = [
        '1' => $club['kit1_identifier'] ?? '',
        '2' => $club['kit2_identifier'] ?? '',
        '3' => $club['kit3_identifier'] ?? '',
    ];

    foreach ($kits as $kit => $identifierString) {
        $tokens = preg_split('/[,;|]+/', mb_strtolower($identifierString));

        foreach ($tokens as $token) {
            $token = trim($token);

            if ($token !== '' && mb_strpos($titleLower, $token) !== false) {
                return (string)$kit;
            }
        }
    }

    return null;
}

function detect_real_madrid_kit_type_fallback(string $title, string $url = ''): ?string
{
    $t = mb_strtolower($title . ' ' . $url);

    if (
        mb_strpos($t, 'home') !== false ||
        mb_strpos($t, 'primera') !== false
    ) {
        return '1';
    }

    if (
        mb_strpos($t, 'away') !== false ||
        mb_strpos($t, 'segunda') !== false
    ) {
        return '2';
    }

    if (
        mb_strpos($t, 'third') !== false ||
        mb_strpos($t, 'tercera') !== false
    ) {
        return '3';
    }

    return null;
}

function detect_audience(string $title): string
{
    $t = mb_strtolower($title);

    // Mujer
    $femaleSignals = [
        'mujer',
        'women',
        "women's",
        'womens',
        'lady',
        'ladies',
        'female',
        'femenina',
		'jugadora'
    ];

    foreach ($femaleSignals as $signal) {
        if (mb_strpos($t, $signal) !== false) {
            return 'mujer';
        }
    }

    // Niño
    $kidsSignals = [
        'niño',
        'nino',
        'niña',
        'nina',
        'kid',
        'kids',
        'youth',
        'junior',
        'júnior',
        'infant',
		'infantil',
        'baby',
        'bebé',
        'bebe',
        'toddler'
    ];

    foreach ($kidsSignals as $signal) {
        if (mb_strpos($t, $signal) !== false) {
            return 'nino';
        }
    }

    // Hombre
    $maleSignals = [
        'hombre',
        'men',
        "men's",
        'mens',
        'male',
        'adult',
		'jugador'
    ];

    foreach ($maleSignals as $signal) {
        if (mb_strpos($t, $signal) !== false) {
            return 'hombre';
        }
    }

    return 'desconocido';
}

function detect_audience_from_url(string $url): string
{
    $u = mb_strtolower($url);

    if (
        mb_strpos($u, 'womens') !== false ||
        mb_strpos($u, 'women') !== false ||
        mb_strpos($u, 'lady') !== false
    ) {
        return 'mujer';
    }

    if (
        mb_strpos($u, 'youth') !== false ||
        mb_strpos($u, 'kids') !== false ||
        mb_strpos($u, 'kid') !== false ||
        mb_strpos($u, 'junior') !== false ||
        mb_strpos($u, 'baby') !== false ||
        mb_strpos($u, 'infant') !== false
    ) {
        return 'nino';
    }

    if (
        mb_strpos($u, 'mens') !== false ||
        mb_strpos($u, 'men') !== false
    ) {
        return 'hombre';
    }

    return 'desconocido';
}

function detect_garment_type(string $title): string
{
    $t = mb_strtolower($title);

    if (
        mb_strpos($t, 'equipación completa') !== false ||
        mb_strpos($t, 'equipacion completa') !== false ||
        mb_strpos($t, 'kit completo') !== false ||
        mb_strpos($t, 'full kit') !== false ||
        mb_strpos($t, 'home kit') !== false ||
        mb_strpos($t, 'away kit') !== false ||
        mb_strpos($t, 'third kit') !== false ||
        mb_strpos($t, 'kit') !== false ||
        mb_strpos($t, 'conjunto') !== false
    ) {
        return 'equipacion_completa';
    }

    if (
        mb_strpos($t, 'camiseta') !== false ||
        mb_strpos($t, 'shirt') !== false ||
        mb_strpos($t, 'jersey') !== false
    ) {
        return 'camiseta';
    }

    return 'otra';
}

function detect_version_type(string $title): string
{
    $t = mb_strtolower($title);

    if (
        mb_strpos($t, 'player') !== false ||
        mb_strpos($t, 'match') !== false ||
        mb_strpos($t, 'authentic') !== false ||
        mb_strpos($t, 'pro') !== false ||
		 mb_strpos($t, 'vapor') !== false ||
		mb_strpos($t, 'competición') !== false ||
        mb_strpos($t, 'elite') !== false
    ) {
        return 'player';
    }

    if (
        mb_strpos($t, 'fan') !== false ||
        mb_strpos($t, 'stadium') !== false ||
        mb_strpos($t, 'replica') !== false
    ) {
        return 'fan';
    }

    return 'desconocida';
}

function is_valid_target_product(string $title): bool
{
    $t = mb_strtolower($title);

    $positiveSignals = [
        'camiseta',
        'shirt',
        'jersey',
        'equipación completa',
        'equipacion completa',
        'full kit',
        'kit completo',
        'conjunto'
    ];

    $negativeSignals = [
        'match day every day',
        'sempre endavant',
        'paseo',
        'moda',
        'fashion',
        'sudadera',
        'chaqueta',
        'hoodie',
        'gorra',
        'gorro',
        'mochila',
        'bolsa',
        'estuche',
        'bufanda',
        'bandera',
        'llavero',
        'balón',
        'balon',
        'papelería',
        'papeleria',
        'taza',
        'botella',
        'calcetines',
        'calcetín',
        'calcetin',
        'media',
        'medias',
        'short',
        'pantalón',
        'pantalon',
        'pre-partido',
        'entrenamiento',
        'prematch',
        'pre-match',
        'matchday',
        'match-day',
        'match day',
        'portero',
        'forever green',
        'uel',
        'uefa',
        'conference league',
        'final',
        'final uefa',
        'retro',
        'expo 92',
        'especial',
        'special edition',
        'limited edition',
        'fourth',
        'cuarta equipacion',
		'manga larga',
		'long sleeve',
        'cuarta equipación'
    ];

    foreach ($negativeSignals as $signal) {
        if (mb_strpos($t, $signal) !== false) {
            return false;
        }
    }

    foreach ($positiveSignals as $signal) {
        if (mb_strpos($t, $signal) !== false) {
            return true;
        }
    }

    return false;
}

function absolute_url(string $baseUrl, string $url): string
{
    $url = trim($url);

    if ($url === '') {
        return '';
    }

    if (preg_match('~^https?://~i', $url)) {
        return $url;
    }

    $base = parse_url($baseUrl);

    if (!$base || empty($base['scheme']) || empty($base['host'])) {
        return $url;
    }

    $scheme = $base['scheme'];
    $host = $base['host'];

    if (str_starts_with($url, '//')) {
        return $scheme . ':' . $url;
    }

    if (str_starts_with($url, '/')) {
        return $scheme . '://' . $host . $url;
    }

    $path = $base['path'] ?? '/';
    $dir = rtrim(str_replace('\\', '/', dirname($path)), '/');

    return $scheme . '://' . $host . ($dir ? $dir : '') . '/' . ltrim($url, '/');
}

function sanitize_card_html(string $html): string
{
    $html = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $html);
    $html = preg_replace('/<iframe\b[^>]*>(.*?)<\/iframe>/is', '', $html);
    $html = preg_replace('/<form\b[^>]*>(.*?)<\/form>/is', '', $html);
    $html = preg_replace('/<select\b[^>]*>(.*?)<\/select>/is', '', $html);
    $html = preg_replace('/<option\b[^>]*>(.*?)<\/option>/is', '', $html);
    $html = preg_replace('/<button\b[^>]*>(.*?)<\/button>/is', '', $html);
    $html = preg_replace('/<input\b[^>]*>/is', '', $html);
    $html = preg_replace('/<label\b[^>]*>(.*?)<\/label>/is', '', $html);

    $html = preg_replace('/\son\w+="[^"]*"/i', '', $html);
    $html = preg_replace("/\son\w+='[^']*'/i", '', $html);

    return trim($html);
}

function extract_prices_from_text(string $text): array
{
    preg_match_all('/\d{1,4}(?:[.,]\d{2})/', $text, $matches);

    $prices = [];

    foreach ($matches[0] as $candidate) {
    $price = clean_price_to_float($candidate);
    if ($price !== null && is_reasonable_price($price)) {
        $prices[] = $price;
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
    $discount = min($prices);
    $original = max($prices);

    if ($discount < $original) {
        $discountActive = 1;
    } else {
        $discount = null;
        $discountActive = 0;
    }
}

    return [
        'price_original' => $original ?? 0.00,
        'price_discount' => $discount,
        'discount_active' => $discountActive
    ];
}

function is_reasonable_price(?float $price): bool
{
    if ($price === null) {
        return false;
    }

    return $price >= 10 && $price <= 300;
}

function extract_single_reasonable_price_from_text(string $text): ?float
{
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    preg_match_all('/\d{1,4}(?:[.,]\d{2})/', $text, $matches);

    $prices = [];

    foreach ($matches[0] as $candidate) {
        $price = clean_price_to_float($candidate);
        if ($price !== null && is_reasonable_price($price)) {
            $prices[] = $price;
        }
    }

    $prices = array_values(array_unique($prices));
    sort($prices);

    if (count($prices) === 0) {
        return null;
    }

    // Si aparece "Desde", nos quedamos con el menor precio válido.
    if (mb_stripos($text, 'desde') !== false || mb_stripos($text, 'from price') !== false) {
        return min($prices);
    }

    // Si solo queremos un precio fiable y único por producto, usamos el menor razonable.
    return min($prices);
}

function clean_display_title(string $title): string
{
    $title = normalize_title($title);

    $patterns = [
        '/\b(?:añadir|anadir|agregada|agregado|agregar|comprar|buy|add to cart|cart)\b/iu',
        '/(?:none|ninguno)(?:\s*\/\s*(?:none|ninguno))+?/iu',
        '/\s*[-–]\s*(?:XXS|XS|S|M|L|XL|XXL|2XL|3XL|4XL)(?:\s*\/\s*(?:none|ninguno|sin nombre|sin numero|sin número|[a-záéíóúñ]+))*$/iu',
        '/\b\d{1,4}(?:[.,]\d{2})\s*€\b/u',
    ];

    foreach ($patterns as $pattern) {
        $title = preg_replace($pattern, '', $title);
    }

    $title = preg_replace('/\s{2,}/u', ' ', $title);

    return trim($title, " \t\n\r\0\x0B-–/");
}

