<?php

declare(strict_types=1);

namespace TorrentGate;

use InvalidArgumentException;

final class DestinationValidator
{
    public const ROOT = '/data2/transmission/seeding';

    private const MAX_RELATIVE_LENGTH = 1024;
    private const MAX_COMPONENT_LENGTH = 255;
    private const MAX_DEPTH = 100;
    private const MAX_ABSOLUTE_LENGTH = 4096;

    /**
     * Validate a relative destination directory.
     *
     * An empty string means the fixed root directory itself.
     */
    public static function validate(string $relativePath): string
    {
        if ($relativePath === '') {
            return '';
        }

        if (strlen($relativePath) > self::MAX_RELATIVE_LENGTH) {
            throw new InvalidArgumentException(
                'The destination subdirectory is too long.'
            );
        }

        if (preg_match('//u', $relativePath) !== 1) {
            throw new InvalidArgumentException(
                'The destination contains invalid text encoding.'
            );
        }

        if (
            str_starts_with($relativePath, '/') ||
            str_contains($relativePath, '\\')
        ) {
            throw new InvalidArgumentException(
                'The destination must be a relative directory path.'
            );
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $relativePath) === 1) {
            throw new InvalidArgumentException(
                'The destination contains control characters.'
            );
        }

        $components = explode('/', $relativePath);

        if (count($components) > self::MAX_DEPTH) {
            throw new InvalidArgumentException(
                'The destination has too many directory levels.'
            );
        }

        foreach ($components as $component) {
            if (
                $component === '' ||
                $component === '.' ||
                $component === '..'
            ) {
                throw new InvalidArgumentException(
                    'The destination contains an unsafe directory component.'
                );
            }

            if (strlen($component) > self::MAX_COMPONENT_LENGTH) {
                throw new InvalidArgumentException(
                    'A destination directory name is too long.'
                );
            }

            if (str_contains($component, ':')) {
                throw new InvalidArgumentException(
                    'The destination contains an invalid character.'
                );
            }
        }

        $absolutePath = self::ROOT . '/' . $relativePath;

        if (strlen($absolutePath) > self::MAX_ABSOLUTE_LENGTH) {
            throw new InvalidArgumentException(
                'The complete destination path is too long.'
            );
        }

        return $relativePath;
    }

    public static function absolutePath(string $relativePath): string
    {
        $validated = self::validate($relativePath);

        return $validated === ''
            ? self::ROOT
            : self::ROOT . '/' . $validated;
    }
}
