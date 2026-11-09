<?php

declare(strict_types=1);

namespace TorrentGate;

/**
 * Strict, bounded Bencode scanner.
 *
 * Scans the original byte stream without decoding or re-encoding it.
 * Returns the exact byte range of the top-level "info" dictionary.
 */
final class BencodeScanner
{
    private const MAX_DEPTH = 100;
    private const MAX_NODES = 1_000_000;

    private string $data;
    private int $length;
    private int $position = 0;
    private int $nodes = 0;

    private ?int $infoOffset = null;
    private ?int $infoLength = null;

    private function __construct(string $data)
    {
        $this->data = $data;
        $this->length = strlen($data);
    }

    /**
     * Validate a complete torrent's Bencode structure.
     *
     * @return array{
     *     info_offset: int,
     *     info_length: int,
     *     input_length: int
     * }
     *
     * @throws \InvalidArgumentException
     */
    public static function scan(string $data): array
    {
        if ($data === '') {
            throw new \InvalidArgumentException('The torrent file is empty.');
        }

        $scanner = new self($data);

        if ($scanner->peek() !== 'd') {
            throw new \InvalidArgumentException(
                'The torrent root must be a Bencode dictionary.'
            );
        }

        $scanner->parseDictionary(0, true);

        if ($scanner->position !== $scanner->length) {
            throw new \InvalidArgumentException(
                'Unexpected trailing data after the root dictionary.'
            );
        }

        if ($scanner->infoOffset === null || $scanner->infoLength === null) {
            throw new \InvalidArgumentException(
                'The torrent does not contain an info dictionary.'
            );
        }

        return [
            'info_offset' => $scanner->infoOffset,
            'info_length' => $scanner->infoLength,
            'input_length' => $scanner->length,
        ];
    }

    private function peek(): ?string
    {
        if ($this->position >= $this->length) {
            return null;
        }

        return $this->data[$this->position];
    }

    private function countNode(): void
    {
        ++$this->nodes;

        if ($this->nodes > self::MAX_NODES) {
            throw new \InvalidArgumentException(
                'The torrent contains too many Bencode values.'
            );
        }
    }

    /**
     * Parse a Bencode value.
     *
     * Returns its type: integer, string, list or dictionary.
     */
    private function parseValue(int $depth): string
    {
        if ($depth > self::MAX_DEPTH) {
            throw new \InvalidArgumentException(
                'The torrent exceeds the maximum Bencode nesting depth.'
            );
        }

        $this->countNode();

        $type = $this->peek();

        if ($type === null) {
            throw new \InvalidArgumentException(
                'Unexpected end of torrent data.'
            );
        }

        if ($type === 'i') {
            $this->parseInteger();
            return 'integer';
        }

        if ($type === 'l') {
            $this->parseList($depth);
            return 'list';
        }

        if ($type === 'd') {
            $this->parseDictionary($depth, false);
            return 'dictionary';
        }

        if ($type >= '0' && $type <= '9') {
            $this->readByteString(false);
            return 'string';
        }

        throw new \InvalidArgumentException(
            sprintf('Invalid Bencode value marker at byte %d.', $this->position)
        );
    }

    private function parseInteger(): void
    {
        ++$this->position; // Skip "i".

        $end = strpos($this->data, 'e', $this->position);

        if ($end === false) {
            throw new \InvalidArgumentException(
                'Unterminated Bencode integer.'
            );
        }

        $value = substr(
            $this->data,
            $this->position,
            $end - $this->position
        );

        if (!preg_match('/\A(?:0|[1-9][0-9]*|-[1-9][0-9]*)\z/', $value)) {
            throw new \InvalidArgumentException(
                'Invalid or non-canonical Bencode integer.'
            );
        }

        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new \InvalidArgumentException(
                'Bencode integer exceeds the supported integer range.'
            );
        }

        $this->position = $end + 1;
    }

    private function readByteString(bool $capture = true): ?string
    {
        $start = $this->position;
        $colon = strpos($this->data, ':', $start);

        if ($colon === false) {
            throw new \InvalidArgumentException(
                'Bencode byte string is missing its length separator.'
            );
        }

        $lengthText = substr($this->data, $start, $colon - $start);

        if (!preg_match('/\A(?:0|[1-9][0-9]*)\z/', $lengthText)) {
            throw new \InvalidArgumentException(
                'Invalid or non-canonical Bencode byte string length.'
            );
        }

        $byteLength = filter_var($lengthText, FILTER_VALIDATE_INT);

        if ($byteLength === false) {
            throw new \InvalidArgumentException(
                'Bencode byte string length exceeds the supported range.'
            );
        }

        $contentStart = $colon + 1;
        $remaining = $this->length - $contentStart;

        if ($byteLength > $remaining) {
            throw new \InvalidArgumentException(
                'Bencode byte string extends beyond the end of the input.'
            );
        }

	$this->position = $contentStart + $byteLength;

        if (!$capture) {
            return null;
        }

	return substr($this->data, $contentStart, $byteLength);
    }

    private function parseList(int $depth): void
    {
        ++$this->position; // Skip "l".

        while (true) {
            $marker = $this->peek();

            if ($marker === null) {
                throw new \InvalidArgumentException(
                    'Unterminated Bencode list.'
                );
            }

            if ($marker === 'e') {
                ++$this->position;
                return;
            }

            $this->parseValue($depth + 1);
        }
    }

    private function parseDictionary(int $depth, bool $isRoot): void
    {
        ++$this->position; // Skip "d".

        $previousKey = null;
        $seenKeys = [];

        while (true) {
            $marker = $this->peek();

            if ($marker === null) {
                throw new \InvalidArgumentException(
                    'Unterminated Bencode dictionary.'
                );
            }

            if ($marker === 'e') {
                ++$this->position;
                return;
            }

            if ($marker < '0' || $marker > '9') {
                throw new \InvalidArgumentException(
                    'Bencode dictionary keys must be byte strings.'
                );
            }

            $this->countNode();
            $key = $this->readByteString();

            // Bencode dictionaries must have keys in strictly ascending byte order.
            // This also rejects duplicate keys without retaining every key in memory.
            if ($previousKey !== null && strcmp($previousKey, $key) >= 0) {
                throw new \InvalidArgumentException(
                   'Bencode dictionary keys are duplicate or not in canonical order.'
                );
            }

            $previousKey = $key;

            $valueStart = $this->position;

            if ($isRoot && $key === 'info') {
                if ($this->peek() !== 'd') {
                    throw new \InvalidArgumentException(
                        'The top-level info value must be a dictionary.'
                    );
                }

                $this->infoOffset = $valueStart;
                $this->parseValue($depth + 1);
                $this->infoLength = $this->position - $valueStart;
            } else {
                $this->parseValue($depth + 1);
            }
        }
    }
}
