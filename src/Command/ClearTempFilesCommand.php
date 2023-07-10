<?php
namespace Webeak\Bundle\FileBundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Webeak\Bundle\FileBundle\FileSystem\FileSystemInterface;

class ClearTempFilesCommand extends Command
{
    public function __construct(private readonly FileSystemInterface $fileSystem, string $name = null)
    {
        parent::__construct($name);
    }

    protected function configure()
    {
        $this->setName('wb:file:clear')
             ->setDescription('Clears temporary files');
    }

    protected function execute(InputInterface $input, OutputInterface $output): void
    {
        $this->fileSystem->clearOldTemporaryFiles($output);
    }
}
