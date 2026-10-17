<?php
/** @package Ceebl CSV Probe */
namespace Ceebl\Plugin\System\Csvprobe\Extension;

defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;

final class Csvprobe extends CMSPlugin implements SubscriberInterface
{
    private const MANAGEMENT_GROUP_ID = 11;
    private const BOM = "\xEF\xBB\xBF";

    public static function getSubscribedEvents(): array
    {
        return [
            'onAfterRoute' => 'protectAndFormatDownload',
            'onBeforeCompileHead' => 'hideExportControls',
        ];
    }

    public function hideExportControls(Event $event): void
    {
        $app = $this->getApplication();
        if (!$app->isClient('site')) {
            return;
        }

        $user = $app->getIdentity();
        $groups = array_map('intval', $user->getAuthorisedGroups());
        if (!$user->guest && in_array(self::MANAGEMENT_GROUP_ID, $groups, true)) {
            return;
        }

        $document = $app->getDocument();
        if ($document->getType() !== 'html') {
            return;
        }

        // CSS applies to dynamically created buttons too; never alters the
        // directory itself. Server-side authorization remains mandatory.
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
        if (strtolower($input->getCmd('option')) !== 'com_rsform'
            || strtolower($input->getCmd('controller')) !== 'directory'
            || strtolower($input->getCmd('task')) !== 'download') {
            return;
        }

        // Guard the actual RSForm endpoint, not merely its download button.
        // Joomla's effective groups include inherited memberships.
        $user = $app->getIdentity();
        $groups = array_map('intval', $user->getAuthorisedGroups());
        if ($user->guest || !in_array(self::MANAGEMENT_GROUP_ID, $groups, true)) {
            throw new \RuntimeException('You are not authorized to download directory CSV files.', 403);
        }

        // The RSForm controller's own validation and CSRF protection remain in force.
        // No output manipulation for requests that cannot be the observed CSV POST.
        if (strtoupper($input->server->getString('REQUEST_METHOD')) !== 'POST') {
            return;
        }

        $firstCallback = true;
        $limitstart = max(0, $input->getInt('limitstart', 0));
        $callback = static function (string $content, int $phase) use (&$firstCallback, $limitstart): string {
            if (!$firstCallback) {
                return $content;
            }
            $firstCallback = false;

            $csv = false;
            foreach (headers_list() as $header) {
                if (stripos($header, 'Content-Type:') === 0 && stripos($header, 'text/csv') !== false) {
                    $csv = true;
                    break;
                }
            }
            if (!$csv || $limitstart !== 0 || $content === '') {
                return $content;
            }

            // Observed RSForm AJAX client strips one BOM when decoding response
            // text. Two BOMs on the wire leave one in the downloaded CSV.
            // Server multipart responses were compared against a full export.
            $prefix = strncmp($content, self::BOM, 3) === 0 ? self::BOM : self::BOM . self::BOM;
            return $prefix . $content;
        };

        // The non-removable outer buffer survives RSForm's ob_end_clean().
        ob_start($callback, 0, PHP_OUTPUT_HANDLER_STDFLAGS & ~PHP_OUTPUT_HANDLER_REMOVABLE);
        ob_start();
    }
}
