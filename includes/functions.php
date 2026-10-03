<?php

function rom_h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function rom_base_url(string $path = ''): string
{
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/CarrotRom/index.php')), '/');
    if ($base === '' || $base === '.') {
        $base = '';
    }
    return $base . '/' . ltrim($path, '/');
}

function rom_detail_url(string $romId): string
{
    return rom_base_url('detail.php?id=' . rawurlencode($romId));
}

function rom_excerpt(?string $text, int $limit = 54): string
{
    $text = trim(strip_tags((string) $text));
    if ($text === '') {
        return '';
    }
    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($text, 0, $limit, '...');
    }
    return strlen($text) > $limit ? substr($text, 0, $limit - 3) . '...' : $text;
}

function rom_footer_sites(?PDO $pdo): array
{
    if (!$pdo instanceof PDO) {
        return [];
    }

    try {
        static $hasStatus = null;
        if ($hasStatus === null) {
            $columns = $pdo->query('SHOW COLUMNS FROM sites')->fetchAll(PDO::FETCH_COLUMN);
            $hasStatus = in_array('status', $columns, true);
        }
        $statusWhere = $hasStatus ? " AND COALESCE(status, 'active') = 'active'" : '';
        $stmt = $pdo->query("SELECT name, url, logo, description FROM sites WHERE COALESCE(url, '') <> ''{$statusWhere} ORDER BY sort_order ASC, name ASC LIMIT 10");
        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {
        return [];
    }
}

function rom_render_footer(?PDO $pdo = null): void
{
    $footerSites = rom_footer_sites($pdo);
    $footerColumns = [
        'Organization' => [
            'About' => 'https://carrot28.com/about',
            'Services' => 'https://carrot28.com/services',
            'Contact' => 'https://carrot28.com/contact',
        ],
        'Legal' => [
            'Privacy Policy' => 'https://carrot28.com/privacy-policy',
            'Terms of Service' => 'https://carrot28.com/terms-of-service',
            'Cookie Policy' => 'https://carrot28.com/cookie-policy',
            'Disclaimer' => 'https://carrot28.com/disclaimer',
        ],
    ];
    ?>
    <footer class="site-footer">
        <div class="footer-brand">
            <a class="footer-logo" href="https://home.carrot28.com" target="_blank" rel="noopener noreferrer" aria-label="Carrot28">
                <span class="brand-mark">CR</span>
            </a>
            <div>
                <strong>Carrot28</strong>
                <p>Hệ sinh thái sản phẩm số Carrot28 kết nối app, game, nội dung, âm nhạc và thư viện ROM trong một nền tảng sáng tạo.</p>
            </div>
        </div>

        <nav class="footer-menu<?= $footerSites ? ' has-sites' : '' ?>" aria-label="Footer navigation">
            <div class="footer-column">
                <h2>Explore</h2>
                <a href="<?= rom_h(rom_base_url()) ?>">Store</a>
                <a href="<?= rom_h(rom_base_url('index.php?platform=psp')) ?>">PPSSPP</a>
                <a href="<?= rom_h(rom_base_url('index.php?platform=ps1')) ?>">PlayStation</a>
                <a href="#rom-list">ROM Library</a>
            </div>

            <?php foreach ($footerColumns as $columnTitle => $links): ?>
                <div class="footer-column">
                    <h2><?= rom_h($columnTitle) ?></h2>
                    <?php foreach ($links as $label => $url): ?>
                        <a href="<?= rom_h($url) ?>" target="_blank" rel="noopener noreferrer"><?= rom_h($label) ?></a>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>

            <?php if ($footerSites): ?>
                <div class="footer-column footer-sites">
                    <h2>Sites</h2>
                    <?php foreach ($footerSites as $site): ?>
                        <?php
                        $siteName = trim((string) ($site['name'] ?? ''));
                        $siteUrl = trim((string) ($site['url'] ?? ''));
                        $siteLogo = trim((string) ($site['logo'] ?? ''));
                        $siteDescription = rom_excerpt($site['description'] ?? '', 54);
                        if ($siteName === '' || $siteUrl === '') {
                            continue;
                        }
                        ?>
                        <a class="footer-site-link" href="<?= rom_h($siteUrl) ?>" target="_blank" rel="noopener noreferrer">
                            <?php if ($siteLogo !== ''): ?>
                                <img src="<?= rom_h($siteLogo) ?>" alt="" loading="lazy">
                            <?php else: ?>
                                <span class="footer-site-icon" aria-hidden="true">
                                    <svg viewBox="0 0 24 24"><path d="M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Zm0 0c2.4-2.3 3.6-5.3 3.6-9S14.4 5.3 12 3m0 18c-2.4-2.3-3.6-5.3-3.6-9S9.6 5.3 12 3M3.6 9h16.8M3.6 15h16.8" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                                </span>
                            <?php endif; ?>
                            <span>
                                <strong><?= rom_h($siteName) ?></strong>
                                <?php if ($siteDescription !== ''): ?><small><?= rom_h($siteDescription) ?></small><?php endif; ?>
                            </span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </nav>

        <div class="footer-bottom">
            <span>© <?= date('Y') ?> Carrot28. All rights reserved.</span>
            <span>Built for simple ROM discovery.</span>
        </div>
    </footer>
    <?php
}

function rom_decode_photos(?string $value): array
{
    $value = trim((string) $value);
    if ($value === '') {
        return [];
    }
    $decoded = json_decode($value, true);
    if (is_array($decoded)) {
        return array_values(array_filter(array_map(static fn($item): string => trim((string) $item), $decoded)));
    }
    return array_values(array_filter(array_map(static fn(string $line): string => trim($line), preg_split('/\r\n|\r|\n/', $value) ?: [])));
}

function rom_decode_files(?string $fileRom, ?string $fileSize = ''): array
{
    $fileRom = trim((string) $fileRom);
    if ($fileRom === '') {
        return [];
    }

    $decoded = json_decode($fileRom, true);
    if (is_array($decoded)) {
        $items = array_keys($decoded) === range(0, count($decoded) - 1) ? $decoded : [$decoded];
        $rows = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $url = trim((string) ($item['url'] ?? $item['file_rom'] ?? ''));
            if ($url === '') {
                continue;
            }
            $rows[] = [
                'console_id' => trim((string) ($item['console_id'] ?? $item['platform'] ?? '')),
                'console_name' => trim((string) ($item['console_name'] ?? '')),
                'url' => $url,
                'file_size' => trim((string) ($item['file_size'] ?? $item['size'] ?? '')),
            ];
        }
        return $rows;
    }

    return [[
        'console_id' => '',
        'console_name' => '',
        'url' => $fileRom,
        'file_size' => trim((string) $fileSize),
    ]];
}

