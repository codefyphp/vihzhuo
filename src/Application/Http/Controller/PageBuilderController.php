<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Service\CodefyPageBuilder;
use Codefy\Framework\Http\BaseController;
use Psr\Http\Message\ResponseInterface;
use Qubus\Exception\Data\TypeException;
use Qubus\Http\Factories\EmptyResponseFactory;
use Qubus\Http\Factories\HtmlResponseFactory;
use Qubus\Http\ServerRequest;

use function Codefy\Framework\Helpers\config;
use function Codefy\Framework\Helpers\view;
use function Qubus\Support\Helpers\is_null__;

final class PageBuilderController extends BaseController
{
    /**
     * @throws \Exception
     */
    public function assets(): ResponseInterface
    {
        $builder = new CodefyPageBuilder(config()->array('vihzhuo'));
        $builder->handlePageBuilderAssetRequest();

        return EmptyResponseFactory::create(200);
    }

    /**
     * @throws TypeException
     */
    public function uploads(): ResponseInterface
    {
        $builder = new CodefyPageBuilder(config()->array('vihzhuo'));
        $builder->handleUploadedFileRequest();

        return EmptyResponseFactory::create(200);
    }

    /**
     * @throws TypeException
     */
    public function websiteManager(): ResponseInterface
    {
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
}
