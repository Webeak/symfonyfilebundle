<?php
namespace Webeak\Bundle\FileBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Webeak\Bundle\FileBundle\FileSystem;

class ClearTempFilesCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this->setName('wb:file:clear')
            ->setDescription('Clears temporary files');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $manager = $this->getContainer()->get(FileSystem::class);
        $manager->clearOldTemporaryFiles($output);
    }
}

