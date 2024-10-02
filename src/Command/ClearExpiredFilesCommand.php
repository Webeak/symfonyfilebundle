<?php
namespace Webeak\Bundle\FileBundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Webeak\Bundle\FileBundle\FileManager;

class ClearExpiredFilesCommand extends Command
{
    public function __construct(private readonly FileManager $fileManager, string $name = null)
    {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('wb:file:clear-expired')
             ->setDescription('Clears expired files');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->fileManager->clearExpiredFiles($output)) {
            return 0;
        }
        return 1;
    }
}
