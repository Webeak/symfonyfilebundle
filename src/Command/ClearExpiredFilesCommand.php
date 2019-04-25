<?php
namespace Webeak\Bundle\FileBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Webeak\Bundle\FileBundle\FileManager;

class ClearExpiredFilesCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this->setName('wb:files:clear-expired')
            ->setDescription('Clears expired files');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $manager = $this->getContainer()->get(FileManager::class);
        $manager->clearExpiredFiles($output);
    }
}

