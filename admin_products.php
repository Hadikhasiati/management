<?php
// admin_products.php
ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once 'auth.php';
require_once 'db.php';

// فرض بر این است که تابع بررسی سطح دسترسی مدیر وجود دارد
if (function_exists('check_admin_auth')) {
    check_admin_auth();
}

$msg = '';
$error = '';

// تغییر وضعیت سفارش (تایید، تحویل داده شده و...)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_order_status') {
    $order_id = (int)($_POST['order_id'] ?? 0);
    $new_status = trim($_POST['order_status'] ?? 'pending');
    if ($order_id > 0) {
        $stmtUpd = $pdo->prepare("UPDATE orders SET status = ? WHERE id = ?");
        $stmtUpd->execute([$new_status, $order_id]);
        $msg = 'وضعیت سفارش با موفقیت به‌روزرسانی شد.';
    }
}

// ثبت یا ویرایش دسته‌بندی
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_category') {
    $cat_name = trim($_POST['cat_name'] ?? '');
    if (!empty($cat_name)) {
        $stmt = $pdo->prepare("INSERT INTO product_categories (name) VALUES (?)");
        $stmt->execute([$cat_name]);
        $msg = 'دسته‌بندی جدید با موفقیت اضافه شد.';
    }
}

// ثبت یا ویرایش محصول
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_product') {
    $id = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
    $name = trim($_POST['name'] ?? '');
    $category_id = (int)($_POST['category_id'] ?? 0);
    $price = (float)($_POST['price'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $sizes = trim($_POST['sizes'] ?? '');
    $colors = trim($_POST['colors'] ?? '');
    $status = $_POST['status'] ?? 'active';
    $image = trim($_POST['image'] ?? '');

    // آپلود عکس اگر فایل ارسال شده باشد
    if (isset($_FILES['image_file']) && $_FILES['image_file']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = 'uploads/products/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        $fileExtension = pathinfo($_FILES['image_file']['name'], PATHINFO_EXTENSION);
        $fileName = 'prod_' . time() . '_' . mt_rand(1000, 9999) . '.' . $fileExtension;
        $uploadFile = $uploadDir . $fileName;
        
        if (move_uploaded_file($_FILES['image_file']['tmp_name'], $uploadFile)) {
            $image = $uploadFile;
        }
    }

    if (!empty($name) && $price > 0) {
        if ($id > 0) {
            // ویرایش محصول
            $stmt = $pdo->prepare("UPDATE products SET name = ?, category_id = ?, price = ?, description = ?, sizes = ?, colors = ?, status = ?, image = ? WHERE id = ?");
            $stmt->execute([$name, $category_id, $price, $description, $sizes, $colors, $status, $image, $id]);
            $msg = 'محصول با موفقیت ویرایش شد.';
        } else {
            // درج محصول جدید
            $stmt = $pdo->prepare("INSERT INTO products (name, category_id, price, description, sizes, colors, status, image) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $category_id, $price, $description, $sizes, $colors, $status, $image]);
            $msg = 'محصول جدید با موفقیت ثبت شد.';
        }
    } else {
        $error = 'لطفاً نام محصول و قیمت معتبر را وارد کنید.';
    }
}

// حذف محصول
if (isset($_GET['delete'])) {
    $del_id = (int)$_GET['delete'];
    $pdo->prepare("DELETE FROM products WHERE id = ?")->execute([$del_id]);
    header('Location: admin_products.php');
    exit;
}

$categories = $pdo->query("SELECT * FROM product_categories ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
$products = $pdo->query("SELECT p.*, c.name as cat_name FROM products p LEFT JOIN product_categories c ON p.category_id = c.id ORDER BY p.id DESC")->fetchAll(PDO::FETCH_ASSOC);

// واکشی لیست تمام سفارشات کاربران برای مدیر
$orders = [];
try {
    $orders = $pdo->query("
        SELECT o.*, u.full_name as student_name, u.phone as student_phone, p.name as product_name 
        FROM orders o 
        LEFT JOIN users u ON o.user_id = u.id 
        LEFT JOIN products p ON o.product_id = p.id 
        ORDER BY o.id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// اطلاعات محصول برای ویرایش
$edit_prod = null;
if (isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->execute([$edit_id]);
    $edit_prod = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>مدیریت محصولات و سفارشات فروشگاه | باشگاه رادین</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: system-ui, -apple-system, sans-serif; }
        body { background-color: #0b1120; color: #f8fafc; min-height: 100vh; padding: 1.5rem 1rem; }
        .container { max-width: 1250px; margin: 0 auto; }
        .top-navbar { display: flex; justify-content: space-between; align-items: center; background: #111827; border: 1px solid #1f2937; padding: 1rem 1.25rem; border-radius: 14px; margin-bottom: 1.5rem; }
        .btn-back { background: #1e293b; color: #94a3b8; border: 1px solid #334155; padding: 0.5rem 1rem; border-radius: 8px; text-decoration: none; font-size: 0.85rem; font-weight: 700; }
        .grid-layout { display: grid; grid-template-columns: 350px 1fr; gap: 1.5rem; margin-bottom: 2rem; }
        @media (max-width: 900px) { .grid-layout { grid-template-columns: 1fr; } }
        .card { background: #111827; border: 1px solid #1f2937; border-radius: 16px; padding: 1.25rem; margin-bottom: 1.5rem; }
        .form-group { display: flex; flex-direction: column; gap: 0.4rem; margin-bottom: 0.85rem; }
        .form-group label { font-size: 0.82rem; color: #94a3b8; font-weight: 600; }
        .form-control { background: #1e293b; border: 1px solid #334155; border-radius: 8px; padding: 0.6rem; color: #fff; font-size: 0.9rem; outline: none; }
        .btn-submit { background: linear-gradient(135deg, #0284c7, #0369a1); color: #fff; border: none; padding: 0.65rem 1.25rem; border-radius: 8px; font-weight: 700; cursor: pointer; width: 100%; }
        table { width: 100%; border-collapse: collapse; text-align: right; font-size: 0.85rem; }
        th { color: #94a3b8; border-bottom: 1px solid #1f2937; padding: 0.75rem; }
        td { border-bottom: 1px solid #1f2937; padding: 0.75rem; vertical-align: middle; }
        .badge-active { background: #065f46; color: #34d399; padding: 0.2rem 0.5rem; border-radius: 4px; font-size: 0.75rem; }
        .badge-inactive { background: #7f1d1d; color: #f87171; padding: 0.2rem 0.5rem; border-radius: 4px; font-size: 0.75rem; }
    </style>
</head>
<body>
    <div class="container">
        <div class="top-navbar">
            <h2 style="font-size: 1.15rem; color: #38bdf8;">🛒 مدیریت محصولات و سفارشات فروشگاه</h2>
            <a href="dashboard.php" class="btn-back">بازگشت به پیشخوان ↵</a>
        </div>

        <?php if (!empty($msg)): ?>
            <div style="background: #065f46; color: #34d399; padding: 0.75rem; border-radius: 8px; margin-bottom: 1rem;"><?= $msg ?></div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div style="background: #7f1d1d; color: #f87171; padding: 0.75rem; border-radius: 8px; margin-bottom: 1rem;"><?= $error ?></div>
        <?php endif; ?>

        <!-- بخش مدیریت و نمایش سفارشات کاربران -->
        <div class="card" style="margin-bottom: 2rem;">
            <h3 style="font-size: 1.05rem; color: #38bdf8; margin-bottom: 1rem;">📦 مدیریت و پیگیری سفارشات ثبت‌شده کاربران</h3>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>شماره سفارش</th>
                            <th>نام هنرجو</th>
                            <th>محصول خریداری شده</th>
                            <th>مشخصات (سایز / رنگ)</th>
                            <th>تعداد</th>
                            <th>مبلغ کل</th>
                            <th>کد رهگیری</th>
                            <th>وضعیت</th>
                            <th>عملیات تغییر وضعیت</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($orders)): ?>
                            <tr><td colspan="9" style="text-align: center; color: #64748b; padding: 2rem;">هنوز سفارشی از طرف کاربران ثبت نشده است.</td></tr>
                        <?php else: ?>
                            <?php foreach ($orders as $ord): ?>
                                <tr>
                                    <td>#<?= $ord['id'] ?></td>
                                    <td>
                                        <strong><?= htmlspecialchars($ord['student_name'] ?: 'کاربر ناشناخته') ?></strong><br>
                                        <span style="font-size: 0.75rem; color: #94a3b8; font-family: monospace;"><?= htmlspecialchars($ord['student_phone'] ?: '-') ?></span>
                                    </td>
                                    <td><strong><?= htmlspecialchars($ord['product_name'] ?: 'محصول حذف‌شده') ?></strong></td>
                                    <td>
                                        سایز: <?= htmlspecialchars($ord['selected_size'] ?: '-') ?><br>
                                        رنگ: <?= htmlspecialchars($ord['selected_color'] ?: '-') ?>
                                    </td>
                                    <td><?= $ord['quantity'] ?></td>
                                    <td style="color: #4ade80; font-weight: 700;"><?= number_format($ord['total_amount']) ?> ت</td>
                                    <td style="font-family: monospace; color: #38bdf8;"><?= htmlspecialchars($ord['tracking_code'] ?: '-') ?></td>
                                    <td>
                                        <?php if ($ord['status'] === 'paid'): ?>
                                            <span style="color: #4ade80; font-weight: 700;">پرداخت شده</span>
                                        <?php elseif ($ord['status'] === 'delivered'): ?>
                                            <span style="color: #38bdf8; font-weight: 700;">تحویل داده شده</span>
                                        <?php else: ?>
                                            <span style="color: #fbbf24; font-weight: 700;">در انتظار / نامشخص</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <form action="admin_products.php" method="POST" style="display: flex; gap: 0.4rem; align-items: center;">
                                            <input type="hidden" name="action" value="update_order_status">
                                            <input type="hidden" name="order_id" value="<?= $ord['id'] ?>">
                                            <select name="order_status" class="form-control" style="padding: 0.3rem; font-size: 0.78rem;">
                                                <option value="pending" <?= $ord['status'] === 'pending' ? 'selected' : '' ?>>در انتظار</option>
                                                <option value="paid" <?= $ord['status'] === 'paid' ? 'selected' : '' ?>>پرداخت شده</option>
                                                <option value="delivered" <?= $ord['status'] === 'delivered' ? 'selected' : '' ?>>تحویل داده شده</option>
                                            </select>
                                            <button type="submit" style="background: #0284c7; color: #fff; border: none; padding: 0.35rem 0.6rem; border-radius: 6px; font-size: 0.78rem; cursor: pointer;">ثبت</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="grid-layout">
            <!-- ستون فرم‌ها -->
            <div>
                <!-- فرم افزودن دسته‌بندی -->
                <div class="card">
                    <h3 style="font-size: 1rem; color: #38bdf8; margin-bottom: 1rem;">➕ ایجاد دسته‌بندی جدید</h3>
                    <form action="admin_products.php" method="POST">
                        <input type="hidden" name="action" value="save_category">
                        <div class="form-group">
                            <label>نام دسته‌بندی:</label>
                            <input type="text" name="cat_name" class="form-control" required placeholder="مثلاً: لوازم ایمنی، اسکیت سرعت...">
                        </div>
                        <button type="submit" class="btn-submit" style="background: #334155;">افزودن دسته</button>
                    </form>
                </div>

                <!-- فرم افزودن / ویرایش محصول -->
                <div class="card">
                    <h3 style="font-size: 1rem; color: #38bdf8; margin-bottom: 1rem;"><?= $edit_prod ? '✏️ ویرایش محصول' : '📦 افزودن محصول جدید' ?></h3>
                    <form action="admin_products.php" method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="save_product">
                        <?php if ($edit_prod): ?>
                            <input type="hidden" name="product_id" value="<?= $edit_prod['id'] ?>">
                        <?php endif; ?>

                        <div class="form-group">
                            <label>نام محصول:</label>
                            <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($edit_prod['name'] ?? '') ?>" required>
                        </div>

                        <div class="form-group">
                            <label>دسته‌بندی:</label>
                            <select name="category_id" class="form-control">
                                <option value="0">بدون دسته‌بندی</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= $cat['id'] ?>" <?= (isset($edit_prod) && $edit_prod['category_id'] == $cat['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($cat['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>قیمت (تومان):</label>
                            <input type="number" name="price" class="form-control" value="<?= $edit_prod['price'] ?? '' ?>" required>
                        </div>

                        <div class="form-group">
                            <label>سایزها (با کاما جدا کنید):</label>
                            <input type="text" name="sizes" class="form-control" placeholder="38, 39, 40, فری سایز" value="<?= htmlspecialchars($edit_prod['sizes'] ?? '') ?>">
                        </div>

                        <div class="form-group">
                            <label>رنگ‌ها (با کاما جدا کنید):</label>
                            <input type="text" name="colors" class="form-control" placeholder="مشکی, قرمز, آبی" value="<?= htmlspecialchars($edit_prod['colors'] ?? '') ?>">
                        </div>

                        <div class="form-group">
                            <label>توضیحات معرفی محصول:</label>
                            <textarea name="description" class="form-control" rows="3"><?= htmlspecialchars($edit_prod['description'] ?? '') ?></textarea>
                        </div>

                        <div class="form-group">
                            <label>لینک عکس یا آپلود تصویر:</label>
                            <input type="text" name="image" class="form-control" placeholder="آدرس لینک عکس یا آپلود فایل زیر" value="<?= htmlspecialchars($edit_prod['image'] ?? '') ?>" style="margin-bottom: 0.3rem;">
                            <input type="file" name="image_file" class="form-control" accept="image/*">
                        </div>

                        <div class="form-group">
                            <label>وضعیت نمایش:</label>
                            <select name="status" class="form-control">
                                <option value="active" <?= (isset($edit_prod) && $edit_prod['status'] === 'active') ? 'selected' : '' ?>>فعال (نمایش در فروشگاه)</option>
                                <option value="inactive" <?= (isset($edit_prod) && $edit_prod['status'] === 'inactive') ? 'selected' : '' ?>>غیرفعال</option>
                            </select>
                        </div>

                        <button type="submit" class="btn-submit"><?= $edit_prod ? 'ذخیره تغییرات' : 'ثبت و انتشار محصول' ?></button>
                        <?php if ($edit_prod): ?>
                            <a href="admin_products.php" style="display:block; text-align:center; margin-top:0.5rem; color:#94a3b8; font-size:0.8rem; text-decoration:none;">انصراف از ویرایش</a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <!-- ستون لیست محصولات -->
            <div class="card" style="overflow-x: auto;">
                <h3 style="font-size: 1rem; color: #38bdf8; margin-bottom: 1rem;">📋 لیست محصولات ثبت‌شده</h3>
                <table>
                    <thead>
                        <tr>
                            <th>تصویر</th>
                            <th>نام محصول</th>
                            <th>دسته</th>
                            <th>قیمت</th>
                            <th>وضعیت</th>
                            <th>عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($products)): ?>
                            <tr><td colspan="6" style="text-align: center; color: #64748b; padding: 2rem;">هنوز محصولی ثبت نشده است.</td></tr>
                        <?php else: ?>
                            <?php foreach ($products as $p): ?>
                                <tr>
                                    <td>
                                        <?php if (!empty($p['image'])): ?>
                                            <img src="<?= htmlspecialchars($p['image']) ?>" style="width: 40px; height: 40px; object-fit: cover; border-radius: 6px;">
                                        <?php else: ?>
                                            <span>⛸️</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><strong><?= htmlspecialchars($p['name']) ?></strong></td>
                                    <td><?= htmlspecialchars($p['cat_name'] ?: 'بدون دسته') ?></td>
                                    <td style="color: #4ade80; font-weight: 700;"><?= number_format($p['price']) ?> ت</td>
                                    <td>
                                        <?php if ($p['status'] === 'active'): ?>
                                            <span class="badge-active">فعال</span>
                                        <?php else: ?>
                                            <span class="badge-inactive">غیرفعال</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="admin_products.php?edit=<?= $p['id'] ?>" style="color: #38bdf8; text-decoration: none; margin-left: 0.5rem;">ویرایش</a>
                                        <a href="admin_products.php?delete=<?= $p['id'] ?>" onclick="return confirm('آیا از حذف این محصول اطمینان دارید؟')" style="color: #f87171; text-decoration: none;">حذف</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>