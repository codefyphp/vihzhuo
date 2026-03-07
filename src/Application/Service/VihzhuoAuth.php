<?php

declare(strict_types=1);

namespace Application\Service;

use Codefy\Framework\Proxy\Codefy;
use Qubus\Exception\Data\TypeException;
use Vihzhuo\Contracts\AuthContract;

use function Codefy\Framework\Helpers\config;
use function Codefy\Framework\Helpers\gate;
use function Codefy\Framework\Helpers\site_url;
use function phpb_redirect;

class VihzhuoAuth implements AuthContract
{
    /**
     * @inheritDoc
     */
    public function handleRequest(?string $action = null): void
    {
        if (phpb_in_module('auth')) {
            if ($this->isAuthenticated()) {
                phpb_redirect(url: phpb_url(module: 'website_manager'));
            } else {
                Codefy::$PHP->flash->error(message: 'Access denied');
                phpb_redirect(url: site_url(path: 'login'));
            }
        } elseif ($action === 'logout') {
            phpb_redirect(url: site_url(path: 'logout'));
        }
    }

    /**
     * @inheritDoc
     */
    public function isAuthenticated(): bool
    {
        return gate()->isLoggedIn() && gate(permission: 'vihzhuo:manage');
    }

    /**
     * @inheritDoc
     * @throws TypeException
     */
    public function requireAuth(): void
    {
        if (!$this->isAuthenticated()) {
            phpb_redirect(
                url: site_url(
                    path: config()->string(key: 'auth.login_route')
                )
            );
            exit();
        }
    }

    /**
     * @inheritDoc
     */
    public function renderLoginForm(): void
    {
        return ;
    }
}
