<?php

declare(strict_types=1);

use Application\Provider\DatabaseServiceProvider;
use Application\Provider\RbacServiceProvider;
use Application\Provider\ViewServiceProvider;
use Codefy\Framework\Application as CodefyApp;
use Codefy\Framework\Providers\AssetsServiceProvider;
use Codefy\Framework\Providers\LocalizationServiceProvider;
use Qubus\Http\Response;
use Qubus\Routing\Router;

use function Codefy\Framework\Helpers\env;

$app = CodefyApp::create(
    config: [
        'basePath' => env(key: 'APP_BASE_PATH') ?: dirname(path: __DIR__)
    ]
)
//->withEncryptedEnv(bool: true)
->withProviders([
    RbacServiceProvider::class,
    LocalizationServiceProvider::class,
    DatabaseServiceProvider::class,
    AssetsServiceProvider::class,
    ViewServiceProvider::class,
])
->withSingletons([
    //
])
->withRouting(
    web: [
        dirname(path: __DIR__) . '/routes/web/register.php',
        dirname(path: __DIR__) . '/routes/web/admin.php',
        dirname(path: __DIR__) . '/routes/web/auth.php',
        dirname(path: __DIR__) . '/routes/web/web.php',
    ],
    api: dirname(path: __DIR__) . '/routes/api/rest.php',
    then: static function (Router $router): void {
        // Let CORS evaluate preflights even when the target only declares POST/PUT/etc.
        // Explicit OPTIONS routes registered above take precedence over this fallback.
        $router->map(['OPTIONS'], '*', static fn(): Response => new Response(status: 404));
    },
)->return();

$app->share(nameOrInstance: $app);

return $app::getInstance();
