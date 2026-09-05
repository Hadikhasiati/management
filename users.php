<?php
// users.php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_only_cookies', '1');
    session_start();
}

date_default_timezone_set('Asia/Tehran');

require_once __DIR__ . '/db.php';
if (file_exists(__DIR__ . '/tenant.php')) {
    require_once __DIR__ . '/tenant.php';
}
require_once __DIR__ . '/auth.php';

$current_user = check_auth();

if (!defined('CURRENT_CLUB_ID')) define('CURRENT_CLUB_ID', (int)($current_user['club_id'] ?? 1));
if (!defined('CURRENT_CLUB_NAME')) define('CURRENT_CLUB_NAME', 'باشگاه رادین اسکیت');
if (!defined('CURRENT_CLUB_THEME')) define('CURRENT_CLUB_THEME', '#0284c7');

if (($current_user['role'] ?? '') !== 'admin') {
    header("Location: dashboard.php");
    exit;
}

// توابع تاریخ شمسی
if (!function_exists('gregorian_to_jalali')) {
    function gregorian_to_jalali(int $gy, int $gm, int $gd): array {
        $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
        $days = 355666 + (365 * $gy) + (int)(($gy2 + 3) / 4) - (int)(($gy2 + 99) / 100) + (int)(($gy2 + 399) / 400) + $gd + $g_d_m[$gm - 1];
        $jy = -1595 + (33 * (int)($days / 12053));
        $days %= 12053;
        $jy += 4 * (int)($days / 1461);
        $days %= 1461;
        if ($days > 365) {
            $jy += (int)(($days - 1) / 365);
            $days = ($days - 1) % 365;
        }
        if ($days < 186) {
            $jm = 1 + (int)($days / 31);
            $jd = 1 + ($days % 31);
        } else {
            $jm = 7 + (int)(($days - 186) / 30);
            $jd = 1 + (($days - 186) % 30);
        }
        return [$jy, $jm, $jd];
    }
}

$today_parts = explode('-', date('Y-m-d'));
list($current_jy, $current_jm, $current_jd) = gregorian_to_jalali((int)$today_parts[0], (int)$today_parts[1], (int)$today_parts[2]);

if (!function_exists('to_jalali_date')) {
    function to_jalali_date(?string $g_date): string {
        if (empty($g_date)) return 'ثبت نشده';
        $parts = explode('-', substr($g_date, 0, 10));
        if (count($parts) !== 3) return 'نامعتبر';
        list($jy, $jm, $jd) = gregorian_to_jalali((int)$parts[0], (int)$parts[1], (int)$parts[2]);
        return sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
    }
}

// محاسبه سن شمسی
if (!function_exists('calculate_user_age_jalali')) {
    function calculate_user_age_jalali(?string $birth_input, int $cur_jy, int $cur_jm, int $cur_jd): string {
        if (empty($birth_input) || $birth_input === '-' || $birth_input === '0000-00-00') {
            return 'ثبت نشده';
        }
        $birth_input = trim($birth_input);
        $b_jy = 0; $b_jm = 1; $b_jd = 1; $has_exact_date = false;

        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $birth_input, $m)) {
            $gy = (int)$m[1]; $gm = (int)$m[2]; $gd = (int)$m[3];
            if ($gy >= 1900) {
                list($b_jy, $b_jm, $b_jd) = gregorian_to_jalali($gy, $gm, $gd);
                $has_exact_date = true;
            } else {
                $b_jy = $gy; $b_jm = $gm; $b_jd = $gd;
                $has_exact_date = true;
            }
        } elseif (preg_match('/^(13\d{2}|14\d{2})[\/\-](\d{1,2})[\/\-](\d{1,2})$/', $birth_input, $m)) {
            $b_jy = (int)$m[1]; $b_jm = (int)$m[2]; $b_jd = (int)$m[3];
            $has_exact_date = true;
        } elseif (preg_match('/^(13\d{2}|14\d{2})$/', $birth_input, $m)) {
            $b_jy = (int)$m[1];
            $age = $cur_jy - $b_jy;
            return max(0, $age) . ' سال';
        } elseif (is_numeric($birth_input) && (int)$birth_input > 0 && (int)$birth_input < 110) {
            return (int)$birth_input . ' سال';
        }

        if ($has_exact_date && $b_jy > 1300 && $b_jy <= $cur_jy) {
            $age = $cur_jy - $b_jy;
            if ($cur_jm < $b_jm || ($cur_jm === $b_jm && $cur_jd < $b_jd)) {
                $age--;
            }
            return max(0, $age) . ' سال';
        }
        return 'ثبت نشده';
    }
}

