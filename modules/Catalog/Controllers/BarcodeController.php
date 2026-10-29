<?php

declare(strict_types=1);

namespace Apotheke\Catalog\Controllers;

use Apotheke\Catalog\Barcode;
use Apotheke\Catalog\Items\ItemInput;
use Apotheke\Catalog\Views\ItemView;
use Apotheke\Core\Http\LegacyPathRouter;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Legacy public/index.php: GET /barcode (the lookup form; ?code= goes to
 * /barcode/{code}) and GET /barcode/{code} (the only item with the code, the
 * list of matches, or a new item with the code filled in).
 */
final class BarcodeController extends CatalogController
{
    /** GET /barcode */
    public function index(): ResponseInterface
    {
        $code = $this->request->getGet('code');
        if (is_string($code) && ItemInput::barcode($code) !== '') {
            return $this->redirectTo('/barcode/' . rawurlencode(ItemInput::barcode($code)));
        }
        /** @var ItemView $view */
        $view = service('itemView');

        return $this->html($view->barcode());
    }

    /** GET /barcode/{code}: the code is the rest of legacy's decoded path, any character included (Core's LegacyPathRouter). */
    public function lookup(): ResponseInterface
    {
        $code = substr(LegacyPathRouter::requestPath($this->request), strlen('/barcode/'));
        /** @var Barcode $barcode */
        $barcode = service('barcode');

        return $this->redirectTo($barcode->target($code))->removeHeader('Cache-Control')->setHeader('Cache-Control', 'no-store');
    }
}
