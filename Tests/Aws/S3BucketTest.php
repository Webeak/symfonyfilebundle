<?php

namespace Webeak\Bundle\FileBundle\Tests\Aws;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Webeak\Bundle\FileBundle\Aws\S3Bucket;

class S3BucketTest extends TestCase
{
    public function testStaticCredentialsRemainSupported(): void
    {
        $options = $this->getClientOptions([
            'region' => 'eu-west-3',
            'credentials_key' => 'access-key',
            'credentials_secret' => 'secret-key',
        ]);

        $this->assertSame('latest', $options['version']);
        $this->assertSame('eu-west-3', $options['region']);
        $this->assertSame([
            'key' => 'access-key',
            'secret' => 'secret-key',
        ], $options['credentials']);
    }

    public function testCredentialsAreOmittedToUseTheDefaultProviderChain(): void
    {
        $options = $this->getClientOptions([
            'region' => 'eu-west-3',
            'credentials_key' => null,
            'credentials_secret' => null,
        ]);

        $this->assertArrayNotHasKey('credentials', $options);
    }

    /**
     * @dataProvider provideIncompleteCredentials
     */
    public function testIncompleteStaticCredentialsAreRejected($key, $secret): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('AWS credentials key and secret must either both be provided or both omitted.');

        $this->getClientOptions([
            'region' => 'eu-west-3',
            'credentials_key' => $key,
            'credentials_secret' => $secret,
        ]);
    }

    public function provideIncompleteCredentials(): array
    {
        return [
            'missing key' => [null, 'secret-key'],
            'missing secret' => ['access-key', null],
        ];
    }

    private function getClientOptions(array $configuration): array
    {
        $reflection = new ReflectionClass(S3Bucket::class);
        $bucket = $reflection->newInstanceWithoutConstructor();

        $configurationProperty = $reflection->getProperty('configuration');
        $configurationProperty->setAccessible(true);
        $configurationProperty->setValue($bucket, $configuration);

        $method = $reflection->getMethod('getClientOptions');
        $method->setAccessible(true);

        return $method->invoke($bucket);
    }
}
