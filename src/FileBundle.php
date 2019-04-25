<?php
namespace Webeak\Bundle\FileBundle;

use Webeak\Bundle\FileBundle\DependencyInjection\Compiler\RegisterAdaptersPass;
use Webeak\Bundle\FileBundle\DependencyInjection\Compiler\RegisterProcessorsPass;
use Webeak\Bundle\FileBundle\DependencyInjection\Compiler\RegisterStoragesPass;
use Doctrine\DBAL\Types\Type;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Webeak\Bundle\FileBundle\DependencyInjection\WebeakFilesExtension;

class FileBundle extends Bundle
{
    /**
     * {@inheritdoc}
     */
    public function getContainerExtension()
    {
        return new WebeakFilesExtension();
    }

    /**
     * {@inheritDoc}
     */
    public function build(ContainerBuilder $container)
    {
        parent::build($container);
        $container->addCompilerPass(new RegisterAdaptersPass());
        $container->addCompilerPass(new RegisterProcessorsPass());
        $container->addCompilerPass(new RegisterStoragesPass());
    }

    /**
     * {@inheritDoc}
     *
     * @throws
     */
    public function boot()
    {
        $em = $this->container->get('doctrine.orm.entity_manager');
        $platform = $em->getConnection()->getDatabasePlatform();
        if (!Type::hasType('file')) {
            Type::addType('file', 'Webeak\Bundle\FileBundle\Bridge\Doctrine\Dbal\Type\FileType');
            $platform->registerDoctrineTypeMapping('file', 'file');
            $platform->markDoctrineTypeCommented('file');
        }
        if (!Type::hasType('files')) {
            Type::addType('files', 'Webeak\Bundle\FileBundle\Bridge\Doctrine\Dbal\Type\FilesType');
            $platform->registerDoctrineTypeMapping('files', 'files');
            $platform->markDoctrineTypeCommented('files');
        }
    }
}
