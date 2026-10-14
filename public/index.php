<?php
declare(strict_types=1);

/*
 * Apotheke – front controller / router.
 * Apache: FallbackResource /index.php   ·   Dev: php -S 0.0.0.0:9091 -t public public/index.php
 */

$path = rawurldecode((string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'));

// Built-in dev server: serve real static files under public/ only (never ../ outside the docroot).
require_once dirname(__DIR__) . '/src/dev_server.php';
if (PHP_SAPI === 'cli-server' && dev_server_static_file(__DIR__, $path)) {
    return false;
}

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/items.php';
require dirname(__DIR__) . '/src/views.php';
require dirname(__DIR__) . '/src/backup.php';
require dirname(__DIR__) . '/src/settings_views.php';
require dirname(__DIR__) . '/src/stocktake_views.php';
require dirname(__DIR__) . '/src/import_views.php';
require dirname(__DIR__) . '/src/photo_views.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; connect-src 'self' blob:; img-src 'self' blob: data:; style-src 'self'; script-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'");

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'HEAD') {
    $method = 'GET';
}

if ($path === '/healthz') {
    header('Content-Type: text/plain');
    header('X-Apotheke-Version: ' . app_version());
    db();
    exit('ok');
}
if ($path === '/health') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    db();
    exit(json_encode(['status' => 'ok', 'version' => app_version()]));
}
settings_apply_runtime();

