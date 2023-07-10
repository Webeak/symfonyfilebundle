<?php
namespace Webeak\Bundle\FileBundle;

use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Webeak\Bundle\FileBundle\Bridge\Doctrine\Dbal\Type\FilesType;
use Webeak\Bundle\FileBundle\Bridge\Doctrine\Dbal\Type\FileType;
use Webeak\Bundle\FileBundle\DependencyInjection\Compiler\RegisterAdaptersPass;
use Webeak\Bundle\FileBundle\DependencyInjection\Compiler\RegisterFileSystemPass;
use Webeak\Bundle\FileBundle\DependencyInjection\Compiler\RegisterProcessorsPass;
use Webeak\Bundle\FileBundle\DependencyInjection\Compiler\RegisterStoragesPass;
use Doctrine\DBAL\Types\Type;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Webeak\Bundle\FileBundle\DependencyInjection\WebeakFilesExtension;

class WebeakFileBundle extends Bundle
{
    /**
     * {@inheritdoc}
     */
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new WebeakFilesExtension();
    }

    /**
     * {@inheritDoc}
     */
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new RegisterAdaptersPass());
        $container->addCompilerPass(new RegisterProcessorsPass());
        $container->addCompilerPass(new RegisterStoragesPass());
        $container->addCompilerPass(new RegisterFileSystemPass());
    }

    /**
     * {@inheritDoc}
     *
     * @throws
     */
    public function boot(): void
    {
        if ($this->container->has('doctrine.orm.entity_manager')) {
            $em = $this->container->get('doctrine.orm.entity_manager');
            $platform = $em->getConnection()->getDatabasePlatform();
            if (!Type::hasType('file')) {
                Type::addType('file', FileType::class);
                $platform->registerDoctrineTypeMapping('file', 'file');
            }
            if (!Type::hasType('files')) {
                Type::addType('files', FilesType::class);
                $platform->registerDoctrineTypeMapping('files', 'files');
            }
        }
    }
}