$today = date('Y-m-d');

// ۱. لاگین تستی
if (isset($_GET['login_as'])) {
    $target_id = (int)$_GET['login_as'];
    $stmtTarget = $pdo->prepare("SELECT * FROM users WHERE id = ? AND club_id = ? LIMIT 1");
    $stmtTarget->execute([$target_id, CURRENT_CLUB_ID]);
    $target = $stmtTarget->fetch(PDO::FETCH_ASSOC);

    if ($target) {
        if (!isset($_SESSION['impersonator_admin_id'])) {
            $_SESSION['impersonator_admin_id'] = $current_user['id'];
        }
        $_SESSION['user_id'] = $target['id'];
        header("Location: dashboard.php");
        exit;
    }
}

// ۲. حذف هنرجو
if (isset($_GET['delete_user'])) {
    $del_id = (int)$_GET['delete_user'];
    $stmtDel = $pdo->prepare("DELETE FROM users WHERE id = ? AND club_id = ? AND role = 'student'");
    $stmtDel->execute([$del_id, CURRENT_CLUB_ID]);
    header("Location: users.php?msg=" . urlencode("هنرجو با موفقیت حذف شد."));
    exit;
}

// ۳. تنظیم اعتبار شهریه
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'adjust_subscription') {
    $target_id  = (int)($_POST['user_id'] ?? 0);
    $sub_action = $_POST['sub_action'] ?? '';
    $days       = (int)($_POST['days'] ?? 30);

    $stmtUser = $pdo->prepare("SELECT subscription_expires_at FROM users WHERE id = ? AND club_id = ? AND role = 'student' LIMIT 1");
    $stmtUser->execute([$target_id, CURRENT_CLUB_ID]);
    $user_sub = $stmtUser->fetch(PDO::FETCH_ASSOC);

    if ($user_sub) {
        $current_exp = $user_sub['subscription_expires_at'];
        $new_exp = null;
        $msg_text = '';

        if ($sub_action === 'add') {
            $base = (!empty($current_exp) && $current_exp >= $today) ? $current_exp : $today;
            $new_exp = date('Y-m-d', strtotime("{$base} +{$days} days"));
            $msg_text = "اعتبار به مدت {$days} روز افزایش یافت.";
        } elseif ($sub_action === 'subtract') {
            $base = (!empty($current_exp)) ? $current_exp : $today;
            $new_exp = date('Y-m-d', strtotime("{$base} -{$days} days"));
            $msg_text = "از اعتبار هنرجو {$days} روز کسر شد.";
        } elseif ($sub_action === 'cancel') {
            $new_exp = null; 
            $msg_text = "اعتبار شهریه هنرجو به طور کامل لغو شد.";
        } elseif ($sub_action === 'expire') {
            $new_exp = date('Y-m-d', strtotime('-1 day'));
            $msg_text = "اشتراک منقضی شد.";
        }

        $stmtUp = $pdo->prepare("UPDATE users SET subscription_expires_at = ? WHERE id = ? AND club_id = ?");
        $stmtUp->execute([$new_exp, $target_id, CURRENT_CLUB_ID]);
        header("Location: users.php?msg=" . urlencode($msg_text));
        exit;
    }
}

// واکشی پارامترهای فیلتر
$search = trim($_GET['search'] ?? '');
$status = trim($_GET['status'] ?? '');
$skill  = trim($_GET['skill'] ?? '');

$sql = "SELECT u.*, c.title as class_title, coach.full_name as coach_name 
        FROM users u 
        LEFT JOIN classes c ON u.class_id = c.id 
        LEFT JOIN users coach ON u.coach_id = coach.id 
        WHERE u.club_id = ? AND u.role = 'student'";
$params = [CURRENT_CLUB_ID];

