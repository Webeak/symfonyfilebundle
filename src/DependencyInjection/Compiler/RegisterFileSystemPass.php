<?php
namespace Webeak\Bundle\FileBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Webeak\Bundle\EssentialBundle\Exception\UsageException;
use Webeak\Bundle\FileBundle\FileManager;
use Webeak\Bundle\FileBundle\FileSystem\FileSystemInterface;

class RegisterFileSystemPass implements CompilerPassInterface
{
    /**
     * @inheritDoc
     */
    public function process(ContainerBuilder $container): void
    {
        if ($container->hasDefinition(FileManager::class) === false) {
            return ;
        }
        $map = [];
        foreach ($container->findTaggedServiceIds('wb.file.file_filesystem') as $id => $attributes) {
            foreach ($attributes as $value) {
                if (array_key_exists('alias', $value)) {
                    $map[$value['alias']] = new Reference($id);
                    continue 2;
                }
            }
            throw new UsageException(sprintf('Missing alias for filesystem adapter "%s".', $id));
        }
        $currentAdapter = $container->getParameter('wb.file.filesystem_type');
        if (!array_key_exists($currentAdapter, $map)) {
            throw new UsageException(sprintf('No filesystem adapter "%s" has been found.', $currentAdapter));
        }
        $definition = $container->getDefinition($map[$currentAdapter]);
        $container->setDefinition(FileSystemInterface::class, $definition);
    }
}
