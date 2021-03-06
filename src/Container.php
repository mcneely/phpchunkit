<?php

declare(strict_types = 1);

namespace PHPChunkit;

use PHPChunkit\Command;
use PHPChunkit\GenerateTestClass;
use Pimple\Container as PimpleContainer;
use Symfony\Component\Console\Application;

class Container extends PimpleContainer
{
    const NAME = 'PHPChunkit';
    const VERSION = '0.0.1';

    public function initialize()
    {
        $this['phpchunkit.configuration'] = function() {
            return $this->getConfiguration();
        };

        $this['phpchunkit.symfony_application'] = function() {
            return new Application(self::NAME, self::VERSION);
        };

        $this['phpchunkit.application'] = function() {
            return new PHPChunkitApplication($this);
        };

        $this['phpchunkit.database_sandbox'] = function() {
            return $this['phpchunkit.configuration']->getDatabaseSandbox();
        };

        $this['phpchunkit.event_dispatcher'] = function() {
            return $this['phpchunkit.configuration']->getEventDispatcher();
        };

        $this['phpchunkit.test_chunker'] = function() {
            return new TestChunker($this['phpchunkit.test_counter']);
        };

        $this['phpchunkit.test_runner'] = function() {
            return new TestRunner(
                $this['phpchunkit.symfony_application'],
                $this['phpchunkit.application.input'],
                $this['phpchunkit.application.output'],
                $this['phpchunkit.configuration']
            );
        };

        $this['phpchunkit.test_counter'] = function() {
            return new TestCounter(
                $this['phpchunkit.file_classes_helper']
            );
        };

        $this['phpchunkit.test_finder'] = function() {
            return new TestFinder(
                $this['phpchunkit.configuration']->getTestsDirectory()
            );
        };

        $this['phpchunkit.command.setup'] = function() {
            return new Command\Setup();
        };

        $this['phpchunkit.command.test_watcher'] = function() {
            return new Command\TestWatcher(
                $this['phpchunkit.test_runner'],
                $this['phpchunkit.configuration'],
                $this['phpchunkit.file_classes_helper']
            );
        };

        $this['phpchunkit.command.run'] = function() {
            return new Command\Run(
                $this['phpchunkit.test_runner'],
                $this['phpchunkit.configuration'],
                $this['phpchunkit.test_chunker'],
                $this['phpchunkit.test_finder']
            );
        };

        $this['phpchunkit.command.create_databases'] = function() {
            return new Command\CreateDatabases($this['phpchunkit.event_dispatcher']);
        };

        $this['phpchunkit.command.build_sandbox'] = function() {
            return new Command\BuildSandbox(
                $this['phpchunkit.test_runner'],
                $this['phpchunkit.event_dispatcher']
            );
        };

        $this['phpchunkit.command.generate_test'] = function() {
            return new Command\Generate(new GenerateTestClass());
        };

        $this['phpchunkit.file_classes_helper'] = function() {
            return new FileClassesHelper();
        };
    }

    private function getConfiguration() : Configuration
    {
        $configuration = $this->loadConfiguration();

        $this->loadPHPChunkitBootstrap($configuration);

        // try to guess watch directories
        if (!$configuration->getWatchDirectories()) {
            $paths = [
                sprintf('%s/src', $configuration->getRootDir()),
                sprintf('%s/lib', $configuration->getRootDir()),
                sprintf('%s/tests', $configuration->getRootDir()),
            ];

            $watchDirectories = [];
            foreach ($paths as $path) {
                if (is_dir($path)) {
                    $watchDirectories[] = $path;
                }
            }

            $configuration->setWatchDirectories($watchDirectories);
        }

        // try to guess tests directory
        if (!$configuration->getTestsDirectory()) {
            $paths = [
                sprintf('%s/tests', $configuration->getRootDir()),
                sprintf('%s/src', $configuration->getRootDir()),
                sprintf('%s/lib', $configuration->getRootDir()),
            ];

            foreach ($paths as $path) {
                if (is_dir($path)) {
                    $configuration->setTestsDirectory($path);
                    break;
                }
            }
        }

        return $configuration;
    }

    private function loadConfiguration() : Configuration
    {
        $xmlPath = $this->findPHPChunkitXmlPath();

        $configuration = $xmlPath
            ? Configuration::createFromXmlFile($xmlPath)
            : new Configuration()
        ;

        $configuration->setSandboxEnabled($this->isSandboxEnabled());

        if (!$configuration->getRootDir()) {
            $configuration->setRootDir($this['phpchunkit.root_dir']);
        }

        return $configuration;
    }

    private function isSandboxEnabled() : bool
    {
        return array_filter($_SERVER['argv'], function($arg) {
            return strpos($arg, 'sandbox') !== false;
        }) ? true : false;
    }

    /**
     * @return null|string
     */
    private function findPHPChunkitXmlPath()
    {
        if (file_exists($distXmlPath = $this['phpchunkit.root_dir'].'/phpchunkit.xml.dist')) {
            return $distXmlPath;
        }

        if (file_exists($defaultXmlPath = $this['phpchunkit.root_dir'].'/phpchunkit.xml')) {
            return $defaultXmlPath;
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    private function loadPHPChunkitBootstrap(Configuration $configuration)
    {
        if ($bootstrapPath = $configuration->getBootstrapPath()) {
            if (!file_exists($bootstrapPath)) {
                throw new \InvalidArgumentException(
                    sprintf('Bootstrap path "%s" does not exist.', $bootstrapPath)
                );
            }

            require_once $bootstrapPath;
        } else {
            $autoloaderPath = sprintf('%s/vendor/autoload.php', $configuration->getRootDir());

            if (file_exists($autoloaderPath)) {
                require_once $autoloaderPath;
            }
        }
    }
}
