<?php
/**
 * Ceebl RSForm CSV Export
 *
 * Copyright (C) 2026 Ceebl.
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Ceebl\Plugin\System\Csvprobe\Extension\Csvprobe;

return new class implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(PluginInterface::class, function (Container $container): PluginInterface {
            $plugin = new Csvprobe(
                $container->get('dispatcher'),
                (array) PluginHelper::getPlugin('system', 'csvprobe')
            );
            $plugin->setApplication(Factory::getApplication());
            return $plugin;
        });
    }
};
