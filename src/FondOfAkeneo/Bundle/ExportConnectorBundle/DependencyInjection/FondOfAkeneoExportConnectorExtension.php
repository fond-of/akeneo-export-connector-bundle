<?php

namespace FondOfAkeneo\Bundle\ExportConnectorBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

class FondOfAkeneoExportConnectorExtension extends Extension
{

    /**
     * Loads a specific configuration.
     *
     * @param array $configs
     * @param ContainerBuilder $container
     * s
     * @throws \Exception
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        /** @var YamlFileLoader $loader */
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));

        $loader->load('jobs.yml');
        $loader->load('job_defaults.yml');
        $loader->load('steps.yml');
        $loader->load('processors.yml');
        $loader->load('providers.yml');
    }
}
