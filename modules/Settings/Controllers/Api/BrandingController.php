<?php

declare(strict_types=1);

namespace Apotheke\Settings\Controllers\Api;

use Apotheke\Core\Http\ApiResponse;
use Apotheke\Settings\Branding\Branding;
use Apotheke\Settings\SettingsService;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * GET /api/v1/branding (API plan §3, Branding row): the name, colours and icon URLs every page's <head> and the
 * sign-in page already show to anyone, so it is public like /manifest.webmanifest and /theme.css. Nothing else:
 * no setting that only the Control Panel shows. No session.
 */
final class BrandingController extends Controller
{
    public function show(): ResponseInterface
    {
        /** @var SettingsService $settings */
        $settings = service('settings');
        /** @var Branding $branding */
        $branding = service('branding');

        return ApiResponse::json($this->response, [
            'name' => (string)$settings->get('app_name'),
            'tagline' => (string)$settings->get('app_tagline'),
            'accent' => (string)$settings->get('accent'),
            'theme_default' => (string)$settings->get('theme_default'),
            'logo_url' => $branding->logoUrl(),
            'favicon_url' => $branding->faviconSvgUrl(),
            'touch_icon_url' => $branding->iconUrl('180'),
        ]);
    }
}
