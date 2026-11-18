<?php

declare(strict_types=1);

namespace MaxServ\App\Import;

/**
 * How many dummyjson.com products to import, and how many to skip first.
 */
final readonly class ImportRequest
{
    public const int MIN_COUNT = 1;
    public const int MAX_COUNT = 200;
    public const int MIN_OFFSET = 0;
    public const int MAX_OFFSET = 10_000;
    private const int MAX_DIGITS = 5;

    /**
     * Creates the import request.
     *
     * @param int $count How many products to import, from MIN_COUNT to MAX_COUNT.
     * @param int $offset How many products to skip first, from MIN_OFFSET to MAX_OFFSET.
     *
     * @throws InvalidImportRequest The count or offset is out of range.
     */
    public function __construct(
        public int $count,
        public int $offset,
    ) {
        if ($count < self::MIN_COUNT || $count > self::MAX_COUNT || $offset < self::MIN_OFFSET || $offset > self::MAX_OFFSET) {
            throw new InvalidImportRequest(self::allowedValuesMessage());
        }
    }

    /**
     * Reads the import request from the form fields count and offset.
     *
     * @param array<string, mixed> $input The submitted form fields.
     *
     * @return self The import request.
     * @throws InvalidImportRequest A field is missing, not a whole number, or out of range.
     */
    public static function fromInput(array $input): self
    {
        return new self(
            self::readWholeNumber($input['count'] ?? null),
            self::readWholeNumber($input['offset'] ?? null),
        );
    }

    /**
     * Describes the allowed values for the user.
     *
     * @return string The message.
     */
    public static function allowedValuesMessage(): string
    {
        return sprintf(
            'Count must be a whole number from %d to %d, and offset a whole number from %d to %d.',
            self::MIN_COUNT,
            self::MAX_COUNT,
            self::MIN_OFFSET,
            self::MAX_OFFSET,
        );
    }

    private static function readWholeNumber(mixed $value): int
    {
        if (!is_string($value) || strlen($value) > self::MAX_DIGITS || !ctype_digit($value)) {
            throw new InvalidImportRequest(self::allowedValuesMessage());
        }

        return (int) $value;
    }
}
