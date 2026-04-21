<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Service\CodefyPageBuilder;
use Codefy\Framework\Http\BaseController;
use Codefy\Framework\Proxy\Codefy;
use Psr\Http\Message\ResponseInterface;
use Qubus\Exception\Data\TypeException;
use Qubus\Http\Factories\EmptyResponseFactory;
use Qubus\Http\Factories\HtmlResponseFactory;
use Qubus\Http\ServerRequest;
use Qubus\Routing\Exceptions\NamedRouteNotFoundException;
use Qubus\Routing\Exceptions\RouteParamFailedConstraintException;

use function Codefy\Framework\Helpers\config;
use function Codefy\Framework\Helpers\gate;
use function Codefy\Framework\Helpers\view;
use function Qubus\Security\Helpers\t__;
use function Qubus\Support\Helpers\is_null__;

final class PageBuilderController extends BaseController
{
    /**
     * @throws \Exception
     */
    public function assets(): void
    {
        $builder = $this->builder();
        $builder->handlePageBuilderAssetRequest();
    }

    /**
     * @throws TypeException
     */
    public function uploads(): void
    {
        $builder = $this->builder();
        $builder->handleUploadedFileRequest();
    }

    /**
     * @return ResponseInterface
     * @throws TypeException
     * @throws NamedRouteNotFoundException
     * @throws RouteParamFailedConstraintException
     */
    public function websiteManager(): ResponseInterface
    {
        if (false === gate(permission: 'vihzhuo:manage')) {
            Codefy::$PHP->flash->error(
                message: t__(msgid: 'Access denied.', domain: 'devflow')
            );
            return $this->redirect($this->router->url('admin.home'));
        }

        $builder = new CodefyPageBuilder(config()->array('vihzhuo'));
        $builder->handleRequest();

        return EmptyResponseFactory::create(200);
    }

    /**
     * @throws \Exception
     */
    public function any(ServerRequest $request): ResponseInterface
    {
        $builder = new CodefyPageBuilder(config()->array('vihzhuo'));
        $hasPageReturned = $builder->handlePublicRequest();

        if ($request->getUri()->getPath() === '/' && ! $hasPageReturned) {
            return view(template: 'framework::welcome');
        }

        if (is_null__($hasPageReturned)) {
            return view(template: 'framework::error/404');
        }

        // @phpstan-ignore argument.type
        return HtmlResponseFactory::create($hasPageReturned);
    }

    /**
     * @throws TypeException
     */
    private function builder(): CodefyPageBuilder
    {
        return new CodefyPageBuilder(config()->array('vihzhuo'));
    }
}
