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
        header('Cache-Control: no-store');
        echo stats_body_html();
        exit;
    }

    /* ---- list / search ---- */
    if ($path === '/' && $method === 'GET') {
        $q    = clean_line((string)($_GET['q'] ?? ''), 200);
        $f    = ['loc' => filter_value($_GET['loc'] ?? ''), 'cat' => filter_value($_GET['cat'] ?? ''), 'tag' => ctype_digit((string)($_GET['tag'] ?? '')) ? (string)$_GET['tag'] : ''];
        $sort = (string)($_GET['sort'] ?? 'recent');
        $page = max(1, (int)($_GET['page'] ?? 1));
        $res  = items_search($q, $f, $sort, $page);
        if (wants_json()) {
            json_out(['ok' => true, 'html' => cards_html($res['items']), 'total' => $res['total'],
                      'count' => count_text($res['total'], $q !== '' || $f['loc'] !== '' || $f['cat'] !== '' || $f['tag'] !== ''), 'more' => $res['more'], 'page' => $page,
                      'chips' => active_chips_html($f, $sort, locations(), categories()), 'nfilters' => active_filter_count($f, $sort)]);
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
                 'quantity' => 1, 'notes' => '', 'tags' => '', 'value' => '', 'purchase_date' => '', 'serial' => ''];
        $toast = isset($_GET['saved']) && ($s = item_get((int)$_GET['saved'])) ? 'Saved “' . $s['name'] . '”' : null;
        view_form(null, $vals, locations(), categories(), null, $toast);
        exit;
    }
    if (preg_match('#^/item/(\d+)(/edit)?$#D', $path, $m) && $method === 'GET') {
        $it = item_get((int)$m[1]);
        if (!$it) { view_error(404, 'Item not found.'); exit; }
        header('Cache-Control: no-store');
        if (!empty($m[2])) view_form($it, ['new_location' => '', 'new_category' => '', 'tags' => implode(', ', array_column($it['tags'], 'name')),
            'value' => money_input($it['value_cents']), 'purchase_date' => (string)$it['purchase_date']] + $it, locations(), categories(), null);
        else view_item($it, photos_for((int)$it['id']), max(0, (int)($_GET['p'] ?? 0)));
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
            'tags'     => mb_substr((string)($_POST['tags'] ?? ''), 0, 2000),
            'value'    => clean_line((string)($_POST['value'] ?? ''), 32),
            'purchase_date' => clean_line((string)($_POST['purchase_date'] ?? ''), 10),
            'serial'   => clean_line((string)($_POST['serial'] ?? ''), 100),
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
            $fields = ['tags' => tags_parse($vals['tags']), 'value_cents' => parse_money($vals['value']),
                       'purchase_date' => parse_date_ymd($vals['purchase_date']), 'serial' => $vals['serial']];
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
        $savedId = item_save($existing, $fields + $vals, $newImage, !empty($_POST['remove_image']));
        $after = (string)($_POST['after'] ?? 'view');
        if ($after === 'another') {
            $qs = ['loc' => $vals['location_id'], 'cat' => $vals['category_id'], 'saved' => $savedId];
            redirect('/new?' . http_build_query(array_filter($qs, fn($v) => $v !== null)));
        }
        redirect($existing ? '/item/' . $savedId : '/?saved=' . $savedId);
    }

    /* ---- photos: add / cover / reorder / delete ---- */
    if (preg_match('#^/item/(\d+)/photos(?:/(\d+)/(cover|left|right|delete))?$#D', $path, $m) && $method === 'POST') {
        check_csrf();
        $iid = (int)$m[1];
        if (!item_get($iid)) throw new UserError('Item not found.', 404);
        if (empty($m[2])) {
            $files = pick_uploads(['photos', 'photo']);
            if (!$files) throw new UserError('Choose a photo to add.', 422);
            if (count(photos_for($iid)) + count($files) > MAX_PHOTOS) throw new UserError('An item can have at most ' . MAX_PHOTOS . ' photos.', 422);
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

    /* ---- QR labels ---- */
    if ($path === '/labels' && $method === 'GET') {
        header('Cache-Control: no-store');
        $locs = locations();
        $loc = filter_value($_GET['loc'] ?? '');
        if (ctype_digit($loc)) {
            $l = tax_get('location', (int)$loc);
            if (!$l) throw new UserError('Location not found.', 404);
            $labels = [];
            if (($_GET['items'] ?? '') === '1') {
                foreach (items_search('', ['loc' => $loc, 'cat' => ''], 'name', 1, 1000)['items'] as $it) {
                    $labels[] = ['title' => $it['name'], 'sub' => $l['name'], 'path' => '/item/' . (int)$it['id']];
                }
            } else {
                $labels[] = ['title' => $l['name'], 'sub' => (int)$l['n'] . ' item' . ((int)$l['n'] === 1 ? '' : 's'), 'path' => '/?loc=' . (int)$l['id']];
            }
            view_labels($l['name'], $labels, '/locations', ['/labels?loc=' . (int)$l['id'] => 'Location label', '/labels?loc=' . (int)$l['id'] . '&items=1' => 'One label per item', '/labels' => 'All locations']);
            exit;
        }
        $labels = array_map(fn($l) => ['title' => $l['name'], 'sub' => (int)$l['n'] . ' item' . ((int)$l['n'] === 1 ? '' : 's'), 'path' => '/?loc=' . (int)$l['id']], $locs);
        view_labels('All locations', $labels, '/locations');
        exit;
    }
    if (preg_match('#^/item/(\d+)/label$#D', $path, $m) && $method === 'GET') {
        $it = item_get((int)$m[1]);
        if (!$it) throw new UserError('Item not found.', 404);
        header('Cache-Control: no-store');
        view_labels($it['name'], [['title' => $it['name'], 'sub' => $it['location'], 'path' => '/item/' . (int)$it['id']]], '/item/' . (int)$it['id']);
        exit;
    }

    /* ---- CSV export ---- */
    if ($path === '/export.csv' && $method === 'GET') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="apotheke-' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['id', 'name', 'location', 'category', 'quantity', 'notes', 'image', 'created_at', 'updated_at', 'tags', 'value', 'currency', 'purchase_date', 'serial'], ',', '"', '\\');
        $tagMap = [];
        foreach (db()->query('SELECT it.item_id, t.name FROM item_tags it JOIN tags t ON t.id = it.tag_id ORDER BY t.sort_key, t.id') as $t) $tagMap[(int)$t['item_id']][] = $t['name'];
        foreach (db()->query("SELECT i.id, i.name, COALESCE(l.name, '') AS location, COALESCE(c.name, '') AS category, i.quantity, i.notes, i.image, i.created_at, i.updated_at, i.value_cents, i.purchase_date, i.serial
                              " . ITEM_FROM . "
                              ORDER BY CASE WHEN l.id IS NULL THEN 1 ELSE 0 END, l.sort_key, CASE WHEN c.id IS NULL THEN 1 ELSE 0 END, c.sort_key, i.sort_key, i.id") as $r) {
            $v = $r['value_cents'];
            unset($r['value_cents']);
            $r = array_slice($r, 0, 9) + ['tags' => implode(', ', $tagMap[(int)$r['id']] ?? []),
                 'value' => $v === null ? '' : number_format((int)$v / 100, 2, '.', ''), 'currency' => $v === null ? '' : cfg()['currency'],
                 'purchase_date' => (string)$r['purchase_date'], 'serial' => (string)$r['serial']];
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
