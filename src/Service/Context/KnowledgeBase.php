<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Service\Context;

use Pimcore\Model\Asset;
use Tsf\GatekeeperAiBundle\Model\AssembledContext;
use Tsf\GatekeeperAiBundle\Service\Config\Settings;
use Tsf\GatekeeperAiBundle\Service\TokenEstimator;

use function count;
use function is_string;

/**
 * Assembles the knowledge base from the Markdown assets of the configured folder: the .md files
 * directly in the folder and in _global/ always, those in <ClassName>/ for the class being
 * enriched. Files are concatenated in path order, each under a "## <path>" heading, so the text
 * - and with it the hash - is the same for the same files every time.
 */
class KnowledgeBase
{
    public const GLOBAL_FOLDER = '_global';

    public const EXTENSION = '.md';

    public function __construct(
        private readonly Settings $settings,
        private readonly TokenEstimator $estimator,
    ) {
    }

    public function assemble(?string $className = null): AssembledContext
    {
        $files = $this->readFiles($this->folders($className));
        ksort($files, SORT_STRING);

        $blocks = [];
        foreach ($files as $path => $content) {
            $content = trim(str_replace(["\r\n", "\r"], "\n", $content));
            $blocks[] = '## ' . $path . "\n\n" . $content;
        }

        $text = count($blocks) > 0 ? implode("\n\n", $blocks) . "\n" : '';

        return new AssembledContext($text, array_keys($files), $this->estimator->estimate($text));
    }

    /**
     * Whether the configured folder exists in the asset tree at all
     */
    public function folderExists(): bool
    {
        return $this->loadFolder($this->settings->getContextFolder()) instanceof Asset\Folder;
    }

    /**
     * Asset paths (with trailing slash, as the assets table stores them) that are read
     *
     * @return string[]
     */
    private function folders(?string $className): array
    {
        $root = rtrim($this->settings->getContextFolder(), '/') . '/';
        $folders = [$root, $root . self::GLOBAL_FOLDER . '/'];
        if ($className !== null && $className !== '') {
            $folders[] = $root . $className . '/';
        }

        return $folders;
    }

    /**
     * The .md files directly inside the given folders, keyed by their path relative to the
     * context folder
     *
     * @param string[] $folders
     *
     * @return array<string, string>
     */
    protected function readFiles(array $folders): array
    {
        $root = rtrim($this->settings->getContextFolder(), '/') . '/';

        $listing = new Asset\Listing();
        $listing->setCondition(
            'path IN (' . implode(', ', array_fill(0, count($folders), '?')) . ') AND LOWER(filename) LIKE ?',
            [...$folders, '%' . self::EXTENSION]
        );
        $listing->setOrderKey(['path', 'filename']);
        $listing->setOrder('ASC');

        $files = [];
        foreach ($listing->load() as $asset) {
            if ($asset instanceof Asset\Folder) {
                continue;
            }
            $data = $asset->getData();
            if (!is_string($data)) {
                continue;
            }
            $relative = substr($asset->getRealFullPath(), strlen($root));
            $files[$relative] = $data;
        }

        return $files;
    }

    protected function loadFolder(string $path): ?Asset
    {
        try {
            return Asset::getByPath($path);
        } catch (\Throwable) {
            return null;
        }
    }
}
