<?php
namespace Webeak\Bundle\FileBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: "wb_file_file"/*, options: ["engine" => "MyISAM"]*/)]
#[ORM\Index(columns: ["ref"], flags: ["fulltext"])]
class File extends AbstractFile
{

}
