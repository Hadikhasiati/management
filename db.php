<?php
// db.php
$db_host = 'localhost';
$db_name = 'tyucdeii_app';
$db_user = 'tyucdeii_app';
$db_pass = 'Hadi@6098';

try {
    $pdo = new PDO("mysql:host={$db_host};dbname={$db_name};charset=utf8mb4", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    die("خطا در برقراری ارتباط با پایگاه‌داده: " . $e->getMessage());
}

// ==========================================
// تشخیص هوشمند ساب‌دامین و تنظیمات Multi-Tenant
// ==========================================
$host = $_SERVER['HTTP_HOST'] ?? 'ap.radinskateomd.ir';
$parts = explode('.', $host);
$subdomain = 'ap';

if (count($parts) >= 3) {
    $subdomain = strtolower(trim($parts[0]));
}

$club_id = 1;
$club_name = 'باشگاه رادین اسکیت';
$club_theme = '#0284c7';

if ($subdomain !== 'ap' && $subdomain !== 'www' && !empty($subdomain)) {
    try {
        $stmtTenant = $pdo->prepare("SELECT * FROM clubs WHERE subdomain = ? AND status = 'active' LIMIT 1");
        $stmtTenant->execute([$subdomain]);
        $club_info = $stmtTenant->fetch(PDO::FETCH_ASSOC);

        if ($club_info) {
            $club_id = (int)$club_info['id'];
            $club_name = $club_info['name'] ?? 'باشگاه ورزشی';
            $club_theme = $club_info['theme_color'] ?? '#0284c7';
        }
    } catch (Exception $e) {}
}

if (!defined('CURRENT_CLUB_ID')) define('CURRENT_CLUB_ID', $club_id);
if (!defined('CURRENT_CLUB_NAME')) define('CURRENT_CLUB_NAME', $club_name);
if (!defined('CURRENT_CLUB_THEME')) define('CURRENT_CLUB_THEME', $club_theme);