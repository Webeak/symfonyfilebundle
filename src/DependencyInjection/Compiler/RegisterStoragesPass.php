<?php
namespace Webeak\Bundle\FileBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Reference;

class RegisterStoragesPass implements CompilerPassInterface
{
    /**
     * @inheritDoc
     */
    public function process(ContainerBuilder $container)
    {
        if ($container->hasDefinition('Webeak\Bundle\FileBundle\FileManager') === false) {
            return ;
        }
        $managerDefinition = $container->getDefinition('Webeak\Bundle\FileBundle\FileManager');
        foreach ($container->findTaggedServiceIds('wb.file.file_manager_storage') as $id => $attributes) {
            $managerDefinition->addMethodCall('registerStorage', [new Reference($id), $attributes]);
        }
    }
}
