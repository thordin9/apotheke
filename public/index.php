<?php
declare(strict_types=1);

/*
 * Apotheke – front controller / router.
 * Apache: FallbackResource /index.php   ·   Dev: php -S 0.0.0.0:8080 -t public public/index.php
 */

$path = rawurldecode((string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'));

// Built-in dev server: let it serve real static files directly.
if (PHP_SAPI === 'cli-server' && $path !== '/' && is_file(__DIR__ . $path) && !str_ends_with($path, '.php')) {
    return false;
}

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/items.php';
require dirname(__DIR__) . '/src/views.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; img-src 'self' blob: data:; style-src 'self'; script-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'");

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($path === '/healthz') {
    header('Content-Type: text/plain');
    db();
    exit('ok');
}

// Request bodies larger than post_max_size arrive empty – give a clear message.
if ($method === 'POST' && empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $msg = 'Upload too large (max ' . ini_get('post_max_size') . ').';
    if (wants_json()) json_out(['ok' => false, 'error' => $msg], 413);
    init_session();
    view_error(413, $msg);
    exit;
}

init_session();

try {
    /* ---- auth ---- */
    if ($path === '/login') {
        if (app_password() === '') redirect('/');
        if ($method === 'POST') {
            check_csrf();
            if (login((string)($_POST['password'] ?? ''))) redirect('/');
            usleep(400000);
            view_login('Wrong password.');
        } else {
            view_login(null);
        }
        exit;
    }
    if ($path === '/logout' && $method === 'POST') {
        check_csrf();
        logout();
        redirect('/login');
    }
    if (!is_authed()) {
        if (wants_json()) json_out(['ok' => false, 'error' => 'Not signed in', 'redirect' => '/login'], 401);
        if (str_starts_with($path, '/media/')) { http_response_code(401); exit; }
        redirect('/login');
    }

    /* ---- media ---- */
    if (preg_match('#^/media/(full|thumb)/([^/]+)$#D', $path, $m)) {
        serve_image($m[1], $m[2]);
    }

    /* ---- statistics fragment (lazy-loaded when the Overview panel is opened) ---- */
    if ($path === '/stats' && $method === 'GET') {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        echo stats_body_html();
        exit;
    }

    /* ---- list / search ---- */
    if ($path === '/' && $method === 'GET') {
        $q    = clean_line((string)($_GET['q'] ?? ''), 200);
        $f    = ['loc' => filter_value($_GET['loc'] ?? ''), 'cat' => filter_value($_GET['cat'] ?? '')];
        $sort = (string)($_GET['sort'] ?? 'recent');
        $page = max(1, (int)($_GET['page'] ?? 1));
        $res  = items_search($q, $f, $sort, $page);
        if (wants_json()) {
            json_out(['ok' => true, 'html' => cards_html($res['items']), 'total' => $res['total'],
                      'count' => count_text($res['total'], $q !== '' || $f['loc'] !== '' || $f['cat'] !== ''), 'more' => $res['more'], 'page' => $page]);
        }
        header('Cache-Control: no-store');
        $toast = null;
        if (isset($_GET['saved']) && ($s = item_get((int)$_GET['saved']))) $toast = 'Saved “' . $s['name'] . '”';
        if (isset($_GET['deleted'])) $toast = 'Item deleted';
        view_list($q, $f, $sort, $res, locations(), categories(), stats(), $toast, ($_COOKIE['stats_open'] ?? '1') !== '0');
        exit;
    }

    /* ---- add / edit forms ---- */
    if ($path === '/new' && $method === 'GET') {
        header('Cache-Control: no-store');
        $pick = function (string $kind): ?int {
            $v = (string)($_GET[tax($kind)['param']] ?? '');
            return ctype_digit($v) && tax_get($kind, (int)$v) ? (int)$v : tax_default_id($kind);
        };
        $vals = ['name' => '', 'location_id' => $pick('location'), 'new_location' => '', 'category_id' => $pick('category'), 'new_category' => '',
                 'quantity' => 1, 'notes' => ''];
        $toast = isset($_GET['saved']) && ($s = item_get((int)$_GET['saved'])) ? 'Saved “' . $s['name'] . '”' : null;
        view_form(null, $vals, locations(), categories(), null, $toast);
        exit;
    }
    if (preg_match('#^/item/(\d+)(/edit)?$#D', $path, $m) && $method === 'GET') {
        $it = item_get((int)$m[1]);
        if (!$it) { view_error(404, 'Item not found.'); exit; }
        header('Cache-Control: no-store');
        if (!empty($m[2])) view_form($it, $it + ['new_location' => '', 'new_category' => ''], locations(), categories(), null);
        else view_item($it);
        exit;
    }

    /* ---- create / update ---- */
    if ($path === '/save' && $method === 'POST') {
        check_csrf();
        $id = (int)($_POST['id'] ?? 0);
        $existing = $id > 0 ? item_get($id) : null;
        if ($id > 0 && !$existing) throw new UserError('Item not found.', 404);
        $qty = filter_var($_POST['quantity'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1000000]]);
        $vals = [
            'name'     => clean_line((string)($_POST['name'] ?? ''), 200),
            'quantity' => $qty === false ? 1 : $qty,
            'notes'    => mb_substr(trim(str_replace("\r\n", "\n", (string)($_POST['notes'] ?? ''))), 0, 5000),
        ];
        $raw = [];
        foreach (KINDS as $kind) {
            $col = tax($kind)['col'];
            $raw[$kind] = (string)($_POST[$col] ?? '');
            $vals[$col] = ctype_digit($raw[$kind]) ? (int)$raw[$kind] : null;
            $vals['new_' . $kind] = clean_line((string)($_POST['new_' . $kind] ?? ''), 120);
        }
        try {
            if ($vals['name'] === '') throw new UserError('Please give the item a name.');
            foreach (KINDS as $kind) {
                $t = tax($kind);
                if ($vals['new_' . $kind] === '' && $raw[$kind] === '__new') throw new UserError('Type a name for the new ' . $t['one'] . '.');
                if ($vals[$t['col']] !== null && !tax_get($kind, $vals[$t['col']])) throw new UserError('That ' . $t['one'] . ' no longer exists. Please pick another.');
            }
            foreach (KINDS as $kind) {
                if ($vals['new_' . $kind] !== '') {
                    // typed a new entry (inline field / no-JS fallback): create it, or reuse a same-named one
                    $vals[tax($kind)['col']] = tax_create($kind, $vals['new_' . $kind])['id'];
                    $vals['new_' . $kind] = '';
                }
            }
            $file = pick_upload(['photo', 'photo2']);
            $newImage = $file ? image_store($file) : null;
        } catch (UserError $e) {
            if (wants_json()) json_out(['ok' => false, 'error' => $e->getMessage()], 422);
            http_response_code(422);
            view_form($existing, $vals, locations(), categories(), $e->getMessage());
            exit;
        }
        $savedId = item_save($existing, $vals, $newImage, !empty($_POST['remove_image']));
        $after = (string)($_POST['after'] ?? 'view');
        if ($after === 'another') {
            $qs = ['loc' => $vals['location_id'], 'cat' => $vals['category_id'], 'saved' => $savedId];
            redirect('/new?' . http_build_query(array_filter($qs, fn($v) => $v !== null)));
        }
        redirect($existing ? '/item/' . $savedId : '/?saved=' . $savedId);
    }

    /* ---- quantity +/- ---- */
    if (preg_match('#^/item/(\d+)/qty$#D', $path, $m) && $method === 'POST') {
        check_csrf();
        $delta = max(-1000, min(1000, (int)($_POST['delta'] ?? 0)));
        $q = item_adjust_qty((int)$m[1], $delta);
        if ($q === null) throw new UserError('Item not found.', 404);
        if (wants_json()) json_out(['ok' => true, 'quantity' => $q]);
        redirect('/item/' . (int)$m[1]);
    }

    /* ---- delete ---- */
    if (preg_match('#^/item/(\d+)/delete$#D', $path, $m) && $method === 'POST') {
        check_csrf();
        $it = item_get((int)$m[1]);
        if ($it) item_delete($it);
        redirect('/?deleted=1');
    }

    /* ---- locations & categories management ---- */
    if (preg_match('#^/(locations|categories)(?:/(\d+)(?:/(rename|default|delete))?)?$#D', $path, $m)) {
        $kind = $m[1] === 'locations' ? 'location' : 'category';
        $t = tax($kind);
        $noneCount = stats()[$kind === 'location' ? 'unassigned' : 'uncategorized'];
        if (!isset($m[2]) || $m[2] === '') {
            if ($method === 'GET') {
                header('Cache-Control: no-store');
                $toast = match ((string)($_GET['done'] ?? '')) {
                    'added' => $t['One'] . ' added', 'exists' => 'That ' . $t['one'] . ' already exists', 'renamed' => $t['One'] . ' renamed',
                    'default' => 'Default ' . $t['one'] . ' set', 'nodefault' => 'Default cleared', 'deleted' => $t['One'] . ' deleted',
                    default => null,
                };
                view_manage($kind, tax_list($kind), $noneCount, null, $toast);
                exit;
            }
            if ($method !== 'POST') throw new UserError('Page not found.', 404);
            check_csrf();
            try {
                $r = tax_create($kind, (string)($_POST['name'] ?? ''));
            } catch (UserError $e) {
                if (wants_json()) json_out(['ok' => false, 'error' => $e->getMessage()], 422);
                http_response_code(422);
                view_manage($kind, tax_list($kind), $noneCount, $e->getMessage(), null);
                exit;
            }
            if (wants_json()) json_out(['ok' => true, 'id' => $r['id'], 'name' => $r['name'], 'created' => $r['created']]);
            redirect($t['path'] . '?done=' . ($r['created'] ? 'added' : 'exists') . '#' . $t['param'] . '-' . $r['id']);
        }
        $eid = (int)$m[2];
        $act = $m[3] ?? '';
        $entry = tax_get($kind, $eid);
        if (!$entry) throw new UserError($t['One'] . ' not found.', 404);
        if ($act === 'delete' && $method === 'GET') {
            header('Cache-Control: no-store');
            $others = array_values(array_filter(tax_list($kind), fn($l) => (int)$l['id'] !== $eid));
            view_tax_delete($kind, $entry, $others);
            exit;
        }
        if ($method !== 'POST' || $act === '') throw new UserError('Page not found.', 404);
        check_csrf();
        if ($act === 'rename') {
            try {
                tax_rename($kind, $eid, (string)($_POST['name'] ?? ''));
            } catch (UserError $e) {
                if (wants_json()) json_out(['ok' => false, 'error' => $e->getMessage()], 422);
                http_response_code(422);
                view_manage($kind, tax_list($kind), $noneCount, $e->getMessage(), null);
                exit;
            }
            redirect($t['path'] . '?done=renamed#' . $t['param'] . '-' . $eid);
        }
        if ($act === 'default') {
            $on = ($_POST['on'] ?? '1') === '1';
            tax_set_default($kind, $eid, $on);
            redirect($t['path'] . '?done=' . ($on ? 'default' : 'nodefault') . '#' . $t['param'] . '-' . $eid);
        }
        // delete
        $action = (string)($_POST['action'] ?? '');
        $target = null;
        if ($action === 'move') {
            $target = (int)($_POST['target'] ?? 0);
            if ($target === $eid || !tax_get($kind, $target)) throw new UserError('Pick another ' . $t['one'] . ' to move the items to.', 422);
        } elseif ($action !== 'unassign') {
            // No explicit choice: never drop assignments silently – ask first.
            if ((int)$entry['n'] > 0) redirect($t['path'] . '/' . $eid . '/delete');
        }
        tax_delete($kind, $eid, $target);
        redirect($t['path'] . '?done=deleted');
    }

    /* ---- CSV export ---- */
    if ($path === '/export.csv' && $method === 'GET') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="apotheke-' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['id', 'name', 'location', 'category', 'quantity', 'notes', 'image', 'created_at', 'updated_at'], ',', '"', '\\');
        foreach (db()->query("SELECT i.id, i.name, COALESCE(l.name, '') AS location, COALESCE(c.name, '') AS category, i.quantity, i.notes, i.image, i.created_at, i.updated_at
                              " . ITEM_FROM . "
                              ORDER BY l.name IS NULL, l.name COLLATE NATSORT, c.name IS NULL, c.name COLLATE NATSORT, i.name COLLATE NATSORT") as $r) {
            fputcsv($out, array_values($r), ',', '"', '\\');
        }
        exit;
    }

    view_error(404, 'Page not found.');
} catch (UserError $e) {
    $code = $e->getCode() >= 400 ? (int)$e->getCode() : 400;
    if (wants_json()) json_out(['ok' => false, 'error' => $e->getMessage()], $code);
    view_error($code, $e->getMessage());
} catch (Throwable $e) {
    error_log('[apotheke] ' . $e);
    if (wants_json()) json_out(['ok' => false, 'error' => 'Server error'], 500);
    view_error(500, 'Something went wrong on the server.');
}
