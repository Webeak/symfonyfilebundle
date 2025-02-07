<?php
namespace Webeak\Bundle\FileBundle\DependencyInjection\Compiler;

use Aws\S3\S3Client;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem;
use League\Flysystem\Ftp\FtpAdapter;
use League\Flysystem\Ftp\FtpConnectionOptions;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\PhpseclibV3\SftpAdapter;
use League\Flysystem\PhpseclibV3\SftpConnectionProvider;
use League\Flysystem\UnixVisibility\PortableVisibilityConverter;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Webeak\Bundle\FileBundle\FileManager;
use Webeak\Bundle\FileBundle\FileSystem\FileSystemCollection;

class RegisterFileSystemPass implements CompilerPassInterface
{
    /**
     * @inheritDoc
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(FileManager::class) || !$container->hasParameter('wb.file.filesystems')) {
            return;
        }

        $fileSystemsConfig = $container->getParameter('wb.file.filesystems');
        foreach ($fileSystemsConfig as $key => $config) {
            $serviceId = sprintf('wb_file.filesystem.%s', $key);
            $adapterServiceId = sprintf('wb_file.adapter.%s', $key);

            switch ($config['type']) {
                case 'local':
                    $container
                        ->register($adapterServiceId, LocalFilesystemAdapter::class)
                        ->addArgument($config['save_path']);
                    break;

                case 'aws_s3':
                    $s3ClientServiceId = sprintf('wb_file.s3_client.%s', $key);
                    $container
                        ->register($s3ClientServiceId, S3Client::class)
                        ->addArgument([
                            'region' => $config['region'],
                            'version' => 'latest',
                            'credentials' => [
                                'key' => $config['key'],
                                'secret' => $config['secret'],
                            ],
                        ]);
                    $container
                        ->register($adapterServiceId, AwsS3V3Adapter::class)
                        ->addArgument(new Reference($s3ClientServiceId))
                        ->addArgument($config['bucket'])
                        ->addArgument($config['save_path']);
                    break;

                case 'ftp':
                    $ftpConnectionOptionsServiceId = sprintf('wb_file.ftp_connection_options.%s', $key);
                    $container
                        ->register($ftpConnectionOptionsServiceId, FtpConnectionOptions::class)
                        ->addArgument($config['host'])
                        ->addArgument($config['save_path'] ?? '/')
                        ->addArgument($config['username'])
                        ->addArgument($config['password'])
                        ->addArgument($config['port'] ?? 21)
                        ->addArgument($config['ssl'] ?? false)
                        ->addArgument($config['timeout'] ?? 30)
                        ->addArgument($config['passive'] ?? true);

                    $container
                        ->register($adapterServiceId, FtpAdapter::class)
                        ->addArgument(new Reference($ftpConnectionOptionsServiceId));
                    break;


                case 'sftp':
                    $sftpConnectionProviderServiceId = sprintf('wb_file.sftp_connection_provider.%s', $key);
                    $container
                        ->register($sftpConnectionProviderServiceId, SftpConnectionProvider::class)
                        ->addArgument($config['host'])
                        ->addArgument($config['username'])
                        ->addArgument($config['password'])
                        ->addArgument($config['private_key'])
                        ->addArgument($config['passphrase'])
                        ->addArgument($config['port'])
                        ->addArgument(false)    // useAgent
                        ->addArgument($config['timeout'] ?? 30)
                        ->addArgument(3)        // max tries
                        ->addArgument(null)     // host fingerprint
                        ->addArgument(null);    // connectivity checker

                    $visibility = $config['visibility'] ?? 'private';
                    $permissions = $config['permissions'] ?? [
                        'file' => ['public' => 0644, 'private' => 0600],
                        'dir' => ['public' => 0755, 'private' => 0700],
                    ];
                    $visibilityConverterServiceId = sprintf('wb_file.sftp_visibility_converter.%s', $key);
                    $container
                        ->register($visibilityConverterServiceId, PortableVisibilityConverter::class)
                        ->addArgument($permissions['file']['public'])
                        ->addArgument($permissions['file']['private'])
                        ->addArgument($permissions['dir']['public'])
                        ->addArgument($permissions['dir']['private']);

                    $container
                        ->register($adapterServiceId, SftpAdapter::class)
                        ->addArgument(new Reference($sftpConnectionProviderServiceId))
                        ->addArgument($config['save_path'] ?? '/')
                        ->addArgument(new Reference($visibilityConverterServiceId));
                    break;

                default:
                    throw new \InvalidArgumentException(sprintf('Unknown filesystem type "%s"', $config['type']));
            }

            $container
                ->register($serviceId, Filesystem::class)
                ->addArgument(new Reference($adapterServiceId));
        }

        // Register FileSystemCollection with all the registered filesystems
        $filesystemsServicesReferences = [];
        foreach ($fileSystemsConfig as $key => $config) {
            $serviceId = sprintf('wb_file.filesystem.%s', $key);
            $filesystemsServicesReferences[$key] = new Reference($serviceId);
        }

        $container->register(FileSystemCollection::class, FileSystemCollection::class)
            ->addArgument($filesystemsServicesReferences);
    }
}
