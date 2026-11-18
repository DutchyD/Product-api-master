<?php

declare(strict_types=1);

namespace MaxServ\Core;

use Exception;
use MaxServ\Core\Routing\Router;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\Finder\Finder;

readonly class Bootstrap
{
    /**
     * Builds the container and routes the current request.
     *
     * @return void
     * @throws Exception The container could not be built, or the router could not be loaded from it.
     */
    public function boot(): void
    {
        $container = $this->buildContainer();

        /** @var Router $router */
        $router = $container->get(Router::class);
        $router->match();
    }

    /**
     * Builds and compiles the dependency injection container from every package's services.yaml.
     *
     * @return ContainerBuilder The compiled container.
     * @throws Exception A service file could not be loaded or the container could not be compiled.
     */
    public function buildContainer(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $this->configure($container);

        $loader = new YamlFileLoader($container, new FileLocator(APPLICATION_ROOT . '/packages'));

        $finder = new Finder();
        $serviceFiles = $finder->files()->in(APPLICATION_ROOT . '/packages')->name('services.yaml');

        foreach ($serviceFiles as $serviceFile) {
            $loader->load($serviceFile->getPathname());
        }

        $container->compile();

        return $container;
    }

    private function configure(ContainerBuilder $container): void
    {
        $container->setParameter('application.root', APPLICATION_ROOT);

        $container->setParameter('db.host', getenv('DB_HOST'));
        $container->setParameter('db.user', getenv('DB_USER'));
        $container->setParameter('db.password', getenv('DB_PASSWORD'));
        $container->setParameter('db.database', getenv('DB_DATABASE'));
    }
}
