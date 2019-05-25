<?php

namespace Webeak\Bundle\FileBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Webeak\Bundle\FileBundle\Bridge\Doctrine\Orm\Entity\FileEntityInterface;
use Webeak\Bundle\FileBundle\Bridge\Doctrine\Orm\Entity\File;

/**
 * This is the class that validates and merges configuration from your app/config files.
 *
 * To learn more see {@link http://symfony.com/doc/current/cookbook/bundles/configuration.html}
 */
class Configuration implements ConfigurationInterface
{
    /**
     * {@inheritdoc}
     */
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder('wb_file');
        if (method_exists($treeBuilder, 'getRootNode')) {
            $rootNode = $treeBuilder->getRootNode();
        } else {
            $rootNode = $treeBuilder->root('wb_file');
        }
        $rootNode
            ->addDefaultsIfNotSet()
            ->children()
                ->scalarNode('save_path')
                    ->info('Root directory where private files should be written. DO NOT set this under the "public/" directory.')
                    ->defaultValue('%kernel.project_dir%/var/storage/wb-files')
                ->end()
                ->scalarNode('public_save_path')
                    ->info('Root directory where public files should be written. The directory must be accessible through http.')
                    ->defaultValue('%kernel.project_dir%/public/storage')
                ->end()
                ->scalarNode('http_root')
                    ->info('HTTP path pointing to the "public" directory of the project. Used to generate public files urls.')
                    ->defaultNull()
                ->end()
                ->scalarNode('storage_type')
                    ->info('Type of storage to use to store files\' metadata. Only "doctrine" is supported for now.')
                    ->defaultValue('filesystem')
                ->end()
                ->integerNode('temp_files_lifetime')
                    ->info('Lifespan of temp files. They will be cleaned up by the command "wb:file:clear".')
                    ->min(60) // 1 minute
                    ->defaultValue(60 * 60 * 2) // 2 hours
                ->end()
                ->scalarNode('not_found_image_path')
                    ->info('Path to the image to show when an inexisting image is requested. If null the default internal image will be used.')
                    ->defaultNull()
                ->end()
                ->scalarNode('access_denied_image_path')
                    ->info('Path to the image to show when a user try to access an image he doesn\'t have access to. If null the default internal image will be used.')
                    ->defaultNull()
                ->end()
                ->arrayNode('constraints_aliases')
                    ->info('Define more user-friendly names for constraints FQCN.')
                    ->prototype('scalar')
                        ->beforeNormalization()
                            ->ifString()
                            ->then(function($v) { return str_replace('/', '\\', $v); })
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('configuration_presets')
                    ->info('Define a preset configuration. You can then use the preset name to build a full Configuration object easily. Used by temporarily uploads for example.')
                    ->prototype('array')
                        ->children()
                            ->arrayNode('constraints')
                                ->prototype('array')
                                    ->prototype('variable')
                                    ->end()
                                ->end()
                            ->end()
                            ->arrayNode('processors')
                                ->prototype('array')
                                    ->prototype('variable')
                                    ->end()
                                ->end()
                            ->end()
                            ->arrayNode('requiredRoles')
                                ->addDefaultsIfNotSet()
                            ->end()
                            ->arrayNode('whiteListExclusive')
                                ->addDefaultsIfNotSet()
                            ->end()
                            ->arrayNode('whiteListCumulative')
                                ->addDefaultsIfNotSet()
                            ->end()
                            ->arrayNode('blackList')
                                ->addDefaultsIfNotSet()
                            ->end()
                            ->scalarNode('public')
                                ->defaultFalse()
                            ->end()
                            ->arrayNode('extra')
                                ->prototype('variable')
                                ->end()
                            ->end()
                            ->arrayNode('publicExtra')
                                ->prototype('variable')
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('doctrine_storage')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('entity_class')
                            ->info('FQCN of the entity to use to save files. The entity must implement the "FileEntityInterface" or intherit from "AbstractFile".')
                            ->isRequired()
                            ->defaultValue(File::class)
                            ->beforeNormalization()
                                ->ifString()
                                ->then(function($v) {
                                    $fqcn = str_replace('/', '\\', $v);
                                    $refl = new \ReflectionClass($fqcn);
                                    if (!$refl->implementsInterface(FileEntityInterface::class)) {
                                        throw new InvalidConfigurationException(sprintf('Class "%s" must implement the "FileEntityInterface" interface.', $fqcn));
                                    }
                                    return $fqcn;
                                })
                            ->end()
                        ->end()
                        ->scalarNode('entity_id_attr')
                            ->info('Name of the attribute holding the file identifier in the database entity.')
                            ->defaultValue('ref')
                        ->end()
                    ->end()
                ->end()
            ->end();
        return $treeBuilder;
    }
}
