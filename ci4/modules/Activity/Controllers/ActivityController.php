<?php

declare(strict_types=1);

namespace Apotheke\Activity\Controllers;

use Apotheke\Activity\ActivityLog;
use Apotheke\Activity\Views\ActivityView;
use Apotheke\Core\Http\ErrorPage;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

/** GET /activity[?item=&page=]: recent activity, or one item's full history (legacy). */
final class ActivityController extends Controller
{
    public function index(): ResponseInterface
    {
        $get = $this->request->getGet();
        $get = is_array($get) ? $get : [];
        $page = ActivityLog::clampPage(self::int($get['page'] ?? 1));
        $raw = $get['item'] ?? '';
        $item = is_scalar($raw) && ctype_digit((string)$raw) ? (int)$raw : null;
        /** @var ActivityLog $log */
        $log = service('activityLog');
        $row = null;
        if ($item !== null) {
            $row = $log->item($item);
            if ($row === null) {
                // Legacy view_error(): the error page, even for a fetch.
                /** @var ErrorPage $error */
                $error = service('errorPage');

                return $this->response->setStatusCode(404)->setHeader('Content-Type', 'text/html; charset=UTF-8')->setBody($error->html(404, 'Item not found.'));
            }
        }
        $rows = $log->list($item, $page);
        /** @var ActivityView $view */
        $view = service('activityView');

        return $this->response->setHeader('Content-Type', 'text/html; charset=UTF-8')
            ->removeHeader('Cache-Control')->setHeader('Cache-Control', 'no-store')
            ->setBody($view->page(array_slice($rows, 0, ActivityLog::PAGE), $page, count($rows) > ActivityLog::PAGE, $row));
    }

    /** Legacy (int)$_GET[...]: an array is 0 when empty, else 1. */
    private static function int(mixed $v): int
    {
        return is_array($v) ? ($v === [] ? 0 : 1) : (is_scalar($v) ? (int)$v : 0);
    }
}
