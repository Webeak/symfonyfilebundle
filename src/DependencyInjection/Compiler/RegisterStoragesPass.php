<?php
namespace Webeak\Bundle\FileBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Webeak\Bundle\FileBundle\FileManager;

class RegisterStoragesPass implements CompilerPassInterface
{
    /**
     * @inheritDoc
     */
    public function process(ContainerBuilder $container)
    {
        if ($container->hasDefinition(FileManager::class) === false) {
            return ;
        }
        $managerDefinition = $container->getDefinition(FileManager::class);
        foreach ($container->findTaggedServiceIds('wb.file.file_manager_storage') as $id => $attributes) {
            $managerDefinition->addMethodCall('registerStorage', [new Reference($id), $attributes]);
        }
    }
}
