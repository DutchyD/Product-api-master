<?php

declare(strict_types=1);

namespace MaxServ\App\Tests\Import;

use MaxServ\App\Import\ImportRequest;
use MaxServ\App\Import\InvalidImportRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ImportRequest::class)]
#[CoversClass(InvalidImportRequest::class)]
final class ImportRequestTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function allowedValues(): iterable
    {
        yield 'smallest' => ['1', '0'];
        yield 'largest' => ['200', '10000'];
        yield 'leading zeros' => ['010', '0050'];
    }

    #[Test]
    #[DataProvider('allowedValues')]
    public function allowed_count_and_offset_are_accepted(string $count, string $offset): void
    {
        $request = ImportRequest::fromInput(['count' => $count, 'offset' => $offset]);

        self::assertSame((int) $count, $request->count);
        self::assertSame((int) $offset, $request->offset);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function rejectedInput(): iterable
    {
        yield 'count zero' => [['count' => '0', 'offset' => '0']];
        yield 'count above maximum' => [['count' => '201', 'offset' => '0']];
        yield 'negative offset' => [['count' => '10', 'offset' => '-1']];
        yield 'offset above maximum' => [['count' => '10', 'offset' => '10001']];
        yield 'count not a number' => [['count' => 'ten', 'offset' => '0']];
        yield 'count decimal' => [['count' => '1.5', 'offset' => '0']];
        yield 'count empty' => [['count' => '', 'offset' => '0']];
        yield 'count missing' => [['offset' => '0']];
        yield 'offset missing' => [['count' => '10']];
        yield 'count array' => [['count' => ['10'], 'offset' => '0']];
        yield 'count too many digits' => [['count' => '000001', 'offset' => '0']];
    }

    /**
     * @param array<string, mixed> $input
     */
    #[Test]
    #[DataProvider('rejectedInput')]
    public function invalid_count_or_offset_is_rejected_with_the_allowed_ranges(array $input): void
    {
        $this->expectException(InvalidImportRequest::class);
        $this->expectExceptionMessage('Count must be a whole number from 1 to 200, and offset a whole number from 0 to 10000.');

        ImportRequest::fromInput($input);
    }
}