/* ---- branding: public (the sign-in page needs them too) ---- */
if ($method === 'GET' && $path === '/theme.css') {
    header('Content-Type: text/css; charset=utf-8');
    header('Cache-Control: ' . (isset($_GET['v']) ? 'public, max-age=31536000, immutable' : 'no-cache'));
    exit(accent_css((string)setting('accent')));
}
if ($method === 'GET' && $path === '/labels.css') {
    header('Content-Type: text/css; charset=utf-8');
    header('Cache-Control: ' . (isset($_GET['v']) ? 'public, max-age=31536000, immutable' : 'no-cache'));
    exit(labels_css());
}
if ($method === 'GET' && $path === '/manifest.webmanifest') {
    header('Content-Type: application/manifest+json');
    header('Cache-Control: no-cache');
    exit(json_encode(manifest_data(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}
if ($method === 'GET' && $path === '/favicon.ico') {
    $own = brand_dir() . '/favicon.ico';
    $f = setting('logo') === 'png' && is_file($own) ? $own : __DIR__ . '/assets/favicon.ico';
    header('Content-Type: image/vnd.microsoft.icon');
    header('Cache-Control: public, max-age=86400');
    header('Content-Length: ' . filesize($f));
    readfile($f);
    exit;
}
if ($method === 'GET' && preg_match('#^/brand/([a-z0-9.\-]+)$#D', $path, $bm)) {
    brand_serve($bm[1]);
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
        if (!auth_enabled() || signed_in()) redirect('/');
        if ($method === 'POST') {
            check_csrf();
            $who = clean_line((string)($_POST['username'] ?? ''), USERNAME_MAX * 2);
            try {
                rate_limit('login');
                if (login($who, (string)($_POST['password'] ?? ''))) { rate_limit_clear('login'); redirect('/'); }
                usleep(400000);
                http_response_code(401);
                view_login('Wrong user name or password.', $who);
            } catch (UserError $e) {
                http_response_code($e->getCode() ?: 403);
                view_login($e->getMessage(), $who);
            }
        } else {
            view_login(null, '', isset($_GET['registered']) ? (string)$_GET['registered'] : null);
        }
        exit;
    }
    if ($path === '/register') {
        if (!registration_open()) { view_error(404, 'Registration is closed – ask an admin for an account.'); exit; }
        if (signed_in()) redirect('/');
        if ($method === 'POST') {
            check_csrf();
            $who = clean_line((string)($_POST['username'] ?? ''), USERNAME_MAX * 2);
            try {
                rate_limit('register');
                $u = user_register($who, (string)($_POST['new'] ?? ''), (string)($_POST['confirm'] ?? ''));
            } catch (UserError $e) {
                http_response_code($e->getCode() ?: 422);
                view_register($e->getMessage(), $who);
                exit;
            }
            if ($u['status'] === 'active') { login_session($u['id']); redirect('/?done=welcome'); }
            redirect('/login?registered=pending');
        }
        no_store();
        view_register(null, '');
        exit;
    }
    if ($path === '/logout' && $method === 'POST') {
        check_csrf();
        logout();
        redirect('/login');
    }
    // every route needs a permission of the user's role (see route_perm() in roles.php)
    $need = route_perm($method, $path, $_POST);
    if (!user_can($need)) {
        $me = current_user();
        if ($me === null || $me['anon']) {
            // not signed in: sign in first (a guest may look but not do this)
            if (wants_json()) json_out(['ok' => false, 'error' => 'Not signed in', 'redirect' => '/login'], 401);
            if (str_starts_with($path, '/media/')) { http_response_code(401); exit; }
            redirect('/login');
        }
        $msg = 'Your role (' . role_name($me['role']) . ') does not allow this' . (isset(PERMISSIONS[$need]) ? ' – it needs the “' . PERMISSIONS[$need][0] . '” permission' : '') . '. Ask an admin.';
        if (wants_json()) json_out(['ok' => false, 'error' => $msg], 403);
        view_error(403, $msg);
        exit;
    }

    /* ---- your account: own password ---- */
    if ($path === '/account') {
        $me = current_user();
        if ($me['guest']) redirect(user_can('users') ? '/settings/security' : '/');
        if ($method === 'POST') {
            check_csrf();
            try {
                if (!user_verify($me['username'], (string)($_POST['current'] ?? ''))) throw new UserError('The current password is not correct.', 403);
                user_set_password($me['id'], (string)($_POST['new'] ?? ''), (string)($_POST['confirm'] ?? ''));
            } catch (UserError $e) {
                http_response_code($e->getCode() ?: 422);
                view_account($me, $e->getMessage(), null);
                exit;
            }
            login_session($me['id']);
            redirect('/account?done=pw');
        }
        no_store();
        view_account($me, null, isset($_GET['done']) ? 'Password changed' : null);
        exit;
    }

    /* ---- theme preference (Auto / Light / Dark) ---- */
    if ($path === '/theme' && $method === 'POST') {
        check_csrf();
        $theme = set_theme_cookie((string)($_POST['theme'] ?? 'auto'));
        if (wants_json()) json_out(['ok' => true, 'theme' => $theme]);
        redirect(safe_back((string)($_POST['back'] ?? '/')));
    }

    /* ---- media ---- */
    if (preg_match('#^/media/(full|thumb)/([^/]+)$#D', $path, $m)) {
        serve_image($m[1], $m[2]);
    }

    /* ---- statistics fragment (lazy-loaded when the Overview panel is opened) ---- */
    if ($path === '/stats' && $method === 'GET') {
        header('Content-Type: text/html; charset=utf-8');
        no_store();
        echo stats_body_html();
        exit;
    }

    /* ---- list / search ---- */
    if ($path === '/' && $method === 'GET') {
        $q    = clean_line((string)($_GET['q'] ?? ''), 200);
        $f    = filters_from($_GET);
        $sort = is_string($_GET['sort'] ?? null) && isset(SORTS[$_GET['sort']]) ? $_GET['sort'] : default_sort();
        $page = max(1, (int)($_GET['page'] ?? 1));
        $res  = items_search($q, $f, $sort, $page);
        if (wants_json()) {
            json_out(['ok' => true, 'html' => cards_html($res['items'], ($_GET['select'] ?? '') === '1'), 'total' => $res['total'],
                      'count' => count_text($res['total'], $q !== '' || filters_active($f)) . search_fixed_note($res['fixed'] ?? []), 'more' => $res['more'], 'page' => $page,
                      'chips' => active_chips_html($f, $sort, locations(), categories()), 'nfilters' => active_filter_count($f, $sort), 'query' => saved_filter_query($_GET)]);
        }
        no_store();
        $toast = null;
        if (isset($_GET['saved']) && ($s = item_get((int)$_GET['saved']))) $toast = 'Saved “' . $s['name'] . '”';
        if (isset($_GET['deleted'])) $toast = 'Item deleted';
        if (isset($_GET['undone'])) $toast = 'Undone';
        if (isset($_GET['bulk_done'])) $toast = bulk_toast((int)$_GET['bulk_done'], (string)($_GET['bulk_act'] ?? ''));
        if (isset($_GET['filter_saved']) && ($sf = saved_filter_get((int)$_GET['filter_saved']))) $toast = 'Saved filter “' . $sf['name'] . '”';
        view_list($q, $f, $sort, $res, locations(), categories(), stats(), $toast, ($_COOKIE['stats_open'] ?? (setting('overview_open') ? '1' : '0')) !== '0',
                  ($_GET['select'] ?? '') === '1', ($_GET['all'] ?? '') === '1');
        exit;
    }

    /* ---- add / edit forms ---- */
    if ($path === '/new' && $method === 'GET') {
        no_store();
        $pick = function (string $kind): ?int {
            $v = (string)($_GET[tax($kind)['param']] ?? '');
            return ctype_digit($v) && tax_get($kind, (int)$v) ? (int)$v : tax_default_id($kind);
        };
        $vals = ['name' => '', 'location_id' => $pick('location'), 'new_location' => '', 'category_id' => $pick('category'), 'new_category' => '',
                 'serial' => '', 'barcode' => barcode_clean((string)($_GET['barcode'] ?? ''))] + new_item_defaults();
        $toast = isset($_GET['saved']) && ($s = item_get((int)$_GET['saved'])) ? 'Saved “' . $s['name'] . '”' : null;
        view_form(null, $vals, locations(), categories(), null, $toast);
        exit;
    }
    if (preg_match('#^/item/(\d+)(/edit)?$#D', $path, $m) && $method === 'GET') {
        $it = item_get((int)$m[1]);
        if (!$it) { view_error(404, 'Item not found.'); exit; }
        no_store();
        if (!empty($m[2])) view_form($it, ['new_location' => '', 'new_category' => '', 'tags' => implode(', ', array_column($it['tags'], 'name')),
            'value' => money_input($it['value_cents']), 'purchase_date' => (string)$it['purchase_date'], 'expires_on' => (string)($it['expires_on'] ?? ''),
            'min_qty' => $it['min_qty'] === null ? '' : (string)$it['min_qty']] + $it, locations(), categories(), null,
            isset($_GET['copied']) ? 'Copy created – edit it here' : null);
        else {
            if (!wants_json()) recent_track((int)$it['id']);
            $hist = activity_list((int)$it['id'], 1, 10);
            $toast = isset($_GET['undone']) ? 'Undone' : null;
            $toast ??= isset($_GET['edited']) ? 'Photo saved as a new version' : (isset($_GET['reordered']) ? 'Photo order saved' : null);
            $toast ??= isset($_GET['merged']) ? 'Merged “' . clean_line((string)$_GET['merged'], 200) . '” into this item' : null;
            view_item($it, photos_for((int)$it['id']), max(0, (int)($_GET['p'] ?? 0)), array_slice($hist, 0, 10), count($hist) > 10, attachments_for((int)$it['id']), $toast);
        }
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
            'quantity' => $qty === false ? 1 : $qty, // placeholder for form redisplay if validation fails
            'notes'    => mb_substr(trim(str_replace("\r\n", "\n", (string)($_POST['notes'] ?? ''))), 0, 5000),
            'tags'     => mb_substr((string)($_POST['tags'] ?? ''), 0, 2000),
            'value'    => clean_line((string)($_POST['value'] ?? ''), 32),
            'purchase_date' => clean_line((string)($_POST['purchase_date'] ?? ''), 10),
            'expires_on' => clean_line((string)($_POST['expires_on'] ?? ''), 10),
            'serial'   => clean_line((string)($_POST['serial'] ?? ''), 100),
            'barcode'  => barcode_clean((string)($_POST['barcode'] ?? '')),
            'min_qty'  => clean_line((string)($_POST['min_qty'] ?? ''), 12),
        ];
        $raw = [];
        foreach (KINDS as $kind) {
            $col = tax($kind)['col'];
            $raw[$kind] = (string)($_POST[$col] ?? '');
            $vals[$col] = ctype_digit($raw[$kind]) ? (int)$raw[$kind] : null;
            // the quick-add box (JS) or, without JavaScript, the <noscript> field next to the dropdown (own name, no duplicates)
            $vals['new_' . $kind] = clean_line((string)($_POST['new_' . $kind] ?? ''), 120)
                ?: clean_line((string)($_POST['new_' . $kind . '_ns'] ?? ''), 120);
        }
        // changing the location of an existing item (or adding a new location on the way) needs "move"
        $locCol = tax('location')['col'];
        if ($existing && ((isset($_POST[$locCol]) && ($vals[$locCol] ?? null) !== ($existing[$locCol] === null ? null : (int)$existing[$locCol])) || $vals['new_location'] !== '')) require_perm('move');
        try {
            if ($vals['name'] === '') throw new UserError('Please give the item a name.');
            if ($qty === false) throw new UserError('Quantity must be a whole number between 0 and 1,000,000.');
            $fields = ['tags' => tags_parse($vals['tags']), 'value_cents' => parse_money($vals['value']),
                       'purchase_date' => parse_date_ymd($vals['purchase_date']), 'expires_on' => parse_expiry_date($vals['expires_on']), 'serial' => $vals['serial'], 'barcode' => $vals['barcode'], 'min_qty' => parse_min_qty($vals['min_qty'])];
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
        $before = $existing ? undo_snapshot([(int)$existing['id']]) : [];
        $savedId = item_save($existing, $fields + $vals, $newImage, !empty($_POST['remove_image']));
        $undo = null;
        if ($before) {
            $strip = fn(array $s) => [array_diff_key($s['row'], ['updated_at' => 1, 'search' => 1, 'image' => 1]), $s['tags']];
            $now = undo_snapshot([$savedId]);
            if ($now && $strip($now[0]) !== $strip($before[0])) $undo = undo_create('edit', 'Saved “' . $vals['name'] . '”', $before);
        }
        $after = (string)($_POST['after'] ?? 'view');
        if ($after === 'another') {
            $qs = ['loc' => $vals['location_id'], 'cat' => $vals['category_id'], 'saved' => $savedId];
            redirect('/new?' . http_build_query(array_filter($qs, fn($v) => $v !== null)));
        }
        redirect($existing ? '/item/' . $savedId . ($undo ? '?undo=' . $undo : '') : '/?saved=' . $savedId);
    }

    /* ---- photos: edit (crop / rotate) and order ---- */
    if (preg_match('#^/item/(\d+)/photos/(\d+)/edit$#D', $path, $m)) {
        $it = item_get((int)$m[1]) ?? throw new UserError('Item not found.', 404);
        $ph = photo_find((int)$m[1], (int)$m[2]);
        if ($method === 'POST') {
            check_csrf();
            $pct = fn(string $k, float $d) => is_numeric($_POST['crop_' . $k] ?? null) ? (float)$_POST['crop_' . $k] / 100 : $d;
            try {
                $r = photo_edit((int)$it['id'], $ph['id'], (int)($_POST['rotate'] ?? 0), [$pct('x', 0), $pct('y', 0), $pct('w', 1), $pct('h', 1)]);
            } catch (UserError $e) {
                if (wants_json()) json_out(['ok' => false, 'error' => $e->getMessage()], $e->getCode() ?: 422);
                http_response_code($e->getCode() ?: 422);
                view_photo_edit($it, $ph, count(photos_for((int)$it['id'])), $e->getMessage());
                exit;
            }
            if (wants_json()) json_out(['ok' => true] + $r);
            redirect('/item/' . $it['id'] . '?p=' . $ph['index'] . '&edited=1#photos');
        }
        no_store();
        view_photo_edit($it, $ph, count(photos_for((int)$it['id'])), null);
        exit;
    }
    if (preg_match('#^/item/(\d+)/photos/order$#D', $path, $m) && $method === 'POST') {
        check_csrf();
        if (!item_get((int)$m[1])) throw new UserError('Item not found.', 404);
        $ids = is_array($_POST['ids'] ?? null) ? $_POST['ids'] : preg_split('/\s*,\s*/', (string)($_POST['ids'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        photo_reorder((int)$m[1], $ids ?: []);
        if (wants_json()) json_out(['ok' => true]);
        redirect('/item/' . (int)$m[1] . '?p=0#photos');
    }

    /* ---- photos: add / cover / reorder / delete ---- */
    if (preg_match('#^/item/(\d+)/photos(?:/(\d+)/(cover|left|right|delete))?$#D', $path, $m) && $method === 'POST') {
        check_csrf();
        $iid = (int)$m[1];
        if (!item_get($iid)) throw new UserError('Item not found.', 404);
        if (empty($m[2])) {
            $files = pick_uploads(['photos', 'photo']);
            if (!$files) throw new UserError('Choose a photo to add.', 422);
            if (count(photos_for($iid)) + count($files) > max_photos()) throw new UserError('An item can have at most ' . max_photos() . ' photos.', 422);
            $added = 0;
            foreach ($files as $f) {
                try {
                    $name = image_store($f);
                } catch (UserError $e) {
                    throw new UserError($e->getMessage(), 422);
                }
                photo_add($iid, $name);
                $added++;
            }
            if (wants_json()) json_out(['ok' => true, 'added' => $added, 'count' => count(photos_for($iid))]);
            redirect('/item/' . $iid . '?p=' . (count(photos_for($iid)) - 1) . '#photos');
        }
        $idx = photo_action($iid, (int)$m[2], $m[3]);
        if (wants_json()) json_out(['ok' => true, 'index' => $idx, 'count' => count(photos_for($iid))]);
        redirect('/item/' . $iid . '?p=' . max(0, $idx) . '#photos');
    }

    /* ---- attachments: upload / delete / download ---- */
    if (preg_match('#^/item/(\d+)/attachments(?:/(\d+)/delete)?$#D', $path, $m) && $method === 'POST') {
        check_csrf();
        $iid = (int)$m[1];
        if (empty($m[2])) {
            $n = attachments_add($iid, pick_uploads(['files', 'file']));
            if (wants_json()) json_out(['ok' => true, 'added' => $n, 'count' => count(attachments_for($iid))]);
        } else {
            attachment_delete($iid, (int)$m[2]);
            if (wants_json()) json_out(['ok' => true, 'count' => count(attachments_for($iid))]);
        }
        redirect('/item/' . $iid . '#attachments');
    }
    if (preg_match('#^/attachment/(\d+)$#D', $path, $m) && $method === 'GET') {
        $a = attachment_get((int)$m[1]);
        if (!$a) { http_response_code(404); exit('Not found'); }
        attachment_serve($a);
    }

    /* ---- quantity +/- ---- */
    if (preg_match('#^/item/(\d+)/qty$#D', $path, $m) && $method === 'POST') {
        check_csrf();
        $delta = max(-1000, min(1000, (int)($_POST['delta'] ?? 0)));
        $q = item_adjust_qty((int)$m[1], $delta);
        if ($q === null) throw new UserError('Item not found.', 404);
        $row = item_get((int)$m[1]);
        if (wants_json()) json_out(['ok' => true, 'quantity' => $q, 'low' => $row !== null && is_low($row)]);
        redirect('/item/' . (int)$m[1]);
    }

    /* ---- duplicate ---- */
    if (preg_match('#^/item/(\d+)/duplicate$#D', $path, $m) && $method === 'POST') {
        check_csrf();
        $new = item_duplicate((int)$m[1], ($_POST['photos'] ?? '') === '1');
        redirect('/item/' . $new . '/edit?copied=1');
    }

    /* ---- lending ---- */
    if (preg_match('#^/item/(\d+)/(lend|return)$#D', $path, $m) && $method === 'POST') {
        check_csrf();
        $iid = (int)$m[1];
        if (!item_get($iid)) throw new UserError('Item not found.', 404);
        if ($m[2] === 'lend') {
            try {
                item_lend($iid, (string)($_POST['lent_to'] ?? ''), parse_lend_date((string)($_POST['lent_at'] ?? ''), 'Lent date'),
                          parse_lend_date((string)($_POST['lent_due'] ?? ''), 'Due date'));
            } catch (UserError $e) {
                throw new UserError($e->getMessage(), 422);
            }
        } else {
            item_return($iid);
        }
        redirect(($_POST['back'] ?? '') === '/lent' ? '/lent' : '/item/' . $iid . '#lending');
    }
    if ($path === '/lent' && $method === 'GET') {
        no_store();
        view_lent(items_lent(), local_today());
        exit;
    }

    /* ---- bulk actions on selected items ---- */
    if ($path === '/bulk' && $method === 'POST') {
        check_csrf();
        $ids    = array_slice(array_map('intval', (array)($_POST['ids'] ?? [])), 0, BULK_MAX + 1);
        $action = (string)($_POST['action'] ?? '');
        $back   = (string)($_POST['back'] ?? '');
        if (!preg_match('#^/\?[^\r\n]*$#D', $back)) $back = '/?select=1';
        $back   = preg_replace('/&?(?:bulk_(?:done|act)|undo|undone)=[^&]*/', '', $back);
        $taxId  = function (string $k): ?int { $v = (string)($_POST[$k] ?? ''); return ctype_digit($v) ? (int)$v : null; };
        require_perm(bulk_perm($action));
        if ($action === 'labels') {
            $ids = array_values(array_unique(array_filter($ids, fn($i) => $i > 0)));
            if (!$ids) throw new UserError('Select at least one item.', 422);
            if (count($ids) > BULK_MAX) throw new UserError('Select at most ' . BULK_MAX . ' items at once.', 422);
            redirect('/labels?ids=' . implode(',', $ids));
        }
        try {
            if ($action === 'delete' && ($_POST['confirm'] ?? '') !== '1') {
                $items = array_values(array_filter(array_map('item_get', array_values(array_unique(array_filter($ids, fn($i) => $i > 0))))));
                if (!$items) throw new UserError('Select at least one item.');
                if (count($items) > BULK_MAX) throw new UserError('Select at most ' . BULK_MAX . ' items at once.');
                no_store();
                view_bulk_delete($items, $back);
                exit;
            }
            $snap = undo_enabled() && in_array($action, UNDO_ACTIONS, true) && count($ids) <= BULK_MAX ? undo_snapshot(array_unique(array_filter($ids, fn($i) => $i > 0))) : [];
            $n = items_bulk($ids, $action, ['location_id' => $taxId('location_id'), 'category_id' => $taxId('category_id'),
                'tags' => tags_parse((string)($_POST['tags'] ?? '')), 'tag_id' => (int)($_POST['tag_id'] ?? 0), 'keep_files' => $action === 'delete' && $snap]);
        } catch (UserError $e) {
            throw new UserError($e->getMessage(), 422);
        }
        $undo = $n ? undo_create($action === 'delete' ? 'delete' : $action, bulk_toast($n, $action), $snap, ['back' => $back], $action === 'delete' ? undo_snapshot_files($snap) : []) : null;
        redirect($back . (str_contains($back, '?') ? '&' : '?') . 'bulk_done=' . $n . '&bulk_act=' . rawurlencode($action) . ($undo ? '&undo=' . $undo : ''));
    }

    /* ---- merge duplicates ---- */
    if (preg_match('#^/item/(\d+)/merge$#D', $path, $m)) {
        $it = item_get((int)$m[1]);
        if (!$it) throw new UserError('Item not found.', 404);
        if ($method === 'POST') {
            check_csrf();
            $into = (int)($_POST['into'] ?? 0);
            if (($_POST['confirm'] ?? '') !== '1') redirect('/item/' . (int)$it['id'] . '/merge?into=' . $into);
            $p = item_merge((int)$it['id'], $into);
            if (wants_json()) json_out(['ok' => true, 'id' => $into, 'quantity' => (int)$p['result']['quantity']]);
            redirect('/item/' . $into . '?merged=' . rawurlencode((string)$it['name']));
        }
        if ($method === 'GET') {
            no_store();
            $into = (string)($_GET['into'] ?? '');
            if (ctype_digit($into)) { view_merge_confirm(merge_preview((int)$it['id'], (int)$into)); exit; }
            $q = clean_line((string)($_GET['q'] ?? ''), 200);
            view_merge_pick($it, merge_candidates($it, $q), $q);
            exit;
        }
    }

    /* ---- favourites / recently viewed ---- */
    if (preg_match('#^/item/(\d+)/fav$#D', $path, $m) && $method === 'POST') {
        check_csrf();
        $id = (int)$m[1];
        $it = item_get($id);
        if (!$it) throw new UserError('Item not found.', 404);
        $on = isset($_POST['on']) ? (string)$_POST['on'] === '1' : empty($it['favourite']);
        fav_set($id, $on);
        if (wants_json()) json_out(['ok' => true, 'favourite' => $on, 'count' => fav_count()]);
        $back = (string)($_POST['back'] ?? '');
        redirect(preg_match('#^/(\?[^\r\n]*)?$|^/item/\d+$#D', $back) ? $back : '/item/' . $id);
    }
    if ($path === '/recent/clear' && $method === 'POST') {
        check_csrf();
        recent_clear();
        redirect('/');
    }

    /* ---- undo ---- */
    if ($path === '/undo' && $method === 'POST') {
        check_csrf();
        undo_prune();
        $r = undo_apply((string)($_POST['token'] ?? ''));
        $n = count($r['ids']);
        if (wants_json()) json_out(['ok' => true, 'action' => $r['action'], 'ids' => $r['ids']]);
        if ($r['back'] !== '' && preg_match('#^/\?[^\r\n]*$#D', $r['back'])) redirect($r['back'] . '&undone=' . $n);
        redirect($n === 1 ? '/item/' . $r['ids'][0] . '?undone=1' : '/?undone=' . $n);
    }

    /* ---- delete ---- */
    if (preg_match('#^/item/(\d+)/delete$#D', $path, $m) && $method === 'POST') {
        check_csrf();
        $it = item_get((int)$m[1]);
        if (!$it) throw new UserError('Item not found.', 404);
        $snap = undo_enabled() ? undo_snapshot([(int)$it['id']]) : [];
        item_delete($it, (bool)$snap);
        $undo = undo_create('delete', 'Deleted “' . $it['name'] . '”', $snap, [], undo_snapshot_files($snap));
        redirect('/?deleted=1' . ($undo ? '&undo=' . $undo : ''));
    }

    /* ---- control panel ---- */
    if ($path === '/settings' && $method === 'GET') {
        no_store();
        view_settings_home(null);
        exit;
    }
    if ($path === '/settings/export.json' && $method === 'GET') {
        no_store();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="apotheke-settings-' . local_today() . '.json"');
        exit(json_encode(settings_export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
    if ($path === '/settings/import' && $method === 'POST') {
        check_csrf();
        $f = $_FILES['file'] ?? null;
        $json = $f && ($f['error'] ?? 1) === UPLOAD_ERR_OK ? (string)file_get_contents($f['tmp_name']) : (string)($_POST['json'] ?? '');
        try {
            if (strlen($json) > 256 * 1024) throw new UserError('That settings file is too large.');
            $r = settings_import($json);
        } catch (UserError $e) {
            http_response_code(422);
            view_settings_section('data', [], ['_' => $e->getMessage()], null);
            exit;
        }
        $notes = [];
        foreach ($r['errors'] as $k => $msg) $notes[] = "Skipped $k: $msg";
        if ($r['ignored']) $notes[] = 'Ignored unknown or protected keys: ' . implode(', ', $r['ignored']);
        if ($r['token_cleared']) $notes[] = 'The AI autofill token was cleared because the service address changed: enter it again under AI autofill.';
        view_settings_section('data', [], [], 'Imported ' . $r['applied'] . ' setting' . ($r['applied'] === 1 ? '' : 's'), $notes);
        exit;
    }
    if ($path === '/settings/logo' && $method === 'POST') {
        check_csrf();
        try {
            brand_store($_FILES['logo'] ?? ['error' => UPLOAD_ERR_NO_FILE]);
        } catch (UserError $e) {
            http_response_code(422);
            view_settings_section('appearance', [], [], null, [$e->getMessage()]);
            exit;
        }
        redirect('/settings/appearance?done=logo');
    }
    if ($path === '/settings/logo/reset' && $method === 'POST') {
        check_csrf();
        brand_reset();
        redirect('/settings/appearance?done=logo-reset');
    }
    /* ---- users & sign-in (admins only, see route_perm) ---- */
    if ($path === '/settings/password' && $method === 'POST') {
        // turn sign-in on (creating the first admin when there is none) or off
        check_csrf();
        $mode = (string)($_POST['mode'] ?? '') === 'disable' ? 'disable' : 'change';
        $me = current_user();
        try {
            if ($mode === 'disable') {
                if (!$me['guest'] && !user_verify($me['username'], (string)($_POST['current'] ?? ''))) throw new UserError('The current password is not correct.', 403);
                auth_turn_off();
            } elseif (!$me['guest']) {
                // signed in: change your own password
                if (!user_verify($me['username'], (string)($_POST['current'] ?? ''))) throw new UserError('The current password is not correct.', 403);
                user_set_password($me['id'], (string)($_POST['new'] ?? ''), (string)($_POST['confirm'] ?? ''));
                login_session($me['id']);
            } else {
                $name = clean_line((string)($_POST['username'] ?? 'admin'), USERNAME_MAX * 2) ?: 'admin';
                $u = user_by_name($name);
                if ($u) {
                    // an account exists already: sign in as that admin to turn sign-in back on
                    if ($u['role'] !== 'admin' || !user_verify($name, (string)($_POST['new'] ?? ''))) throw new UserError('Enter the password of the admin “' . $name . '” to turn sign-in on again.', 403);
                    $uid = $u['id'];
                } else {
                    $uid = db_tx(fn() => user_create($name, (string)($_POST['new'] ?? ''), 'admin', (string)($_POST['confirm'] ?? '')));
                }
                auth_turn_on();
                login_session($uid);
            }
        } catch (UserError $e) {
            http_response_code($e->getCode() ?: 422);
            view_settings_section('security', [], ['password' => $e->getMessage()], null);
            exit;
        }
        redirect('/settings/security?done=' . ($mode === 'disable' ? 'pw-off' : 'pw'));
    }
    if (preg_match('#^/settings/users(?:/(\d+)(?:/(role|password|rename|delete|approve))?)?$#D', $path, $m) && $method === 'POST') {
        check_csrf();
        $me = current_user();
        $uid = isset($m[1]) && $m[1] !== '' ? (int)$m[1] : 0;
        $act = $m[2] ?? '';
        try {
            if ($uid === 0) {
                user_create((string)($_POST['username'] ?? ''), (string)($_POST['new'] ?? ''), (string)($_POST['role'] ?? ''), (string)($_POST['confirm'] ?? ''));
                $done = 'user-added';
            } elseif ($act === 'role') {
                user_set_role($uid, (string)($_POST['role'] ?? ''));
                $done = 'user-role';
            } elseif ($act === 'password') {
                user_set_password($uid, (string)($_POST['new'] ?? ''), (string)($_POST['confirm'] ?? ''));
                if ($uid === $me['id']) login_session($uid);
                $done = 'user-pw';
            } elseif ($act === 'rename') {
                user_rename($uid, (string)($_POST['username'] ?? ''));
                $done = 'user-renamed';
            } elseif ($act === 'approve') {
                user_approve($uid);
                $done = 'user-approved';
            } elseif ($act === 'delete') {
                if ($uid === $me['id']) throw new UserError('You cannot delete your own account while signed in with it.', 409);
                user_delete($uid);
                $done = 'user-deleted';
            } else {
                throw new UserError('Page not found.', 404);
            }
        } catch (UserError $e) {
            http_response_code($e->getCode() ?: 422);
            view_settings_section('security', [], ['users' => $e->getMessage()], null);
            exit;
        }
        redirect('/settings/security?done=' . $done . '#users');
    }
    /* ---- roles & permissions (users permission, see route_perm) ---- */
    if (preg_match('#^/settings/roles(?:/([a-z0-9-]+)(?:/(delete))?)?$#D', $path, $m) && $method === 'POST') {
        check_csrf();
        $key = $m[1] ?? '';
        $perms = array_values(array_filter((array)($_POST['perms'] ?? []), 'is_string'));
        try {
            if ($key === '') { $key = role_create((string)($_POST['name'] ?? ''), $perms); $done = 'role-added'; }
            elseif (($m[2] ?? '') === 'delete') { role_delete($key, (string)($_POST['move_to'] ?? 'viewer')); $done = 'role-deleted'; $key = ''; }
            else { role_update($key, isset($_POST['name']) ? (string)$_POST['name'] : null, $perms); $done = 'role-saved'; }
        } catch (UserError $e) {
            if (wants_json()) json_out(['ok' => false, 'error' => $e->getMessage()], $e->getCode() ?: 422);
            http_response_code($e->getCode() ?: 422);
            view_settings_section('security', [], ['roles' => $e->getMessage()], null);
            exit;
        }
        if (wants_json()) json_out(['ok' => true, 'key' => $key]);
        redirect('/settings/security?done=' . $done . '#' . ($key !== '' ? 'rc-' . $key : 'roles'));
    }
    if ($path === '/autofill/suggest' && $method === 'POST') {
        check_csrf();
        no_store();
        $f = $_FILES['image'] ?? null;
        if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) {
            json_out(['ok' => false, 'code' => 'no_image', 'message' => 'No photo.']);
        }
        if ((int)$f['size'] > max_upload()) json_out(['ok' => false, 'code' => 'too_large', 'message' => 'No suggestions: the photo is too large.']);
        $r = autofill_suggest((string)file_get_contents((string)$f['tmp_name']));
        json_out($r + ['error' => $r['ok'] ? null : $r['message']]);
    }
    if ($path === '/settings/autofill/token' && $method === 'POST') {
        check_csrf();
        if (($_POST['action'] ?? '') === 'clear') {
            settings_store(['autofill_token' => '']);
            redirect('/settings/autofill?done=token-cleared');
        }
        $t = trim((string)($_POST['token'] ?? ''));
        if (!preg_match('/^[\x21-\x7E]{1,512}$/D', $t)) {
            http_response_code(422);
            view_settings_section('autofill', [], ['autofill_token' => 'Token: 1–512 printable characters, no spaces.'], null);
            exit;
        }
        settings_store(['autofill_token' => $t]);
        redirect('/settings/autofill?done=token-saved');
    }
    if ($path === '/settings/autofill/test' && $method === 'POST') {
        check_csrf();
        $url = (string)($_POST['autofill_url'] ?? setting('autofill_url'));
        $to = (string)($_POST['autofill_timeout'] ?? '');
        $timeout = ctype_digit($to) ? max(3, min(60, (int)$to)) : (int)setting('autofill_timeout');
        $badUrl = false;
        try { $url = (string)setting_validate('autofill_url', $url); } catch (UserError $e) { $badUrl = trim($url) !== ''; $url = ''; }
        // The stored token only ever goes to the stored address; a typed, unsaved address gets the token typed
        // in the form (if any) or none, so the write-only token cannot be sent to a host of the caller's choice.
        $r = $badUrl
            ? ['ok' => false, 'code' => 'invalid_url', 'message' => 'That is not a valid service address. Only http:// and https:// addresses are supported, e.g. http://192.168.1.10:8080.']
            : autofill_test($url, $timeout, autofill_test_token($url, (string)($_POST['autofill_token'] ?? '')));
        if (wants_json()) json_out($r + ['html' => autofill_test_result_html($r)]);
        no_store();
        $vals = [];
        foreach (settings_section_keys('autofill') as $k) if (isset($_POST[$k])) $vals[$k] = $_POST[$k];
        if (isset($vals['autofill_enabled']) && is_array($_POST['autofill_enabled'] ?? null)) unset($vals['autofill_enabled']);
        view_settings_section('autofill', $vals, [], null, [], $r);
        exit;
    }
    if (preg_match('#^/settings/([a-z]+)(/reset)?$#D', $path, $m) && isset(SETTINGS_SECTIONS[$m[1]])) {
        $section = $m[1];
        if ($method === 'GET' && empty($m[2])) {
            no_store();
            $toast = match ((string)($_GET['done'] ?? '')) {
                'saved' => 'Settings saved', 'reset' => 'Section reset to defaults', 'logo' => 'Logo updated',
                'logo-reset' => 'Default logo restored', 'pw' => 'Password saved', 'pw-off' => 'Sign-in turned off',
                'user-added' => 'User added', 'user-role' => 'Role changed', 'user-pw' => 'Password set', 'user-renamed' => 'User renamed', 'user-deleted' => 'User deleted', 'user-approved' => 'Account approved',
                'token-saved' => 'Token saved', 'token-cleared' => 'Token cleared',
                'saved-token-cleared' => 'Settings saved. The token was cleared because the service address changed: enter it again.',
                'reset-token-cleared' => 'Section reset to defaults. The token was cleared because the service address changed: enter it again.', 'role-added' => 'Role added', 'role-saved' => 'Role saved', 'role-deleted' => 'Role deleted', default => null,
            };
            view_settings_section($section, [], [], $toast);   // accent warnings: shown in the preview box
            exit;
        }
        if ($method === 'POST') {
            check_csrf();
            if (!empty($m[2])) {
                settings_token_cleared(false);
                settings_reset_section($section);
                $afTokenCleared = settings_token_cleared();
                redirect("/settings/$section?done=" . ($afTokenCleared ? 'reset-token-cleared' : 'reset'));
            }
            $raw = [];
            foreach (settings_section_keys($section) as $k) $raw[$k] = $_POST[$k] ?? '';
            $afNewToken = $section === 'autofill' ? trim((string)($_POST['autofill_token'] ?? '')) : '';
            if ($afNewToken !== '' && !preg_match('/^[\x21-\x7E]{1,512}$/D', $afNewToken)) {
                http_response_code(422);
                view_settings_section($section, $raw, ['autofill_token' => 'Token: 1–512 printable characters, no spaces.'], null);
                exit;
            }
            // The stored token belongs to the saved address: settings_store() drops it when the address moves, so it can
            // never follow the address to a host of the admin's choosing. A token entered in the same save replaces it.
            $errors = settings_save($raw, $afNewToken !== '' ? ['autofill_token' => $afNewToken] : []);
            $afTokenCleared = !$errors && settings_token_cleared();
            if (!$errors && $section === 'items') {
                foreach (KINDS as $kind) {
                    $v = (string)($_POST['default_' . $kind] ?? '');
                    $cur = tax_default_id($kind);
                    if ($v === '' && $cur !== null) tax_set_default($kind, $cur, false);
                    elseif (ctype_digit($v) && tax_get($kind, (int)$v) && (int)$v !== $cur) tax_set_default($kind, (int)$v, true);
                }
            }
            if ($errors) {
                http_response_code(422);
                view_settings_section($section, $raw, $errors, null);
                exit;
            }
            redirect("/settings/$section?done=" . ($afTokenCleared ? 'saved-token-cleared' : 'saved'));
        }
    }

    /* ---- barcodes ---- */
    if ($path === '/barcode' && $method === 'GET') {
        if (isset($_GET['code']) && is_string($_GET['code']) && barcode_clean($_GET['code']) !== '') {
            redirect('/barcode/' . rawurlencode(barcode_clean($_GET['code'])));
        }
        no_store();
        view_barcode();
        exit;
    }
    if (str_starts_with($path, '/barcode/') && $method === 'GET') {   // $path is already URL-decoded; codes may contain "/"
        no_store();
        redirect(barcode_target(substr($path, strlen('/barcode/'))));
    }

    /* ---- saved filters ---- */
    if ($path === '/filters' && $method === 'GET') {
        no_store();
        $toast = match ((string)($_GET['done'] ?? '')) { 'renamed' => 'Filter renamed', 'deleted' => 'Filter deleted', default => null };
        view_saved_filters(saved_filters(), null, $toast);
        exit;
    }
    if ($path === '/filters' && $method === 'POST') {
        check_csrf();
        $query = saved_filter_query($_POST);
        try {
            $sid = saved_filter_create((string)($_POST['name'] ?? ''), $query);
        } catch (UserError $e) {
            if (wants_json()) json_out(['ok' => false, 'error' => $e->getMessage()], $e->getCode() ?: 422);
            throw $e;
        }
        if (wants_json()) json_out(['ok' => true, 'id' => $sid, 'html' => saved_filters_html(saved_filters(), $query)]);
        redirect('/?' . ($query !== '' ? $query . '&' : '') . 'filter_saved=' . $sid);
    }
    if (preg_match('#^/filters/(\d+)/(rename|delete)$#D', $path, $m) && $method === 'POST') {
        check_csrf();
        $sid = (int)$m[1];
        try {
            $m[2] === 'rename' ? saved_filter_rename($sid, (string)($_POST['name'] ?? '')) : saved_filter_delete($sid);
        } catch (UserError $e) {
            if ($e->getCode() === 404) throw $e;
            http_response_code($e->getCode() ?: 422);
            view_saved_filters(saved_filters(), $e->getMessage(), null);
            exit;
        }
        redirect('/filters?done=' . ($m[2] === 'rename' ? 'renamed' : 'deleted'));
    }

    /* ---- locations & categories management ---- */
    if (preg_match('#^/(locations|categories)(?:/(\d+)(?:/(rename|default|delete|parent))?)?$#D', $path, $m)) {
        $kind = $m[1] === 'locations' ? 'location' : 'category';
        $t = tax($kind);
        $noneCount = stats()[$kind === 'location' ? 'unassigned' : 'uncategorized'];
        if (!isset($m[2]) || $m[2] === '') {
            if ($method === 'GET') {
                no_store();
                $toast = match ((string)($_GET['done'] ?? '')) {
                    'added' => $t['One'] . ' added', 'exists' => 'That ' . $t['one'] . ' already exists', 'renamed' => $t['One'] . ' renamed',
                    'default' => 'Default ' . $t['one'] . ' set', 'nodefault' => 'Default cleared', 'deleted' => $t['One'] . ' deleted',
                    'moved' => $t['One'] . ' moved',
                    default => null,
                };
                view_manage($kind, tax_list($kind), $noneCount, null, $toast);
                exit;
            }
            if ($method !== 'POST') throw new UserError('Page not found.', 404);
            check_csrf();
            try {
                $parent = $kind === 'location' && ctype_digit((string)($_POST['parent'] ?? '')) ? (int)$_POST['parent'] : null;
                if ($parent !== null && !tax_get('location', $parent)) throw new UserError('That parent location no longer exists.');
                $r = db_tx(function () use ($kind, $parent): array {
                    $r = tax_create($kind, (string)($_POST['name'] ?? ''));
                    // an existing location keeps its place; a new one goes where it was asked for
                    if ($r['created'] && $parent !== null) location_set_parent($r['id'], $parent);
                    return $r;
                });
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
            no_store();
            $all = tax_list($kind);
            $others = array_values(array_filter($all, fn($l) => (int)$l['id'] !== $eid));
            if ($kind === 'location') {
                $entry['children'] = count(array_filter($all, fn($l) => $l['parent_id'] === $eid));
                $me = array_values(array_filter($all, fn($l) => $l['id'] === $eid))[0] ?? null;
                $entry['parent_name'] = $me && $me['parent_id'] !== null ? location_path($me['parent_id']) : '';
            }
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
        if ($act === 'parent') {
            if ($kind !== 'location') throw new UserError('Page not found.', 404);
            $raw = (string)($_POST['parent'] ?? '');
            if ($raw !== '' && !ctype_digit($raw)) throw new UserError('Pick a location.', 422);
            try {
                location_set_parent($eid, $raw === '' ? null : (int)$raw);
            } catch (UserError $e) {
                if (wants_json()) json_out(['ok' => false, 'error' => $e->getMessage()], 422);
                http_response_code(422);
                view_manage($kind, tax_list($kind), $noneCount, $e->getMessage(), null);
                exit;
            }
            if (wants_json()) json_out(['ok' => true, 'path' => location_path($eid)]);
            redirect($t['path'] . '?done=moved#' . $t['param'] . '-' . $eid);
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

    /* ---- QR labels ---- */
    /* ---- stocktake ---- */
    if ($path === '/stocktake') {
        if ($method === 'POST') {
            check_csrf();
            $loc = (string)($_POST['location_id'] ?? '');
            if (!ctype_digit($loc)) throw new UserError('Choose a location to check.', 422);
            redirect('/stocktake/' . stocktake_start((int)$loc));
        }
        no_store();
        $pre = ctype_digit((string)($_GET['loc'] ?? '')) ? (int)$_GET['loc'] : null;
        view_stocktakes(stocktake_list(), $pre, match ((string)($_GET['done'] ?? '')) { 'cancelled' => 'Stocktake cancelled', default => null });
        exit;
    }
    if (preg_match('#^/stocktake/(\d+)(?:/(check|report|finish|cancel))?$#D', $path, $m)) {
        $s = stocktake_get((int)$m[1]);
        if (!$s) throw new UserError('Stocktake not found.', 404);
        $act = $m[2] ?? '';
        if ($method === 'GET' && ($act === '' || $act === 'report')) {
            no_store();
            if ($act === '' && $s['status'] === 'open') view_stocktake($s);
            else view_stocktake_report($s, isset($_GET['finished']) ? 'Stocktake finished' . ((int)$_GET['finished'] > 0 ? ' · ' . item_noun((int)$_GET['finished']) . ' moved' : '') : null);
            exit;
        }
        if ($method !== 'POST' || $act === '' || $act === 'report') throw new UserError('Page not found.', 404);
        check_csrf();
        if ($act === 'cancel') { stocktake_cancel($s['id']); redirect('/stocktake?done=cancelled'); }
        if ($act === 'finish') { if (($_POST['moves'] ?? '') === '1') require_perm('move'); $n = stocktake_finish($s['id'], ($_POST['moves'] ?? '') === '1'); redirect('/stocktake/' . $s['id'] . '/report?finished=' . $n); }
        // check: by item id (tick in the list) or by a scanned / typed code
        $found = ($_POST['found'] ?? '1') !== '0';
        try {
            $iid = ctype_digit((string)($_POST['item_id'] ?? '')) ? (int)$_POST['item_id'] : stocktake_resolve_code((string)($_POST['code'] ?? ''));
            $r = stocktake_check($s['id'], $iid, $found);
        } catch (UserError $e) {
            if (wants_json()) json_out(['ok' => false, 'error' => $e->getMessage()], $e->getCode() ?: 422);
            http_response_code($e->getCode() >= 400 ? $e->getCode() : 422);
            view_stocktake($s, $e->getMessage());
            exit;
        }
        $msg = match ($r['state']) {
            'found' => 'Found “' . $r['name'] . '”', 'missing' => 'Unticked “' . $r['name'] . '”',
            'misplaced' => '“' . $r['name'] . '” belongs in ' . ($r['home'] !== '' ? $r['home'] : 'no location') . ' – marked as misplaced',
            default => 'Removed “' . $r['name'] . '”',
        };
        if (wants_json()) json_out(['ok' => true, 'state' => $r['state'], 'message' => $msg, 'html' => stocktake_body_html(stocktake_get($s['id']))]);
        redirect('/stocktake/' . $s['id']);
    }

    if ($path === '/labels' && $method === 'GET') {
        no_store();
        $locs = locations();
        $locLabel = fn($l) => ['title' => $l['name'], 'sub' => item_noun((int)($l['total'] ?? $l['n'])), 'path' => '/?loc=' . (int)$l['id']];
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($_GET['ids'] ?? ''))), fn($i) => $i > 0)));
        if ($ids) {
            if (count($ids) > BULK_MAX) throw new UserError('Print at most ' . BULK_MAX . ' labels at once.', 422);
            $labels = [];
            foreach ($ids as $id) if ($it = item_get($id)) $labels[] = ['title' => $it['name'], 'sub' => (string)$it['location'], 'path' => '/item/' . $id];
            view_labels(item_noun(count($labels)) . ' selected', $labels, '/?select=1');
            exit;
        }
        $loc = filter_value($_GET['loc'] ?? '');
        if (ctype_digit($loc)) {
            $l = tax_get('location', (int)$loc);
            if (!$l) throw new UserError('Location not found.', 404);
            $byId = [];
            foreach ($locs as $r) $byId[(int)$r['id']] = $r;
            $base = '/labels?loc=' . (int)$l['id'];
            $labels = [];
            if (($_GET['items'] ?? '') === '1') {
                foreach (items_search('', ['loc' => $loc, 'cat' => ''], 'name', 1, 1000)['items'] as $it) {
                    $labels[] = ['title' => $it['name'], 'sub' => (string)$it['location'], 'path' => '/item/' . (int)$it['id']];
                }
            } elseif (($_GET['sub'] ?? '') === '1') {
                $sub = array_flip(location_subtree_ids((int)$l['id']));
                foreach ($locs as $r) if (isset($sub[(int)$r['id']])) $labels[] = $locLabel($r);
            } else {
                $labels[] = $locLabel($byId[(int)$l['id']] ?? $l);
            }
            $opts = [$base => 'Location label'];
            if (count(location_subtree_ids((int)$l['id'])) > 1) $opts[$base . '&sub=1'] = 'Location + sub-locations';
            $opts += [$base . '&items=1' => 'One label per item', '/labels' => 'All locations'];
            view_labels($l['name'], $labels, '/locations', $opts);
            exit;
        }
        view_labels('All locations', array_map($locLabel, $locs), '/locations');
        exit;
    }
    if (preg_match('#^/item/(\d+)/label$#D', $path, $m) && $method === 'GET') {
        $it = item_get((int)$m[1]);
        if (!$it) throw new UserError('Item not found.', 404);
        no_store();
        view_labels($it['name'], [['title' => $it['name'], 'sub' => $it['location'], 'path' => '/item/' . (int)$it['id']]], '/item/' . (int)$it['id']);
        exit;
    }

    /* ---- activity log ---- */
    if ($path === '/activity' && $method === 'GET') {
        no_store();
        $page = max(1, (int)($_GET['page'] ?? 1));
        $item = ctype_digit((string)($_GET['item'] ?? '')) ? (int)$_GET['item'] : null;
        $itemRow = null;
        if ($item !== null) {
            $itemRow = item_get($item);
            if (!$itemRow) { $last = activity_list($item, 1, 1)[0] ?? null; $itemRow = $last ? ['id' => $item, 'name' => $last['item_name']] : null; }
            if (!$itemRow) { view_error(404, 'Item not found.'); exit; }
        }
        $rows = activity_list($item, $page);
        view_activity(array_slice($rows, 0, ACTIVITY_PAGE), $page, count($rows) > ACTIVITY_PAGE, $itemRow);
        exit;
    }

    /* ---- backup & restore ---- */
    if ($path === '/backup' && $method === 'GET') {
        no_store();
        view_backup(backup_counts(db(), db_driver()), null, null, isset($_GET['restored']) ? (($_GET['restored'] === 'token-cleared') ? 'Backup restored. The AI autofill token was cleared because the service address changed: enter it again under AI autofill.' : 'Backup restored') : null);
        exit;
    }
    if ($path === '/backup.zip' && $method === 'GET') {
        @set_time_limit(300);
        $tmp = backup_tmp_dir(DATA_DIR) . '/dl-' . bin2hex(random_bytes(8)) . '.zip';
        try {
            backup_create(db(), db_driver(), DATA_DIR, $tmp);
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="apotheke-backup-' . date('Y-m-d-His', app_time()) . '.zip"');
            header('Content-Length: ' . filesize($tmp));
            no_store();
            readfile($tmp);
        } finally {
            @unlink($tmp);
        }
        exit;
    }
    if ($path === '/backup/restore' && $method === 'POST') {
        check_csrf();
        $file = $_FILES['backup'] ?? null;
        $fail = function (string $msg): never {
            http_response_code(422);
            view_backup(backup_counts(db(), db_driver()), $msg);
            exit;
        };
        if (!is_array($file) || is_array($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) $fail('Choose a backup file.');
        if ($file['error'] !== UPLOAD_ERR_OK) $fail(upload_error_text((int)$file['error']));
        $token = bin2hex(random_bytes(16));
        $dest = backup_tmp_dir(DATA_DIR) . "/restore-$token.zip";
        if (!is_uploaded_file($file['tmp_name']) || !move_uploaded_file($file['tmp_name'], $dest)) $fail('The upload could not be stored.');
        try {
            $b = backup_open($dest, db(), db_driver());
            $b['zip']->close();
        } catch (UserError $e) {
            @unlink($dest);
            $fail($e->getMessage());
        }
        no_store();
        view_backup(backup_counts(db(), db_driver()), null, ['token' => $token] + $b);
        exit;
    }
    if ($path === '/backup/restore/confirm' && $method === 'POST') {
        check_csrf();
        $token = (string)($_POST['token'] ?? '');
        $file = DATA_DIR . "/tmp/restore-$token.zip";
        if (!preg_match('/^[a-f0-9]{32}$/D', $token) || !is_file($file)) {
            http_response_code(410);
            view_backup(backup_counts(db(), db_driver()), 'That restore has expired. Please choose the backup file again.');
            exit;
        }
        if (empty($_POST['confirm'])) {
            http_response_code(422);
            $b = backup_open($file, db(), db_driver());
            $b['zip']->close();
            view_backup(backup_counts(db(), db_driver()), 'Tick the box to confirm that the catalog will be replaced.', ['token' => $token] + $b);
            exit;
        }
        @set_time_limit(300);
        try {
            $restored = backup_restore(db(), db_driver(), DATA_DIR, $file);
        } catch (UserError $e) {
            http_response_code(422);
            view_backup(backup_counts(db(), db_driver()), $e->getMessage());
            exit;
        } finally {
            @unlink($file);
        }
        redirect('/backup?restored=' . (!empty($restored['token_cleared']) ? 'token-cleared' : '1'));
    }

    /* ---- CSV export ---- */
    /* ---- CSV import ---- */
    if ($path === '/import') {
        if ($method === 'POST') {
            check_csrf();
            try {
                $iid = import_upload($_FILES['csv'] ?? []);
            } catch (UserError $e) {
                http_response_code($e->getCode() ?: 422);
                view_import_home($e->getMessage(), null);
                exit;
            }
            redirect('/import/' . $iid);
        }
        no_store();
        view_import_home(null, isset($_GET['done']) && ($d = import_get((int)$_GET['done'])) && $d['result'] ? 'Imported · ' . import_summary_text($d['result']) : null);
        exit;
    }
    if (preg_match('#^/import/(\d+)(?:/(preview|commit))?$#D', $path, $m)) {
        $imp = import_get((int)$m[1]) ?? throw new UserError('Import not found.', 404);
        $act = $m[2] ?? '';
        if ($imp['status'] === 'done') redirect('/import?done=' . $imp['id']);
        [$header, $rows] = import_rows($imp);
        if ($act === '' && $method === 'GET') { no_store(); view_import_map($imp, $header, $rows, null, null); exit; }
        if ($act === '' || $method !== 'POST') throw new UserError('Page not found.', 404);
        check_csrf();
        try {
            [$imp['mapping'], $imp['options']] = import_set_mapping($imp['id'], $header, $_POST);
            if ($act === 'commit') {
                import_commit($imp);
                redirect('/import?done=' . $imp['id']);
            }
            view_import_map($imp, $header, $rows, import_run($imp, false), null);
        } catch (UserError $e) {
            http_response_code($e->getCode() ?: 422);
            view_import_map($imp, $header, $rows, $act === 'commit' ? import_run($imp, false) : null, $e->getMessage());
        }
        exit;
    }

    if ($path === '/export.csv' && $method === 'GET') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="apotheke-' . date('Y-m-d', app_time()) . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['id', 'name', 'location', 'category', 'quantity', 'notes', 'image', 'created_at', 'updated_at', 'tags', 'value', 'currency', 'purchase_date', 'serial', 'min_qty', 'lent_to', 'lent_at', 'lent_due', 'expires_on', 'barcode'], ',', '"', '\\');
        $tagMap = [];
        foreach (db()->query('SELECT it.item_id, t.name FROM item_tags it JOIN tags t ON t.id = it.tag_id ORDER BY t.sort_key, t.id') as $t) $tagMap[(int)$t['item_id']][] = $t['name'];
        foreach (db()->query("SELECT i.id, i.name, COALESCE(l.name, '') AS location, COALESCE(c.name, '') AS category, i.quantity, i.notes, i.image, i.created_at, i.updated_at, i.value_cents, i.purchase_date, i.serial, i.min_qty, i.lent_to, i.lent_at, i.lent_due, i.expires_on, i.barcode
                              " . ITEM_FROM . "
                              ORDER BY CASE WHEN l.id IS NULL THEN 1 ELSE 0 END, l.sort_key, CASE WHEN c.id IS NULL THEN 1 ELSE 0 END, c.sort_key, i.sort_key, i.id") as $r) {
            $v = $r['value_cents'];
            unset($r['value_cents']);
            $r = array_slice($r, 0, 9) + ['tags' => implode(', ', $tagMap[(int)$r['id']] ?? []),
                 'value' => $v === null ? '' : number_format((int)$v / 100, 2, '.', ''), 'currency' => $v === null ? '' : setting('currency'),
                 'purchase_date' => (string)$r['purchase_date'], 'serial' => (string)$r['serial'],
                 'min_qty' => $r['min_qty'] === null ? '' : (string)(int)$r['min_qty'],
                 'lent_to' => (string)$r['lent_to'], 'lent_at' => (string)$r['lent_at'], 'lent_due' => (string)$r['lent_due'], 'expires_on' => (string)$r['expires_on'], 'barcode' => (string)$r['barcode']];
            fputcsv($out, array_map('csv_protect', array_values($r)), ',', '"', '\\');
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
