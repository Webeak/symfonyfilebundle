<?php
namespace Webeak\Bundle\FileBundle\DependencyInjection\Compiler;

use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;

class RegisterProcessorsPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container)
    {
        $aliases = [];
        foreach ($container->findTaggedServiceIds('wb.file.file_manager_processor') as $id => $attributes) {
            $processorDefinition = $container->getDefinition($id);
            $processorDefinition->setPublic(true);
            $processorDefinition->setShared(false);
            if (is_array($attributes)) {
                for ($i = 0, $ii = count($attributes); $i < $ii; ++$i) {
                    if (array_key_exists('alias', $attributes[$i])) {
                        if (!array_key_exists($attributes[$i]['alias'], $aliases)) {
                            $aliases[$attributes[$i]['alias']] = $id;
                        } else {
                            throw new InvalidConfigurationException(
                                sprintf('A processor named "%s" has already been defined.', $attributes[$i]['alias'])
                            );
                        }
                    }
                }
            }
        }
        $container->setParameter('wb_file.processors_aliases', $aliases);
    }
}
