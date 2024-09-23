<?php
namespace Webeak\Bundle\FileBundle\DependencyInjection\Compiler;

use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Webeak\Bundle\FileBundle\FileManager;
use Webeak\Bundle\FileBundle\Storage\DoctrineStorage;
use Webeak\Bundle\FileBundle\Storage\StorageCollection;

class RegisterStoragesPass implements CompilerPassInterface
{
    /**
     * @inheritDoc
     */
    /**
     * @inheritDoc
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(FileManager::class) || !$container->hasParameter('wb.file.storages')) {
            return;
        }
        $storageConfig = $container->getParameter('wb.file.storages');
        foreach ($storageConfig as $key => $config) {
            $serviceId = sprintf('wb_file.storage.%s', $key);

            switch ($config['type']) {
                case 'doctrine':
                    $container
                        ->register($serviceId, DoctrineStorage::class)
                        ->addArgument(new Reference('service_container'))
                        ->addArgument(new Reference(ManagerRegistry::class))
                        ->addArgument($config);
                    break;

                default:
                    throw new \InvalidArgumentException(sprintf('Unknown storage type "%s"', $config['type']));
            }
        }

        // Register StorageCollection with all the registered storages
        $filesystemsServicesReferences = [];
        foreach ($storageConfig as $key => $config) {
            $serviceId = sprintf('wb_file.storage.%s', $key);
            $filesystemsServicesReferences[$key] = new Reference($serviceId);
        }

        $container->register(StorageCollection::class, StorageCollection::class)
            ->addArgument($filesystemsServicesReferences);
    }
}
