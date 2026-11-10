<?php
// admin.php - Moderation panel: throttled login, hide/restore, cache rebuild, JSON backup (spec §5.4).

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/http.php';
require_once __DIR__ . '/includes/repository.php';
require_once __DIR__ . '/includes/mailer.php';

header('X-Frame-Options: DENY');

function h($v): string {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

// $body is trusted HTML: every dynamic value in it has already gone through h().
function page(string $title, string $body, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    $v = (int) @filemtime(__DIR__ . '/assets/css/style.css');
    echo '<!DOCTYPE html><html lang="vi"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">'
        . '<title>' . h($title) . '</title><link rel="stylesheet" href="assets/css/style.css?v=' . $v . '"><style>'
        . 'main{width:min(100% - 2rem,var(--container));margin:2rem auto;display:grid;gap:1rem}'
        . 'h1,h2{font-family:var(--font-serif);color:var(--color-primary)}'
        . 'form{display:inline}nav{display:flex;flex-wrap:wrap;gap:.5rem}'
        . 'button{cursor:pointer;padding:.35rem .8rem;border:1px solid var(--color-primary);border-radius:var(--radius-sm);background:var(--color-surface);color:var(--color-primary)}'
        . 'button:hover{background:var(--color-primary-subtle)}'
        . 'input[type=password]{padding:.4rem .6rem;border:1px solid var(--color-border);border-radius:var(--radius-sm);background:var(--color-surface)}'
        . 'p[role=status]{padding:.6rem .9rem;border-radius:var(--radius-md);background:var(--color-primary-subtle);color:var(--color-primary)}'
        . 'table{width:100%;border-collapse:collapse;background:var(--color-surface);font-size:.875rem}'
        . 'th,td{border:1px solid var(--color-border);padding:.4rem .5rem;text-align:left;vertical-align:top}'
        . 'th{background:var(--color-surface-subtle)}td img{width:40px;height:50px;object-fit:cover;border-radius:var(--radius-sm)}'
        . 'mark{background:var(--color-primary-subtle);color:var(--color-primary);padding:.1rem .45rem;border-radius:var(--radius-full);white-space:nowrap}'
        . 'small,del{color:var(--color-text-subtle);white-space:nowrap}details{min-width:12rem;max-width:28rem}'
        . '</style></head><body><main>' . $body . '</main></body></html>';
    exit;
}

function fail_page(string $where, Throwable $e): never {
    error_log('[admin] ' . $where . ': ' . $e->getMessage());
    page('Lỗi hệ thống', '<h1>Hệ thống đang bận</h1><p>Vui lòng thử lại sau.</p>', 503);
}

function redirect_with(string $flash): never {
    $_SESSION['flash'] = $flash;
    session_write_close();
    header('Location: admin.php', true, 303);
    exit;
}

function login_page(string $msg = '', int $status = 200): never {
    page('Đăng nhập quản trị', '<h1>Đăng nhập quản trị</h1>'
        . ($msg !== '' ? '<p role="status">' . h($msg) . '</p>' : '')
        . '<form method="post" action="admin.php"><input type="hidden" name="action" value="login">'
        . '<label for="password">Mật khẩu</label> '
        . '<input type="password" id="password" name="password" autocomplete="current-password" required autofocus> '
        . '<button type="submit">Đăng nhập</button></form>', $status);
}

try {
    get_config();
} catch (Throwable $e) {
    fail_page('config', $e);
}

start_session('ltt_admin');
$isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
$action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';

// ---------- Login ----------
if (($_SESSION['admin'] ?? false) !== true) {
    if (!$isPost || $action !== 'login') {
        login_page();
    }
    $ip = client_ip();
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $locked = 'Tạm khóa đăng nhập do có quá nhiều lần nhập sai. Vui lòng thử lại sau.';
    try {
        $pdo = get_pdo();
        $pdo->exec('DELETE FROM admin_login_attempts WHERE created_at < NOW() - INTERVAL 30 DAY');
        // Rolling windows; "id <= ?" lets the recount see only failures up to this request's own row,
        // so parallel failures each get a distinct count and exactly one of them hits === 5 / === 20.
        $count = function (string $sql, array $args) use ($pdo): int {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($args);
            return (int) $stmt->fetchColumn();
        };
        $ipSql = 'SELECT COUNT(*) FROM admin_login_attempts WHERE success = 0 AND ip = ? AND created_at > NOW() - INTERVAL 15 MINUTE AND id <= ?';
        $allSql = 'SELECT COUNT(*) FROM admin_login_attempts WHERE success = 0 AND created_at > NOW() - INTERVAL 1 HOUR AND id <= ?';

        // Locked attempts are neither verified nor recorded: counts stop at the threshold, so the alert fires once.
        // ponytail: check-then-insert is not atomic; a burst of parallel requests can overshoot the limit by a few rows.
        if ($count($allSql, [PHP_INT_MAX]) >= 20 || $count($ipSql, [$ip, PHP_INT_MAX]) >= 5) {
            session_write_close();
            login_page($locked, 429);
        }

        if (!password_verify($password, (string) (get_config()['admin_password_hash'] ?? ''))) {
            $pdo->prepare('INSERT INTO admin_login_attempts (ip, success) VALUES (?, 0)')->execute([$ip]);
            $id = (int) $pdo->lastInsertId();
            $ipCount = $count($ipSql, [$ip, $id]);
            $allCount = $count($allSql, [$id]);
        } else {
            $pdo->prepare('INSERT INTO admin_login_attempts (ip, success) VALUES (?, 1)')->execute([$ip]);
        }
    } catch (Throwable $e) {
        fail_page('login', $e);
    }

    $now = time();
    if (isset($ipCount)) {
        session_write_close();
        if ($ipCount === 5 || $allCount === 20) {
            $what = [];
            if ($ipCount === 5) {
                $what[] = 'IP ' . h($ip) . ' bị khóa 15 phút (mở khóa chậm nhất lúc ' . date('H:i:s d/m/Y', $now + 900) . ')';
            }
            if ($allCount === 20) {
                $what[] = 'Toàn bộ form đăng nhập bị khóa 1 giờ (mở khóa chậm nhất lúc ' . date('H:i:s d/m/Y', $now + 3600) . ')';
            }
            send_admin_notification('[Kỷ yếu] Cảnh báo: tạm khóa đăng nhập quản trị',
                '<p><strong>Phát hiện nhiều lần nhập sai mật khẩu quản trị.</strong></p><p>' . implode('<br>', $what)
                . '</p><p>IP gần nhất: ' . h($ip) . '<br>Số lần sai của IP (15 phút): ' . h($ipCount)
                . '<br>Số lần sai toàn hệ thống (1 giờ): ' . h($allCount) . '<br>Thời điểm: ' . date('H:i:s d/m/Y', $now) . '</p>');
        }
        sleep(min(8, 1 << max(0, $ipCount - 1)));
        login_page($ipCount >= 5 || $allCount >= 20 ? 'Mật khẩu không đúng. ' . $locked : 'Mật khẩu không đúng.', 401);
    }

    session_regenerate_id(true);
    $_SESSION = ['admin' => true, 'csrf' => bin2hex(random_bytes(32))];
    session_write_close();
    send_admin_notification('[Kỷ yếu] Đăng nhập quản trị thành công',
        '<p>Có một lượt đăng nhập quản trị thành công.</p><p>IP: ' . h($ip) . '<br>Thời điểm: ' . date('H:i:s d/m/Y', $now)
        . '</p><p>Nếu không phải bạn, hãy đổi mật khẩu (<code>admin_password_hash</code>) ngay.</p>');
    header('Location: admin.php', true, 303);
    exit;
}

// ---------- Authenticated actions (POST + CSRF, then Post/Redirect/Get) ----------
$csrf = (string) ($_SESSION['csrf'] ?? '');
if ($isPost) {
    $token = $_POST['csrf'] ?? '';
    if ($csrf === '' || !is_string($token) || !hash_equals($csrf, $token)) {
        redirect_with('Phiên làm việc không hợp lệ, vui lòng thử lại.');
    }

    if ($action === 'logout') {
        $_SESSION = [];
        $p = session_get_cookie_params();
        setcookie(session_name(), '', ['expires' => 1, 'path' => $p['path'], 'domain' => $p['domain'],
            'secure' => $p['secure'], 'httponly' => $p['httponly'], 'samesite' => $p['samesite']]);
        session_destroy();
        header('Location: admin.php', true, 303);
        exit;
    }

    $busy = 'Có lỗi xảy ra, vui lòng thử lại (hoặc bấm “Làm mới cache”).';
    try {
        if ($action === 'download_backup') {
            $json = json_encode(export_backup_payload(get_pdo()),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            session_write_close();
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="alumni-backup-' . date('Y-m-d') . '.json"');
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
            echo $json;
            exit;
        }
        if ($action === 'rebuild_cache') {
            $n = count(rebuild_cache(get_pdo()));
            redirect_with("Đã làm mới cache ({$n} hồ sơ đang hiển thị).");
        }
        if ($action === 'toggle_delete') {
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $del = filter_var($_POST['is_deleted'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1]]);
            if ($id === false || $del === false) {
                redirect_with('Dữ liệu không hợp lệ.');
            }
            $pdo = get_pdo();
            $stmt = $pdo->prepare('UPDATE alumni_entries SET is_deleted = ? WHERE id = ?');
            $stmt->execute([$del, $id]);
            if ($stmt->rowCount() === 0) {
                redirect_with("Không có thay đổi: bản ghi #{$id} không tồn tại hoặc đã ở trạng thái này.");
            }
            rebuild_cache($pdo);
            redirect_with(($del ? 'Đã ẩn' : 'Đã khôi phục') . " bản ghi #{$id}.");
        }
    } catch (Throwable $e) {
        error_log('[admin] ' . $action . ': ' . $e->getMessage());
        redirect_with($busy);
    }
    redirect_with('Thao tác không hợp lệ.');
}

// ---------- History table ----------
$flash = (string) ($_SESSION['flash'] ?? '');
unset($_SESSION['flash']);
session_write_close();

try {
    $pdo = get_pdo();
    $rows = $pdo->query('SELECT id, email, fullname, graduation_year, education, work, photo_filename, submitted_at, submitter_ip, is_deleted FROM alumni_entries ORDER BY id DESC')->fetchAll();
    // Same "current" rule as fetch_latest_rows() (collation-aware GROUP BY email).
    $current = array_flip($pdo->query('SELECT MAX(id) FROM alumni_entries WHERE is_deleted = 0 GROUP BY email')->fetchAll(PDO::FETCH_COLUMN));
} catch (Throwable $e) {
    fail_page('history', $e);
}

$csrfField = '<input type="hidden" name="csrf" value="' . h($csrf) . '">';
$button = fn(string $action, string $label): string => '<form method="post" action="admin.php">' . $csrfField
    . '<input type="hidden" name="action" value="' . h($action) . '"><button type="submit">' . h($label) . '</button></form>';

ob_start(); ?>
<h1>Quản trị kỷ yếu</h1>
<p><?= h(count($rows)) ?> bản ghi · <?= h(count($current)) ?> hồ sơ đang hiển thị</p>
<nav><?= $button('rebuild_cache', 'Làm mới cache') ?><?= $button('download_backup', 'Tải bản sao lưu (JSON)') ?><?= $button('logout', 'Đăng xuất') ?></nav>
<?php if ($flash !== ''): ?><p role="status"><?= h($flash) ?></p><?php endif ?>
<h2>Lịch sử bản ghi</h2>
<div style="overflow-x:auto"><table>
<thead><tr><th>#</th><th>Thời gian</th><th>Email</th><th>Họ và tên</th><th>Khóa</th><th>Ảnh</th><th>IP</th><th>Trạng thái</th><th>Nội dung</th><th>Thao tác</th></tr></thead>
<tbody>
<?php foreach ($rows as $r): $id = (int) $r['id']; $hidden = (int) $r['is_deleted'] === 1; ?>
<tr>
<td><?= h($id) ?></td>
<td><?= h($r['submitted_at']) ?></td>
<td><?= h($r['email']) ?></td>
<td><?= h($r['fullname']) ?></td>
<td><?= h($r['graduation_year']) ?></td>
<td><img src="<?= h('static/img/' . ($r['photo_filename'] ?: 'default.jpg')) ?>" alt="" width="40" height="50" loading="lazy"></td>
<td><?= h($r['submitter_ip']) ?></td>
<td><?php if ($hidden): ?><del>Đã ẩn</del><?php elseif (isset($current[$id])): ?><mark>Đang hiển thị</mark><?php else: ?><small>Bản cũ</small><?php endif ?></td>
<td><details><summary>Xem</summary><p><strong>Học tập:</strong><br><?= nl2br(h($r['education'])) ?></p><p><strong>Công việc:</strong><br><?= nl2br(h($r['work'])) ?></p></details></td>
<td><form method="post" action="admin.php"<?= $hidden ? '' : ' onsubmit="return confirm(&#039;Ẩn bản ghi #' . h($id) . '?&#039;)"' ?>><?= $csrfField ?><input type="hidden" name="action" value="toggle_delete"><input type="hidden" name="id" value="<?= h($id) ?>"><input type="hidden" name="is_deleted" value="<?= $hidden ? '0' : '1' ?>"><button type="submit"><?= $hidden ? 'Khôi phục' : 'Ẩn' ?></button></form></td>
</tr>
<?php endforeach ?>
</tbody>
</table></div>
<?php page('Quản trị kỷ yếu', (string) ob_get_clean());
