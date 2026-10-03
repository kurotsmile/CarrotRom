<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/rom_library.php';

$roms = [];
$featured = null;
$categories = [];
$platforms = [];
$errorMessage = $db_error ?? '';
$q = trim((string) ($_GET['q'] ?? ''));
$platform = trim((string) ($_GET['platform'] ?? ''));
$category = trim((string) ($_GET['category'] ?? ''));

if ($pdo instanceof PDO) {
    try {
        carrot_rom_sync_library_to_database($pdo);
        $libraryIds = carrot_rom_library_ids();
        $cacheKey = carrot_rom_cache_key('rom_home', [
            'q' => $q !== '' ? sha1($q) : '',
            'platform' => $platform,
            'category' => $category,
            'library' => carrot_rom_library_signature(),
            'view' => 'wide_slider_detail_v1',
        ]);
        $cachedHome = carrot_rom_cache_get($cacheKey, $q === '' && $platform === '' && $category === '' ? 86400 : 900);
        if (is_array($cachedHome)) {
            $roms = is_array($cachedHome['roms'] ?? null) ? $cachedHome['roms'] : [];
            $categories = is_array($cachedHome['categories'] ?? null) ? $cachedHome['categories'] : [];
            $platforms = is_array($cachedHome['platforms'] ?? null) ? $cachedHome['platforms'] : [];
        } else {
            $where = ['status = "public"'];
            $params = [];
            if ($libraryIds) {
                $where[] = carrot_rom_bind_library_filter($libraryIds, 'rom.id', 'library_rom', $params);
            } else {
                $where[] = '1 = 0';
            }
            if ($q !== '') {
                $where[] = '(id LIKE :q_id OR name LIKE :q_name OR category LIKE :q_category OR description LIKE :q_description)';
                $like = '%' . $q . '%';
                $params += [':q_id' => $like, ':q_name' => $like, ':q_category' => $like, ':q_description' => $like];
            }
            if ($platform !== '') {
                $where[] = '(EXISTS (SELECT 1 FROM rom_console_map rcm WHERE rcm.rom_id = rom.id AND rcm.console_id = :platform) OR FIND_IN_SET(:platform_legacy, REPLACE(platform, " ", "")) > 0)';
                $params[':platform'] = $platform;
                $params[':platform_legacy'] = $platform;
            }
            if ($category !== '') {
                $where[] = '(EXISTS (SELECT 1 FROM rom_category_map rcmap WHERE rcmap.rom_id = rom.id AND rcmap.category_id = :category) OR FIND_IN_SET(:category_legacy, REPLACE(category, " ", "")) > 0)';
                $params[':category'] = $category;
                $params[':category_legacy'] = $category;
            }

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
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY sort_order ASC, updated_at DESC, name ASC
                LIMIT 96
            ');
            $stmt->execute($params);
            $roms = carrot_rom_enrich_rows_from_library($stmt->fetchAll());

            $categoryParams = [];
            $categoryLibraryFilter = $libraryIds
                ? ' AND ' . carrot_rom_bind_library_filter($libraryIds, 'r.id', 'category_library_rom', $categoryParams)
                : ' AND 1 = 0';
            $categoryStmt = $pdo->prepare('
                SELECT rc.id AS category, rc.name, COUNT(DISTINCT r.id) AS total
                FROM rom_category rc
                INNER JOIN rom_category_map rcm ON rcm.category_id = rc.id
                INNER JOIN rom r ON r.id = rcm.rom_id
                WHERE r.status = "public" AND rc.status = "active"' . $categoryLibraryFilter . '
                GROUP BY rc.id, rc.name
                ORDER BY total DESC, rc.name ASC
                LIMIT 14
            ');
            $categoryStmt->execute($categoryParams);
            $categories = $categoryStmt->fetchAll();

            $platformParams = [];
            $platformLibraryFilter = $libraryIds
                ? ' AND ' . carrot_rom_bind_library_filter($libraryIds, 'r.id', 'platform_library_rom', $platformParams)
                : ' AND 1 = 0';
            $platformStmt = $pdo->prepare('
                SELECT rc.id AS platform, rc.name, COUNT(DISTINCT r.id) AS total
                FROM rom_console rc
                INNER JOIN rom_console_map rcm ON rcm.console_id = rc.id
                INNER JOIN rom r ON r.id = rcm.rom_id
                WHERE r.status = "public" AND rc.status = "active"' . $platformLibraryFilter . '
                GROUP BY rc.id, rc.name
                ORDER BY total DESC, rc.sort_order ASC, rc.name ASC
            ');
            $platformStmt->execute($platformParams);
            $platforms = $platformStmt->fetchAll();

            carrot_rom_cache_set($cacheKey, [
                'created_at' => date('c'),
                'roms' => $roms,
                'categories' => $categories,
                'platforms' => $platforms,
            ]);
        }
        $featured = $roms[0] ?? null;
    } catch (Throwable $e) {
        $errorMessage = $e->getMessage();
    }
}

$fallbackHero = __DIR__ . '/assets/store.jpg';
$heroImage = $featured['avatar'] ?? '';
if ($heroImage === '' && is_file($fallbackHero)) {
    $heroImage = rom_base_url('assets/store.jpg');
}
$heroSlides = [];
foreach (array_slice($roms, 0, 6) as $slideRom) {
    $slideImage = (string) ($slideRom['avatar'] ?? '');
    if ($slideImage === '') {
        $slideImage = rom_base_url('assets/store.jpg');
    }
    $heroSlides[] = [
        'title' => (string) ($slideRom['name'] ?? $slideRom['id'] ?? 'CarrotRom'),
        'description' => (($slideRom['console_names'] ?? '') ?: rom_platform_label((string) ($slideRom['platform'] ?? ''))) . ' · ' . rom_price_label($slideRom),
        'image' => $slideImage,
        'url' => rom_detail_url((string) ($slideRom['id'] ?? '')),
        'action' => 'Xem chi tiết',
    ];
}
if (!$heroSlides) {
    $featureImage = $heroImage !== '' ? $heroImage : rom_base_url('assets/store.jpg');
    $heroSlides = [
        ['title' => 'CarrotRom', 'description' => 'Kho ROM giả lập cho PlayStation, PSP và PPSSPP.', 'image' => $featureImage, 'url' => '#rom-list', 'action' => 'Khám phá'],
        ['title' => 'Tự đồng bộ thư mục', 'description' => 'Copy game vào E:\\GameRom và danh sách tự cập nhật.', 'image' => $featureImage, 'url' => '#rom-list', 'action' => 'Xem game'],
        ['title' => 'Avatar theo từng game', 'description' => 'Mỗi thư mục game dùng avatar.* để hiển thị ảnh bìa.', 'image' => $featureImage, 'url' => '#rom-list', 'action' => 'Xem thư viện'],
        ['title' => 'Tải ROM nhanh', 'description' => 'File game được phục vụ qua CarrotRom mà không cần nằm trong XAMPP.', 'image' => $featureImage, 'url' => '#rom-list', 'action' => 'Bắt đầu'],
        ['title' => 'Metadata từ Admin', 'description' => 'Cập nhật hệ máy, thể loại, giá và mô tả HTML từ CarrotAdmin.', 'image' => $featureImage, 'url' => '#rom-list', 'action' => 'Quản lý tốt hơn'],
        ['title' => 'Store tối hiện đại', 'description' => 'Giao diện rộng hơn cho màn hình PC và màn hình lớn.', 'image' => $featureImage, 'url' => '#rom-list', 'action' => 'Duyệt ROM'],
    ];
}
$styleVersion = is_file(__DIR__ . '/styles.css') ? (string) filemtime(__DIR__ . '/styles.css') : '1';
?>
<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CarrotRom - PlayStation & PPSSPP Store</title>
    <meta name="description" content="CarrotRom lưu trữ ROM PlayStation Sony, PSP và PPSSPP với giao diện store tối hiện đại.">
    <link rel="icon" href="<?= rom_h(rom_base_url('favicon.svg')) ?>" type="image/svg+xml">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= rom_h(rom_base_url('favicon/favicon-32x32.png')) ?>">
    <link rel="icon" type="image/png" sizes="16x16" href="<?= rom_h(rom_base_url('favicon/favicon-16x16.png')) ?>">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= rom_h(rom_base_url('favicon/apple-touch-icon.png')) ?>">
    <link rel="manifest" href="<?= rom_h(rom_base_url('favicon/site.webmanifest')) ?>">
    <link rel="shortcut icon" href="<?= rom_h(rom_base_url('favicon.ico')) ?>">
    <meta name="theme-color" content="#ff0f63">
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

    <section class="hero" data-rom-slider>
        <?php foreach ($heroSlides as $slideIndex => $slide): ?>
            <article class="hero-slide <?= $slideIndex === 0 ? 'is-active' : '' ?>" data-rom-slide="<?= (int) $slideIndex ?>" style="background-image:linear-gradient(90deg,rgba(18,25,39,.9),rgba(18,25,39,.38),rgba(18,25,39,.82)),url('<?= rom_h($slide['image']) ?>')">
                <div class="hero-copy">
                    <span class="pill">CarrotRom</span>
                    <h1><?= rom_h($slide['title']) ?></h1>
                    <p><?= rom_h($slide['description']) ?></p>
                    <a class="primary-action" href="<?= rom_h($slide['url']) ?>"><?= rom_h($slide['action']) ?></a>
                </div>
            </article>
        <?php endforeach; ?>
        <button class="hero-arrow hero-arrow-prev" type="button" data-rom-slide-prev aria-label="Previous"><i data-lucide="arrow-left"></i></button>
        <button class="hero-arrow hero-arrow-next" type="button" data-rom-slide-next aria-label="Next"><i data-lucide="arrow-right"></i></button>
        <div class="hero-dots" aria-label="Banner slides">
            <?php foreach ($heroSlides as $slideIndex => $slide): ?>
                <button type="button" class="<?= $slideIndex === 0 ? 'is-active' : '' ?>" data-rom-slide-dot="<?= (int) $slideIndex ?>" aria-label="Slide <?= (int) $slideIndex + 1 ?>"></button>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="filters">
        <div class="section-title"><i data-lucide="star"></i><h2>Interesting offers</h2></div>
        <form class="search-form" method="get">
            <input name="q" value="<?= rom_h($q) ?>" placeholder="Tìm game, hệ máy, thể loại">
            <select name="platform">
                <option value="">Tất cả hệ máy</option>
                <?php foreach ($platforms as $item): ?>
                    <option value="<?= rom_h($item['platform']) ?>" <?= $platform === ($item['platform'] ?? '') ? 'selected' : '' ?>><?= rom_h(($item['name'] ?? '') ?: rom_platform_label((string) $item['platform'])) ?> (<?= (int) $item['total'] ?>)</option>
                <?php endforeach; ?>
            </select>
            <button type="submit"><i data-lucide="search"></i></button>
        </form>
    </section>

    <?php if ($errorMessage): ?><div class="notice"><?= rom_h($errorMessage) ?></div><?php endif; ?>

    <section class="rom-grid" id="rom-list">
        <?php foreach ($roms as $index => $rom): ?>
            <?php $photos = rom_decode_photos($rom['photos'] ?? ''); ?>
            <?php $romFile = rom_first_file($rom); ?>
            <article class="rom-card">
                <div class="card-label"><?= $index % 3 === 0 ? 'Recommend' : ($index % 3 === 1 ? 'Bestseller' : 'Goods of the week') ?></div>
                <div class="cover">
                    <a class="cover-link" href="<?= rom_h(rom_detail_url((string) ($rom['id'] ?? ''))) ?>" aria-label="<?= rom_h('Xem chi tiết ' . ($rom['name'] ?? $rom['id'] ?? 'ROM')) ?>">
                        <?php if (!empty($rom['avatar'])): ?><img src="<?= rom_h($rom['avatar']) ?>" alt="<?= rom_h($rom['name']) ?>"><?php endif; ?>
                    </a>
                    <span><?= rom_h(($rom['console_names'] ?? '') ?: rom_platform_label((string) ($rom['platform'] ?? ''))) ?></span>
                </div>
                <div class="card-body">
                    <h3><?= rom_h($rom['name'] ?? $rom['id']) ?></h3>
                    <?php $descriptionHtml = rom_description_html($rom); ?>
                    <?php if ($descriptionHtml !== ''): ?><div class="rom-description"><?= $descriptionHtml ?></div><?php endif; ?>
                    <?php if ($photos): ?>
                        <div class="thumbs">
                            <?php foreach (array_slice($photos, 0, 3) as $photo): ?><img src="<?= rom_h($photo) ?>" alt=""><?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="card-footer">
                    <strong><?= rom_h(rom_price_label($rom)) ?></strong>
                    <?php if (!empty($romFile['url'])): ?><a href="<?= rom_h($romFile['url']) ?>" target="_blank" rel="noopener noreferrer"><i data-lucide="download"></i></a><?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>

        <?php if (!$roms): ?>
            <div class="empty-state">
                <i data-lucide="gamepad-2"></i>
                <h2>Chưa có ROM public</h2>
                <p>Vào CarrotAdmin > Rom để thêm game, avatar, photos và file ROM.</p>
            </div>
        <?php endif; ?>
    </section>

    <section class="brand-wall">
        <div class="section-title"><i data-lucide="bookmark"></i><h2>Popular Platforms</h2></div>
        <div class="brand-grid">
            <?php foreach (['PlayStation', 'PSP', 'PPSSPP', 'Sony', 'PS2', 'Android'] as $name): ?>
                <div><?= rom_h($name) ?></div>
            <?php endforeach; ?>
        </div>
    </section>

    <?php if ($categories): ?>
        <section class="categories">
            <div class="section-title"><i data-lucide="folder"></i><h2>Categories</h2></div>
            <div class="category-list">
                <?php foreach ($categories as $item): ?>
                    <a href="<?= rom_h(rom_base_url('index.php?category=' . rawurlencode((string) $item['category']))) ?>"><?= rom_h(($item['name'] ?? '') ?: $item['category']) ?> <span><?= (int) $item['total'] ?></span></a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
    <?php rom_render_footer($pdo ?? null); ?>
</div>
<script>
if (window.lucide) lucide.createIcons();
(() => {
    const slider = document.querySelector('[data-rom-slider]');
    if (!slider) return;
    const slides = Array.from(slider.querySelectorAll('[data-rom-slide]'));
    const dots = Array.from(slider.querySelectorAll('[data-rom-slide-dot]'));
    if (slides.length <= 1) return;
    let index = 0;
    let timer = null;
    const show = (nextIndex) => {
        index = (nextIndex + slides.length) % slides.length;
        slides.forEach((slide, slideIndex) => slide.classList.toggle('is-active', slideIndex === index));
        dots.forEach((dot, dotIndex) => dot.classList.toggle('is-active', dotIndex === index));
    };
    const start = () => {
        window.clearInterval(timer);
        timer = window.setInterval(() => show(index + 1), 6200);
    };
    slider.querySelector('[data-rom-slide-prev]')?.addEventListener('click', () => { show(index - 1); start(); });
    slider.querySelector('[data-rom-slide-next]')?.addEventListener('click', () => { show(index + 1); start(); });
    dots.forEach((dot, dotIndex) => dot.addEventListener('click', () => { show(dotIndex); start(); }));
    start();
})();
</script>
</body>
</html>
