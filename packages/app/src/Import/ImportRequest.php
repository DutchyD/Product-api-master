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
    public const string FIELD_COUNT = 'count';
    public const string FIELD_OFFSET = 'offset';
    private const int MAX_DIGITS = 5;

    /**
     * Creates the import request.
     *
     * @param int $count How many products to import, from MIN_COUNT to MAX_COUNT.
     * @param int $offset How many products to skip first, from MIN_OFFSET to MAX_OFFSET.
     *
     * @throws InvalidImportRequest The count or offset is out of range; it names every field that is.
     */
    public function __construct(
        public int $count,
        public int $offset,
    ) {
        $fieldErrors = array_filter([
            self::FIELD_COUNT => self::inRange($count, self::MIN_COUNT, self::MAX_COUNT)
                ? null
                : self::rangeMessage(self::MIN_COUNT, self::MAX_COUNT),
            self::FIELD_OFFSET => self::inRange($offset, self::MIN_OFFSET, self::MAX_OFFSET)
                ? null
                : self::rangeMessage(self::MIN_OFFSET, self::MAX_OFFSET),
        ]);

        if ($fieldErrors !== []) {
            throw new InvalidImportRequest($fieldErrors);
        }
    }

    /**
     * Reads the import request from the form fields count and offset, checking both fields.
     *
     * @param array<string, mixed> $input The submitted form fields.
     *
     * @return self The import request.
     * @throws InvalidImportRequest A field is missing, not a whole number, or out of range;
     *                              it names every field that is.
     */
    public static function fromInput(array $input): self
    {
        $count = self::readWholeNumber($input[self::FIELD_COUNT] ?? null);
        $offset = self::readWholeNumber($input[self::FIELD_OFFSET] ?? null);

        // A value that cannot be read is passed on below the minimum, so it gets the same message as one out of range.
        return new self($count ?? self::MIN_COUNT - 1, $offset ?? self::MIN_OFFSET - 1);
    }

    private static function readWholeNumber(mixed $value): ?int
    {
        if (!is_string($value) || strlen($value) > self::MAX_DIGITS || !ctype_digit($value)) {
            return null;
        }

        return (int) $value;
    }

    private static function inRange(int $value, int $min, int $max): bool
    {
        return $value >= $min && $value <= $max;
    }

    private static function rangeMessage(int $min, int $max): string
    {
        return "Enter a whole number from $min to $max.";
    }
}
