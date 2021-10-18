#!/usr/bin/env php
<?php
require __DIR__.'/vendor/autoload.php';

use App\Command\PhotosToElasticmsCommand;
use Symfony\Component\Console\Application;

$application = new Application('photos-to-elasticms', '1.0.0');
$command = new PhotosToElasticmsCommand();

$application->add($command);
$commandName = $command->getName();
if (null === $commandName) {
    throw new \RuntimeException('Unexpected null command name');
}

$application->setDefaultCommand($commandName, true);
$application->run();