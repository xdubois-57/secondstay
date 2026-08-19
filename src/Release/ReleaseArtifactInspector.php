<?php

declare(strict_types=1);

namespace SecondStay\Release;

use RuntimeException;
use ZipArchive;

final class ReleaseArtifactInspector
{
    /**
     * @return list<string> raisons de rejet ; tableau vide = artefact conforme
     */
    public function inspect(string $zipPath): array
    {
        return ReleaseArtifactPolicy::validate($this->entries($zipPath));
    }

    /**
     * @return list<string>
     */
    public function entries(string $zipPath): array
    {
        if (!is_file($zipPath)) {
            throw new RuntimeException('Archive introuvable : ' . $zipPath);
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Archive illisible : ' . $zipPath);
        }

        $entries = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            if ($name === false || str_ends_with($name, '/')) {
                continue;
            }
            $entries[] = $name;
        }
        $zip->close();

        sort($entries);

        return $entries;
    }
}
