<?php

if (!defined('CARROT_ROM_LIBRARY_PATH')) {
    define('CARROT_ROM_LIBRARY_PATH', getenv('CARROT_ROM_LIBRARY_PATH') ?: 'E:\\GameRom');
}

if (!defined('CARROT_ROM_SITE_BASE_URL')) {
    $romSiteBaseUrl = getenv('CARROT_ROM_SITE_BASE_URL') ?: '';
    if ($romSiteBaseUrl === '') {
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        if ($host === 'rom.carrot28.com') {
            $romSiteBaseUrl = '';
        } elseif ($host === 'admin.carrot28.com') {
            $romSiteBaseUrl = 'https://rom.carrot28.com';
        } else {
            $romSiteBaseUrl = '/CarrotRom';
        }
    }
    define('CARROT_ROM_SITE_BASE_URL', $romSiteBaseUrl);
}
