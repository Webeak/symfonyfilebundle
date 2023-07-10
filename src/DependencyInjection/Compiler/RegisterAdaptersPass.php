<?php
namespace Webeak\Bundle\FileBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Reference;
use Webeak\Bundle\FileBundle\FileManager;

class RegisterAdaptersPass implements CompilerPassInterface
{
    /**
     * @inheritDoc
     */
    public function process(ContainerBuilder $container): void
    {
        if ($container->hasDefinition(FileManager::class) === false) {
            return ;
        }
        $managerDefinition = $container->getDefinition(FileManager::class);
        foreach ($container->findTaggedServiceIds('wb.file.file_manager_adapter') as $id => $attributes) {
            $managerDefinition->addMethodCall('registerAdapter', [new Reference($id), $attributes]);
        }
    }
}
