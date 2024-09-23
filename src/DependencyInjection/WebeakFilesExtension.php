<?php
namespace Webeak\Bundle\FileBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\Image;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Parser as YamlParser;
use Webeak\Bundle\EssentialBundle\Exception\UsageException;
use Webeak\Bundle\FileBundle\Constraint\PdfConstraint;
use Webeak\Component\Utils\ArrayUtils;

/**
 * This is the class that loads and manages your bundle configuration.
 *
 * @link http://symfony.com/doc/current/cookbook/bundles/extension.html
 */
class WebeakFilesExtension extends Extension implements PrependExtensionInterface
{
    /**
     * {@inheritdoc}
     */
    public function getAlias(): string
    {
        return 'wb_file';
    }

    /**
     * {@inheritdoc}
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $aliases = $this->processConstraintsAliases($config);
        $presets = $this->processPresets($config, $aliases);
        $container->setParameter('wb.file.constraints_aliases', $aliases);
        $container->setParameter('wb.file.configuration_presets', $presets);
        $container->setParameter('wb.file.temp_files_lifetime', $config['temp_files_lifetime']);
        $container->setParameter('wb.file.not_found_image_path', $config['not_found_image_path']);
        $container->setParameter('wb.file.access_denied_image_path', $config['access_denied_image_path']);
        $container->setParameter('wb.file.default_storage', $config['default_storage']);
        $container->setParameter('wb.file.storages', ArrayUtils::getValue($config, 'storages', []));
        $container->setParameter('wb.file.default_filesystem', $config['default_filesystem']);
        $container->setParameter('wb.file.filesystems', ArrayUtils::getValue($config, 'filesystems', []));

        $loader = new Loader\YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.yaml');
    }

    /**
     * {@inheritdoc}
     */
    public function prepend(ContainerBuilder $container): void
    {
        $yamlParser = new YamlParser();
        $locator = new FileLocator(__DIR__.'/../Resources/config');
        $file = $locator->locate('doctrine.yaml');
        try {
            $doctrineConfig = $yamlParser->parse(file_get_contents($file));
        } catch (ParseException $e) {
            throw new InvalidConfigurationException(sprintf('The file "%s" does not contain valid YAML.', $file), 0, $e);
        }
        $container->prependExtensionConfig('doctrine', $doctrineConfig['doctrine']);
    }

    /**
     * Merge custom constraints defined in the config.yml with default ones
     * and ensure they exist and are valid.
     *
     * @throws
     */
    private function processConstraintsAliases(array $config): array
    {
        $aliases = array_merge([
            'file' => File::class,
            'image' => Image::class,
            'pdf' => PdfConstraint::class
        ], (array)$config['constraints_aliases']);

        foreach ($aliases as $name => $fqcn) {
            $this->ensureValidConstraint($fqcn);
        }
        return $aliases;
    }

    /**
     * Check the validity of preset definitions and create aliases
     * for constraint directly applied using their FQCN.
     *
     * @param array $config
     * @param array $aliases
     *
     * @return array
     *
     * @throws
     */
    private function processPresets(array $config, array &$aliases): array
    {
        if (!array_key_exists('configuration_presets', $config)) {
            return [];
        }
        $generatedCount = 0;
        foreach ($config['configuration_presets'] as $name => &$data) {
            if (array_key_exists('constraints', $data)) {
                $toRemove = [];
                foreach ($data['constraints'] as $cname => $cdata) {
                    if (!array_key_exists($cname, $aliases)) {
                        if (str_contains($cname, '/') || str_contains($cname, '\\')) {
                            $fqcn = str_replace('/', '\\', $cname);
                            $this->ensureValidConstraint($fqcn);
                            $alias = '__cemf_a'.$generatedCount;
                            $aliases[$alias] = $fqcn;
                            $data['constraints'][$alias] = $cdata;
                            $toRemove[] = $cname;
                        } else {
                            throw new UsageException(sprintf(
                                'Constraint "%s" not found. '.
                                'Create an alias or set the FQCN of the target class.',
                                $cname
                            ));
                        }
                    }
                }
                for ($i = 0, $ii = count($toRemove); $i < $ii; ++$i) {
                    unset($data['constraints'][$toRemove[$i]]);
                }
            }
        }
        return $config['configuration_presets'];
    }

    /**
     * Ensure the class exists and is a subclass of the symfony base class.
     *
     * @throws
     */
    private function ensureValidConstraint(string $fqcn): void
    {
        $refl = new \ReflectionClass($fqcn);
        if (!$refl->isSubclassOf(Constraint::class)) {
            throw new InvalidConfigurationException(sprintf(
                'Constraint "%s" is not a subclass of "%s".',
                $fqcn, Constraint::class
            ));
        }
    }
}
