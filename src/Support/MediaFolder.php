<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Support;

/**
 * Parses a folder of images named `<sku>_<position>.<ext>` (the common FTP-drop
 * convention) into media slots grouped by SKU and ordered by position — where
 * position 1 is the main image, 2 the second, and so on.
 *
 *   foreach (MediaFolder::scan('/ftp/incoming') as $sku => $slots) {
 *       $id = $shopify->products()->findIdBySku($sku);
 *       if ($id !== null) {
 *           $shopify->products()->syncMedia($id, $slots);
 *       }
 *   }
 *
 * The trailing `_<digits>` is the position; everything before it is the SKU (so
 * SKUs may themselves contain underscores). Non-image files and names without a
 * trailing position are ignored. The slot key is the full filename, so re-dropping
 * a file with the same name replaces that image rather than duplicating it.
 */
final class MediaFolder
{
    /** @var list<string> */
    public const DEFAULT_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    /**
     * @param list<string> $extensions
     * @return array<string,list<MediaEntry>> sku => slots, ordered by position
     */
    public static function scan(string $dir, array $extensions = self::DEFAULT_EXTENSIONS): array
    {
        if (!is_dir($dir)) {
            throw new \InvalidArgumentException("Not a directory: {$dir}");
        }

        $allowed = array_map('strtolower', $extensions);
        $bySku   = [];

        foreach (scandir($dir) ?: [] as $entry) {
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (!is_file($path)) {
                continue;
            }

            $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed, true)) {
                continue;
            }

            $name = pathinfo($entry, PATHINFO_FILENAME);
            if (!preg_match('/^(?<sku>.+)_(?<pos>\d+)$/', $name, $m)) {
                continue;
            }

            $bySku[$m['sku']][] = MediaEntry::file((int) $m['pos'], $path, $entry);
        }

        foreach ($bySku as &$slots) {
            usort($slots, static fn (MediaEntry $a, MediaEntry $b): int => $a->position() <=> $b->position());
        }
        unset($slots);

        ksort($bySku);

        return $bySku;
    }
}
