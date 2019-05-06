<?php
namespace Webeak\Bundle\FileBundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Webeak\Bundle\FileBundle\FileManager;

class ClearExpiredFilesCommand extends Command
{
    /** @var FileManager */
    private $fileManager;

    public function __construct(FileManager $fileManager, string $name = null)
    {
        parent::__construct($name);
        $this->fileManager = $fileManager;
    }

    protected function configure()
    {
        $this->setName('wb:file:clear-expired')
             ->setDescription('Clears expired files');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->fileManager->clearExpiredFiles($output);
    }
}

