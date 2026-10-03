<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/rom_library.php';

$romId = trim((string) ($_GET['id'] ?? ''));
$rom = null;
$errorMessage = $db_error ?? '';

if ($romId !== '' && $pdo instanceof PDO) {
    try {
        carrot_rom_sync_library_to_database($pdo);
        if (carrot_rom_safe_child_path($romId) !== null) {
            $cacheKey = carrot_rom_cache_key('rom_detail', [
                'id' => $romId,
                'library' => carrot_rom_library_signature(),
                'view' => 'detail_v1',
            ]);
            $cachedDetail = carrot_rom_cache_get($cacheKey, 86400);
            if (is_array($cachedDetail) && is_array($cachedDetail['rom'] ?? null)) {
                $rom = $cachedDetail['rom'];
            } else {
                $stmt = $pdo->prepare('
                    SELECT rom.*,
                      rom_console_names.console_names,
                      rom_category_names.category_names
                    FROM rom
                    LEFT JOIN (
                        SELECT rcm.rom_id, GROUP_CONCAT(DISTINCT rc.name ORDER BY rc.sort_order ASC, rc.name ASC SEPARATOR ", ") AS console_names
                        FROM rom_console_map rcm
                        INNER JOIN rom_console rc ON rc.id = rcm.console_id
                        WHERE rc.status = "active"
                        GROUP BY rcm.rom_id
                    ) rom_console_names ON rom_console_names.rom_id = rom.id
                    LEFT JOIN (
                        SELECT rcmap.rom_id, GROUP_CONCAT(DISTINCT rcat.name ORDER BY rcat.sort_order ASC, rcat.name ASC SEPARATOR ", ") AS category_names
                        FROM rom_category_map rcmap
                        INNER JOIN rom_category rcat ON rcat.id = rcmap.category_id
                        WHERE rcat.status = "active"
                        GROUP BY rcmap.rom_id
                    ) rom_category_names ON rom_category_names.rom_id = rom.id
                    WHERE rom.id = ? AND rom.status = "public"
                    LIMIT 1
                ');
                $stmt->execute([$romId]);
                $rows = carrot_rom_enrich_rows_from_library($stmt->fetchAll());
                $rom = $rows[0] ?? null;
                if ($rom) {
                    carrot_rom_cache_set($cacheKey, [
                        'created_at' => date('c'),
                        'rom' => $rom,
                    ]);
                }
            }
        }
    } catch (Throwable $e) {
        $errorMessage = $e->getMessage();
    }
}

$romName = (string) ($rom['name'] ?? $romId ?: 'ROM');
$romFile = $rom ? rom_first_file($rom) : ['url' => '', 'file_size' => ''];
$descriptionHtml = $rom ? rom_description_html($rom) : '';
$styleVersion = is_file(__DIR__ . '/styles.css') ? (string) filemtime(__DIR__ . '/styles.css') : '1';
?>
<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= rom_h($romName) ?> - CarrotRom</title>
    <meta name="description" content="<?= rom_h($rom ? mb_strimwidth(strip_tags((string) ($rom['description'] ?? '')), 0, 160, '...') : 'Chi tiết ROM game trên CarrotRom.') ?>">
    <link rel="icon" href="<?= rom_h(rom_base_url('favicon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= rom_h(rom_base_url('styles.css?v=' . $styleVersion)) ?>">
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body>
<div class="site-shell">
    <header class="topbar">
        <a class="brand" href="<?= rom_h(rom_base_url()) ?>">
            <span class="brand-mark">CR</span>
            <span>CarrotRom</span>
        </a>
        <nav class="topnav" aria-label="Rom platform">
            <a href="<?= rom_h(rom_base_url()) ?>">Store</a>
            <a href="<?= rom_h(rom_base_url('index.php?platform=psp')) ?>">PPSSPP</a>
            <a href="<?= rom_h(rom_base_url('index.php?platform=ps1')) ?>">PlayStation</a>
        </nav>
    </header>

    <?php if ($errorMessage): ?><div class="notice"><?= rom_h($errorMessage) ?></div><?php endif; ?>

    <?php if ($rom): ?>
        <main class="detail-layout">
            <div class="detail-cover">
                <?php if (!empty($rom['avatar'])): ?>
                    <img src="<?= rom_h($rom['avatar']) ?>" alt="<?= rom_h($romName) ?>">
                <?php endif; ?>
            </div>
            <article class="detail-panel">
                <div class="detail-meta">
                    <span><?= rom_h(($rom['console_names'] ?? '') ?: rom_platform_label((string) ($rom['platform'] ?? ''))) ?></span>
                    <?php if (!empty($rom['category_names'])): ?><span><?= rom_h($rom['category_names']) ?></span><?php endif; ?>
                    <?php if (!empty($rom['region'])): ?><span><?= rom_h($rom['region']) ?></span><?php endif; ?>
                    <span><?= rom_h(rom_price_label($rom)) ?></span>
                </div>
                <h1><?= rom_h($romName) ?></h1>
                <?php if ($descriptionHtml !== ''): ?><div class="detail-description"><?= $descriptionHtml ?></div><?php endif; ?>
                <div class="detail-actions">
                    <?php if (!empty($romFile['url'])): ?>
                        <a class="primary-action" href="<?= rom_h($romFile['url']) ?>" target="_blank" rel="noopener noreferrer">Download<?= !empty($romFile['file_size']) ? ' · ' . rom_h($romFile['file_size']) : '' ?></a>
                    <?php endif; ?>
                    <a class="secondary-action" href="<?= rom_h(rom_base_url()) ?>">
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="M19 12H5m0 0 6-6m-6 6 6 6"/>
                        </svg>
                        <span>Quay lại Store</span>
                    </a>
                </div>
            </article>
        </main>
    <?php else: ?>
        <div class="empty-state">
            <i data-lucide="gamepad-2"></i>
            <h2>Không tìm thấy ROM</h2>
            <p>Game này chưa public hoặc không còn thư mục tương ứng trong E:\GameRom.</p>
        </div>
    <?php endif; ?>
    <?php rom_render_footer($pdo ?? null); ?>
</div>
<script>if (window.lucide) lucide.createIcons();</script>
</body>
</html>
