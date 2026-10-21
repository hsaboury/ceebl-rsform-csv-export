<?php
/**
 * Joomlog RSForm CSV Export
 *
 * Copyright (C) 2026 Joomlog.
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Ceebl\Plugin\System\Csvprobe\Extension;

defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;

final class Csvprobe extends CMSPlugin implements SubscriberInterface
{
    private const BOM = "\xEF\xBB\xBF";

    public static function getSubscribedEvents(): array
    {
        return [
            'onAfterRoute' => 'protectAndFormatDownload',
            'onBeforeCompileHead' => 'hideExportControls',
        ];
    }

    private function isDownloadRestricted(): bool
    {
        return (bool) $this->params->get('restrict_downloads', 0);
    }

    private function canDownload(): bool
    {
        if (!$this->isDownloadRestricted()) {
            return true;
        }

        $user = $this->getApplication()->getIdentity();

        if ($user->guest) {
            return false;
        }

        $allowed = $this->params->get('allowed_groups', []);
        $allowed = array_values(array_filter(
            array_map('intval', (array) $allowed),
            static fn (int $id): bool => $id > 0
        ));

        if ($allowed === []) {
            return false;
        }

        $groups = array_map('intval', $user->getAuthorisedGroups());

        return (bool) array_intersect($allowed, $groups);
    }

    public function hideExportControls(Event $event): void
    {
        $app = $this->getApplication();

        if (!$app->isClient('site')
            || !$this->isDownloadRestricted()
            || !(bool) $this->params->get('hide_export_buttons', 1)
            || $this->canDownload()) {
            return;
        }

        $document = $app->getDocument();

        if ($document->getType() !== 'html') {
            return;
        }

        $document->addStyleDeclaration(
            '[data-directory-click="downloadFullCSV"],'
            . '[data-directory-click="downloadCSV"],'
            . '[data-directory-click="downloadSelectedCSV"],'
            . '[data-directory-click*="CSV"]{display:none !important;}'
        );
    }

    public function protectAndFormatDownload(Event $event): void
    {
        $app = $this->getApplication();

        if (!$app->isClient('site')) {
            return;
        }

        $input = $app->getInput();

        if (strtolower($input->getCmd('option', '')) !== 'com_rsform'
            || strtolower($input->getCmd('controller', '')) !== 'directory'
            || strtolower($input->getCmd('task', '')) !== 'download') {
            return;
        }

        if (!$this->canDownload()) {
            throw new \RuntimeException(
                'You are not authorized to download directory CSV files.',
                403
            );
        }

        if (!(bool) $this->params->get('enable_encoding_fix', 1)) {
            return;
        }

        if (strtoupper($input->server->getString('REQUEST_METHOD')) !== 'POST') {
            return;
        }

        $firstCallback = true;
        $limitstart = max(0, $input->getInt('limitstart', 0));

        $callback = static function (
            string $content,
            int $phase
        ) use (&$firstCallback, $limitstart): string {
            if (!$firstCallback) {
                return $content;
            }

            $firstCallback = false;

            $csv = false;

            foreach (headers_list() as $header) {
                if (stripos($header, 'Content-Type:') === 0
                    && stripos($header, 'text/csv') !== false) {
                    $csv = true;
                    break;
                }
            }

            if (!$csv || $limitstart !== 0 || $content === '') {
                return $content;
            }

            // RSForm's observed AJAX client strips one BOM.
            $prefix = strncmp($content, self::BOM, 3) === 0
                ? self::BOM
                : self::BOM . self::BOM;

            return $prefix . $content;
        };

        ob_start(
            $callback,
            0,
            PHP_OUTPUT_HANDLER_STDFLAGS & ~PHP_OUTPUT_HANDLER_REMOVABLE
        );

        ob_start();
    }
}
