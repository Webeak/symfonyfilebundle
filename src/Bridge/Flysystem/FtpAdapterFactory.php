<?php
namespace Webeak\Bundle\FileBundle\Bridge\Flysystem;

use League\Flysystem\Ftp\FtpAdapter;
use League\Flysystem\Ftp\FtpConnectionOptions;

class FtpAdapterFactory
{
    public static function create(array $config): FtpAdapter
    {
        $connectionOptions = new FtpConnectionOptions(
            $config['host'],
            $config['root'] ?? '/',
            $config['username'],
            $config['password'],
            $config['port'] ?? 21,
            $config['ssl'] ?? false,
            $config['timeout'] ?? 30,
            $config['passive'] ?? true
        );

        return new FtpAdapter($connectionOptions);
    }
}
