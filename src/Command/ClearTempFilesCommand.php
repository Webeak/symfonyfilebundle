<?php
namespace Webeak\Bundle\FileBundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Webeak\Bundle\EssentialBundle\Exception\UsageException;

class ClearTempFilesCommand extends Command
{
    public function __construct(string $name = null)
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
        throw new UsageException('Not implemented yet');
        // $this->fileSystem->clearOldTemporaryFiles($output);
    }
}