if (!empty($search)) {
    $sql .= " AND (u.full_name LIKE ? OR u.phone LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}
if ($status === 'active') {
    $sql .= " AND u.subscription_expires_at >= ?";
    $params[] = $today;
} elseif ($status === 'expired') {
    $sql .= " AND (u.subscription_expires_at < ? OR u.subscription_expires_at IS NULL)";
    $params[] = $today;
}
if (!empty($skill)) {
    $sql .= " AND u.skill_level = ?";
    $params[] = $skill;
}

$sql .= " ORDER BY u.id DESC";

// ۴. خروجی اکسل استاندارد با فرمت XML (بدون کوچک‌ترین به هم‌ریختگی حروف فارسی و کاملاً ستون‌بندی‌شده)
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $stmtExp = $pdo->prepare($sql);
    $stmtExp->execute($params);
    $export_users = $stmtExp->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename=Radin_Students_Report_' . date('Y-m-d') . '.xls');
    
    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
    echo '<head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head>';
    echo '<body>';
    echo '<table border="1" style="direction:rtl; font-family:Tahoma; text-align:center;">';
    
    // هدرهای جدول اکسل
    echo '<tr style="background:#0284c7; color:#fff; font-weight:bold;">';
    echo '<th>ردیف</th>';
    echo '<th>نام و نام خانوادگی هنرجو</th>';
    echo '<th>شماره تماس</th>';
    echo '<th>سن (شمسی)</th>';
    echo '<th>سطح تمرینی</th>';
    echo '<th>مربی مسئول</th>';
    echo '<th>سانس کلاسی</th>';
    echo '<th>تاریخ انقضای شهریه</th>';
    echo '<th>روزهای باقی‌مانده اعتبار</th>';
    echo '<th>وضعیت اشتراک</th>';
    echo '</tr>';

    $row_index = 1;
    foreach ($export_users as $eu) {
        $is_act = (!empty($eu['subscription_expires_at']) && $eu['subscription_expires_at'] >= $today);
        $jalali_exp = to_jalali_date($eu['subscription_expires_at']);
        $user_age = calculate_user_age_jalali($eu['birth_date'] ?? null, $current_jy, $current_jm, $current_jd);
        
        $days_left = 0;
        if ($is_act && !empty($eu['subscription_expires_at'])) {
            $diff = strtotime($eu['subscription_expires_at']) - strtotime($today);
            $days_left = max(0, (int)round($diff / 86400));
        }

        echo '<tr>';
        echo '<td>' . $row_index++ . '</td>';
        echo '<td>' . htmlspecialchars($eu['full_name'] ?? 'بدون نام') . '</td>';
        echo '<td style="mso-number-format:\@;">' . htmlspecialchars($eu['phone'] ?? '') . '</td>'; // ترفند برای نمایش صحیح شماره موبایل بدون حذف صفر اول
        echo '<td>' . htmlspecialchars($user_age) . '</td>';
        echo '<td>' . htmlspecialchars($eu['skill_level'] ?? 'مبتدی') . '</td>';
        echo '<td>' . htmlspecialchars($eu['coach_name'] ?? 'تعیین نشده') . '</td>';
        echo '<td>' . htmlspecialchars($eu['class_title'] ?? 'عمومی') . '</td>';
        echo '<td>' . htmlspecialchars($jalali_exp) . '</td>';
        echo '<td>' . ($is_act ? $days_left . ' روز' : '۰ روز') . '</td>';
        echo '<td style="' . ($is_act ? 'color:green;' : 'color:red;') . '">' . ($is_act ? 'معتبر' : 'منقضی / فاقد اشتراک') . '</td>';
        echo '</tr>';
    }

    echo '</table>';
    echo '</body>';
    echo '</html>';
    exit;
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// لیست تمام سطوح موجود جهت فیلتر
$skill_levels = $pdo->prepare("SELECT DISTINCT skill_level FROM users WHERE club_id = ? AND role = 'student' AND skill_level IS NOT NULL AND skill_level != ''");
$skill_levels->execute([CURRENT_CLUB_ID]);
$all_skills = $skill_levels->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>مدیریت هنرجویان | <?= htmlspecialchars(CURRENT_CLUB_NAME) ?></title>
    <style>
        :root {
            --primary: <?= htmlspecialchars(CURRENT_CLUB_THEME) ?>;
            --bg-dark: #0b1120;
            --card-bg: rgba(17, 24, 39, 0.85);
            --border-color: rgba(255, 255, 255, 0.08);
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; font-family: system-ui, -apple-system, sans-serif; -webkit-tap-highlight-color: transparent; }
        body { background-color: var(--bg-dark); color: var(--text-main); min-height: 100vh; padding: 1rem 0.85rem calc(80px + env(safe-area-inset-bottom)) 0.85rem; }
        .container { max-width: 950px; margin: 0 auto; }
        
        .header-bar {
            display: flex; justify-content: space-between; align-items: center; background: rgba(30, 41, 59, 0.7);
            border: 1px solid var(--border-color); backdrop-filter: blur(12px); border-radius: 16px;
            padding: 0.85rem 1.1rem; margin-bottom: 1rem;
        }
        .btn-back { background: #1e293b; color: #38bdf8; border: 1px solid #334155; padding: 0.45rem 0.9rem; border-radius: 8px; text-decoration: none; font-size: 0.82rem; font-weight: 700; }

        .search-box { display: flex; gap: 0.5rem; margin-bottom: 0.75rem; flex-wrap: wrap; }
        .form-ctrl { height: 40px; background: #1e293b; border: 1px solid #334155; border-radius: 8px; color: #fff; padding: 0 0.75rem; font-size: 0.85rem; outline: none; flex: 1; min-width: 140px; }
        
        .user-card-mob {
            background: linear-gradient(135deg, rgba(30, 41, 59, 0.6), rgba(15, 23, 42, 0.85));
            border: 1px solid var(--border-color); border-radius: 14px; padding: 1rem; margin-bottom: 0.85rem;
        }
        .user-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem; }
        .user-name { font-size: 1rem; font-weight: 800; color: #fff; }
        
        .user-details-grid {
            display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.5rem; font-size: 0.8rem; color: #cbd5e1;
            background: rgba(15, 23, 42, 0.5); padding: 0.75rem; border-radius: 10px; margin: 0.5rem 0; border: 1px solid rgba(255,255,255,0.04);
        }

        .user-actions { display: flex; gap: 0.5rem; margin-top: 0.75rem; padding-top: 0.75rem; border-top: 1px solid rgba(255,255,255,0.06); flex-wrap: wrap; }
        .btn-mob-act { height: 36px; padding: 0 0.75rem; border-radius: 8px; font-size: 0.78rem; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; border: none; cursor: pointer; }
        .btn-mob-credit { background: rgba(139, 92, 246, 0.15); color: #c4b5fd; border: 1px solid #8b5cf6; }
        .btn-mob-login { background: rgba(2, 132, 199, 0.15); color: #38bdf8; border: 1px solid #0284c7; }
        .btn-mob-delete { background: rgba(239, 68, 68, 0.15); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.3); margin-right: auto; }

        .modal-overlay { position: fixed; inset: 0; background: rgba(0, 0, 0, 0.85); backdrop-filter: blur(5px); display: none; align-items: center; justify-content: center; z-index: 999999; padding: 1rem; }
        .modal-box { background: #111827; border: 1px solid #334155; border-radius: 18px; max-width: 420px; width: 100%; padding: 1.25rem; }
        .modal-group { display: flex; flex-direction: column; gap: 0.35rem; margin-bottom: 0.75rem; }
        .modal-group label { font-size: 0.8rem; color: #94a3b8; font-weight: 600; }
    </style>
</head>
<body>
    <div class="container">
        
        <div class="header-bar">
            <div>
                <h2 style="font-size: 1.05rem; color: #38bdf8;">👥 مدیریت هنرجویان</h2>
                <div style="font-size: 0.75rem; color: #64748b;"><?= htmlspecialchars(CURRENT_CLUB_NAME) ?></div>
            </div>
            <a href="dashboard.php" class="btn-back">بازگشت ↵</a>
        </div>

        <?php if (isset($_GET['msg'])): ?>
            <div style="background:rgba(16,185,129,0.15); color:#34d399; border:1px solid #10b981; padding:0.75rem; border-radius:8px; margin-bottom:1rem; font-size:0.85rem;">
                ✓ <?= htmlspecialchars($_GET['msg']) ?>
            </div>
        <?php endif; ?>

        <form method="GET" class="search-box">
            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" class="form-ctrl" placeholder="جستجوی نام یا موبایل...">
            
            <select name="status" class="form-ctrl">
                <option value="">همه وضعیت‌ها</option>
                <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>اشتراک معتبر</option>
                <option value="expired" <?= $status === 'expired' ? 'selected' : '' ?>>منقضی / فاقد اشتراک</option>
            </select>

            <select name="skill" class="form-ctrl">
                <option value="">همه سطوح تمرینی</option>
                <?php foreach ($all_skills as $s_item): ?>
                    <option value="<?= htmlspecialchars($s_item) ?>" <?= $skill === $s_item ? 'selected' : '' ?>><?= htmlspecialchars($s_item) ?></option>
                <?php endforeach; ?>
            </select>

            <div style="display: flex; gap: 0.4rem; width: 100%;">
                <button type="submit" style="flex: 1; background: var(--primary); color: #fff; border: none; border-radius: 8px; font-weight: 700; height: 40px; cursor: pointer;">فیلتر و جستجو</button>
                <a href="users.php?export=csv&search=<?= urlencode($search) ?>&status=<?= urlencode($status) ?>&skill=<?= urlencode($skill) ?>" style="background: #059669; color: #fff; padding: 0 1rem; border-radius: 8px; font-weight: 700; height: 40px; display: inline-flex; align-items: center; text-decoration: none; font-size: 0.85rem;">خروجی اکسل 📊</a>
            </div>
        </form>

        <?php if (empty($users)): ?>
            <div style="text-align:center; color:#64748b; padding:3rem;">هنرجویی با این مشخصات یافت نشد.</div>
        <?php else: ?>
            <?php foreach ($users as $u): 
                $is_act = (!empty($u['subscription_expires_at']) && $u['subscription_expires_at'] >= $today);
                $jalali_exp = to_jalali_date($u['subscription_expires_at']);
                $user_age = calculate_user_age_jalali($u['birth_date'] ?? null, $current_jy, $current_jm, $current_jd);
                
                $days_left = 0;
                if ($is_act && !empty($u['subscription_expires_at'])) {
                    $diff = strtotime($u['subscription_expires_at']) - strtotime($today);
                    $days_left = max(0, (int)round($diff / 86400));
                }
            ?>
                <div class="user-card-mob">
                    <div class="user-top">
                        <span class="user-name"><?= htmlspecialchars($u['full_name'] ?: 'بدون نام') ?></span>
                        <span style="font-size:0.75rem; padding:2px 8px; border-radius:6px; font-weight:700; <?= $is_act ? 'background:rgba(16,185,129,0.15); color:#34d399;' : 'background:rgba(239,68,68,0.15); color:#f87171;' ?>">
                            <?= $is_act ? "معتبر ({$days_left} روز)" : 'منقضی / فاقد اشتراک' ?>
                        </span>
                    </div>

                    <div class="user-details-grid">
                        <div>شماره تماس: <strong style="font-family:monospace; color:#fff;"><?= htmlspecialchars($u['phone']) ?></strong></div>
                        <div>سطح تمرینی: <strong style="color:#38bdf8;"><?= htmlspecialchars($u['skill_level'] ?? 'مبتدی') ?></strong></div>
                        <div>سن (شمسی): <strong style="color:#fff;"><?= $user_age ?></strong></div>
                        <div>مربی: <strong style="color:#a5b4fc;"><?= htmlspecialchars($u['coach_name'] ?? 'تعیین نشده') ?></strong></div>
                        <div>سانس کلاسی: <strong style="color:#cbd5e1;"><?= htmlspecialchars($u['class_title'] ?? 'عمومی') ?></strong></div>
                        <div>انقضای شهریه: <strong style="font-family:monospace; color:<?= $is_act ? '#34d399' : '#f87171' ?>;"><?= $jalali_exp ?></strong></div>
                    </div>

                    <div class="user-actions">
                        <button type="button" class="btn-mob-act btn-mob-credit" onclick="openCreditModal(<?= $u['id'] ?>, '<?= htmlspecialchars(addslashes($u['full_name'] ?: $u['phone'])) ?>', '<?= htmlspecialchars($jalali_exp) ?>')">
                            ⏱️ مدیریت اعتبار
                        </button>
                        <a href="users.php?login_as=<?= $u['id'] ?>" class="btn-mob-act btn-mob-login">ورود به پنل ↗</a>
                        <a href="users.php?delete_user=<?= $u['id'] ?>" class="btn-mob-act btn-mob-delete" onclick="return confirm('آیا از حذف کامل این هنرجو اطمینان دارید؟')">حذف ✕</a>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

    </div>

    <!-- مودال مدیریت و تنظیم اعتبار -->
    <div class="modal-overlay" id="creditModal">
        <div class="modal-box">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
                <strong style="color:#38bdf8; font-size:0.95rem;">⏱️ مدیریت و تنظیم اعتبار شهریه</strong>
                <button onclick="closeCreditModal()" style="background:none; border:none; color:#94a3b8; font-size:1.2rem; cursor:pointer;">✕</button>
            </div>

            <div style="background:#1e293b; padding:0.75rem; border-radius:10px; margin-bottom:1rem; font-size:0.85rem;">
                <div>هنرجو: <strong id="modalUserName" style="color:#38bdf8;">-</strong></div>
                <div style="margin-top:3px;">تاریخ انقضای فعلی: <span id="modalCurrentExp" style="color:#4ade80; font-family:monospace;">-</span></div>
            </div>

            <form method="POST" action="users.php">
                <input type="hidden" name="action" value="adjust_subscription">
                <input type="hidden" name="user_id" id="modalUserId">

                <div class="modal-group">
                    <label>نوع عملیات:</label>
                    <select name="sub_action" class="form-ctrl" style="height: 38px; font-size: 0.85rem; width:100%;">
                        <option value="add">افزایش اعتبار (روز)</option>
                        <option value="subtract">کسر اعتبار (روز)</option>
                    </select>
                </div>

                <div class="modal-group">
                    <label>تعداد روز:</label>
                    <input type="number" name="days" value="30" min="1" max="365" class="form-ctrl" style="height: 38px; font-size: 0.85rem; width:100%;" required>
                </div>

                <button type="submit" style="width:100%; height:40px; background:var(--primary); color:#fff; border:none; border-radius:8px; font-weight:700; font-size:0.85rem; cursor:pointer; margin-top:0.25rem;">
                    اعمال تغییرات اعتبار 💾
                </button>
            </form>

            <hr style="border:0; border-top:1px solid #1f2937; margin: 1rem 0;">

            <div style="display:flex; gap:0.5rem;">
                <form method="POST" action="users.php" onsubmit="return confirm('آیا از لغو کامل اعتبار این هنرجو اطمینان دارید؟')" style="flex:1;">
                    <input type="hidden" name="action" value="adjust_subscription">
                    <input type="hidden" name="sub_action" value="cancel">
                    <input type="hidden" name="user_id" id="modalUserIdCancel">
                    <button type="submit" style="width:100%; height:36px; background:#b45309; color:#fff; border:none; border-radius:6px; font-weight:700; font-size:0.75rem; cursor:pointer;">
                        لغو کامل اعتبار
                    </button>
                </form>

                <form method="POST" action="users.php" onsubmit="return confirm('اشتراک هنرجو منقضی شود؟')" style="flex:1;">
                    <input type="hidden" name="action" value="adjust_subscription">
                    <input type="hidden" name="sub_action" value="expire">
                    <input type="hidden" name="user_id" id="modalUserIdExpire">
                    <button type="submit" style="width:100%; height:36px; background:#ef4444; color:#fff; border:none; border-radius:6px; font-weight:700; font-size:0.75rem; cursor:pointer;">
                        منقضی‌سازی فوری
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script>
        function openCreditModal(userId, userName, currentExp) {
            document.getElementById('modalUserName').innerText = userName;
            document.getElementById('modalCurrentExp').innerText = currentExp;
            document.getElementById('modalUserId').value = userId;
            document.getElementById('modalUserIdCancel').value = userId;
            document.getElementById('modalUserIdExpire').value = userId;
            document.getElementById('creditModal').style.display = 'flex';
        }
        function closeCreditModal() { document.getElementById('creditModal').style.display = 'none'; }
    </script>

    <!-- نوار ناوبری پایین -->
    <?php require_once __DIR__ . '/mobile_nav.php'; ?>
</body>
</html>