function rom_first_file(array $rom): array
{
    foreach (rom_decode_files($rom['file_rom'] ?? '', $rom['file_size'] ?? '') as $file) {
        if (!empty($file['url'])) {
            return $file;
        }
    }
    return ['console_id' => '', 'console_name' => '', 'url' => '', 'file_size' => ''];
}

function rom_platform_label(string $platform): string
{
    $platforms = array_values(array_filter(array_map('trim', explode(',', $platform))));
    if (count($platforms) > 1) {
        return implode(', ', array_map('rom_platform_label', $platforms));
    }
    $platform = $platforms[0] ?? $platform;
    $labels = [
        'ps1' => 'PlayStation',
        'ps2' => 'PlayStation 2',
        'psp' => 'PSP / PPSSPP',
        'ps3' => 'PlayStation 3',
        'ps4' => 'PlayStation 4',
    ];
    return $labels[$platform] ?? strtoupper($platform ?: 'ROM');
}

function rom_price_label(array $rom): string
{
    if (!empty($rom['is_free']) || (float) ($rom['price'] ?? 0) <= 0) {
        return 'Free';
    }
    return number_format((float) ($rom['price'] ?? 0), 2) . ' USD';
}

function rom_sanitize_html(string $html): string
{
    $html = trim($html);
    if ($html === '') {
        return '';
    }

    $html = preg_replace('#<(script|style|iframe|object|embed|form|input|button|textarea|select|option|meta|link)[^>]*>.*?</\1>#is', '', $html) ?? '';
    $html = preg_replace('#</?(script|style|iframe|object|embed|form|input|button|textarea|select|option|meta|link)[^>]*>#i', '', $html) ?? '';
    $html = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? '';
    $html = preg_replace('/\s+(href|src)\s*=\s*([\'"])\s*javascript:[^\'"]*\2/i', '', $html) ?? '';
    $html = strip_tags($html, '<p><br><strong><b><em><i><u><h2><h3><h4><ul><ol><li><a><span>');
    $html = preg_replace_callback('/<a\b([^>]*)>/i', static function (array $matches): string {
        $attrs = $matches[1] ?? '';
        if (!preg_match('/\shref\s*=\s*([\'"])(.*?)\1/i', $attrs, $hrefMatch)) {
            return '<a>';
        }
        $href = trim(html_entity_decode($hrefMatch[2], ENT_QUOTES, 'UTF-8'));
        if (!preg_match('#^(https?:)?//#i', $href) && strpos($href, '/') !== 0 && strpos($href, '#') !== 0 && strpos($href, 'mailto:') !== 0) {
            return '<a>';
        }
        return '<a href="' . rom_h($href) . '" target="_blank" rel="noopener noreferrer">';
    }, $html) ?? '';

    return $html;
}

function rom_description_html(array $rom): string
{
    $description = trim((string) ($rom['description'] ?? ''));
    if ($description === '') {
        $description = trim((string) ($rom['category_names'] ?? $rom['category'] ?? ''));
    }
    if ($description === '') {
        return '';
    }

    if (!preg_match('/<\/?[a-z][\s\S]*>/i', $description)) {
        return '<p>' . rom_h(mb_strimwidth($description, 0, 110, '...')) . '</p>';
    }

    return rom_sanitize_html($description);
}
