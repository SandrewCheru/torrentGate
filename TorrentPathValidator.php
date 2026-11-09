<?php

declare(strict_types=1);

namespace TorrentGate;

use InvalidArgumentException;

final class TorrentPathValidator
{
    private const MAX_COMPONENT_LENGTH = 255;
    private const MAX_PATH_LENGTH = 4096;
    private const MAX_DEPTH = 100;

    /**
     * Validate all names and file paths in a decoded torrent info dictionary.
     *
     * This validates both v1 and v2 metadata when both are present.
     */
    public static function validate(array $info): void
    {
        if (!isset($info['name']) || !is_string($info['name'])) {
            throw new InvalidArgumentException('Torrent name is missing or invalid.');
        }

        self::validateComponent($info['name'], 'Torrent name');

        if (array_key_exists('name.utf8', $info)) {
            if (!is_string($info['name.utf8'])) {
                throw new InvalidArgumentException('UTF-8 torrent name is invalid.');
            }

            self::validateComponent($info['name.utf8'], 'UTF-8 torrent name');
        }

        // Validate v1 multi-file paths.
        if (array_key_exists('files', $info)) {
            if (!is_array($info['files']) || !array_is_list($info['files'])) {
                throw new InvalidArgumentException('Torrent file list is invalid.');
            }

            foreach ($info['files'] as $index => $file) {
                if (!is_array($file)) {
                    throw new InvalidArgumentException(
                        "Torrent file entry {$index} is invalid."
                    );
                }

                // Validate both representations if both are present.
                foreach (['path', 'path.utf-8'] as $pathKey) {
                    if (!array_key_exists($pathKey, $file)) {
                        continue;
                    }

                    self::validatePath(
                        $file[$pathKey],
                        "Torrent file entry {$index}"
                    );
                }

                if (
                    !array_key_exists('path', $file) &&
                    !array_key_exists('path.utf-8', $file)
                ) {
                    throw new InvalidArgumentException(
                        "Torrent file entry {$index} has no path."
                    );
                }
            }
        }

        // Validate v2 file-tree paths, including hybrid torrents.
        if (array_key_exists('file tree', $info)) {
            self::validateFileTree($info['file tree'], [$info['name']], 0);
        }
    }

    private static function validatePath(mixed $path, string $context): void
    {
        if (!is_array($path) || !array_is_list($path) || $path === []) {
            throw new InvalidArgumentException(
                "{$context} contains an invalid path."
            );
        }

        $components = [];

        foreach ($path as $component) {
            self::validateComponent($component, $context);
            $components[] = $component;
        }

        self::validateCombinedPath($components, $context);
    }

    private static function validateFileTree(
        mixed $node,
        array $parentPath,
        int $depth
    ): void {
        if ($depth > self::MAX_DEPTH) {
            throw new InvalidArgumentException(
                'Torrent file tree exceeds the permitted depth.'
            );
        }

        if (!is_array($node) || $node === []) {
            throw new InvalidArgumentException(
                'Torrent contains an invalid or empty v2 file tree.'
            );
        }

        // An empty key represents a file leaf in the v2 file tree.
        if (array_key_exists('', $node)) {
            if (count($node) !== 1 || !is_array($node[''])) {
                throw new InvalidArgumentException(
                    'Torrent contains an invalid v2 file-tree leaf.'
                );
            }

            return;
        }

        foreach ($node as $component => $child) {
            if (!is_string($component)) {
                throw new InvalidArgumentException(
                    'Torrent file tree contains an invalid path component.'
                );
            }

            self::validateComponent($component, 'V2 file tree');

            $currentPath = [...$parentPath, $component];

            self::validateCombinedPath($currentPath, 'V2 file tree');

            self::validateFileTree($child, $currentPath, $depth + 1);
        }
    }

    private static function validateComponent(
        mixed $component,
        string $context
    ): void {
        if (!is_string($component) || $component === '') {
            throw new InvalidArgumentException(
                "{$context} contains an empty or non-string path component."
            );
        }

        if ($component === '.' || $component === '..') {
            throw new InvalidArgumentException(
                "{$context} contains a path traversal component."
            );
        }

        if (
            str_contains($component, '/') ||
            str_contains($component, '\\')
        ) {
            throw new InvalidArgumentException(
                "{$context} contains a path separator inside a component."
            );
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $component) === 1) {
            throw new InvalidArgumentException(
                "{$context} contains a control character."
            );
        }

        // Reject drive/alternate-stream syntax and ambiguous Windows paths.
        if (str_contains($component, ':')) {
            throw new InvalidArgumentException(
                "{$context} contains a colon in a path component."
            );
        }

        if (strlen($component) > self::MAX_COMPONENT_LENGTH) {
            throw new InvalidArgumentException(
                "{$context} contains an excessively long path component."
            );
        }
    }

    private static function validateCombinedPath(
        array $components,
        string $context
    ): void {
        $path = implode('/', $components);

        if (strlen($path) > self::MAX_PATH_LENGTH) {
            throw new InvalidArgumentException(
                "{$context} exceeds the permitted path length."
            );
        }
    }
}
