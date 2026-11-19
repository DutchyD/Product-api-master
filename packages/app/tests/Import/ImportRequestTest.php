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
    public function allowedCountAndOffsetAreAccepted(string $count, string $offset): void
    {
        $request = ImportRequest::fromInput(['count' => $count, 'offset' => $offset]);

        self::assertSame((int) $count, $request->count);
        self::assertSame((int) $offset, $request->offset);
    }

    /**
     * Form input that is not allowed, with the field errors it should produce.
     *
     * @return iterable<string, array{array<string, mixed>, array<string, string>}>
     */
    public static function rejectedInput(): iterable
    {
        $count = 'Enter a whole number from 1 to 200.';
        $offset = 'Enter a whole number from 0 to 10000.';

        yield 'count zero' => [['count' => '0', 'offset' => '0'], ['count' => $count]];
        yield 'count above maximum' => [['count' => '201', 'offset' => '0'], ['count' => $count]];
        yield 'negative offset' => [['count' => '10', 'offset' => '-1'], ['offset' => $offset]];
        yield 'offset above maximum' => [['count' => '10', 'offset' => '10001'], ['offset' => $offset]];
        yield 'count not a number' => [['count' => 'ten', 'offset' => '0'], ['count' => $count]];
        yield 'count decimal' => [['count' => '1.5', 'offset' => '0'], ['count' => $count]];
        yield 'count empty' => [['count' => '', 'offset' => '0'], ['count' => $count]];
        yield 'count missing' => [['offset' => '0'], ['count' => $count]];
        yield 'offset missing' => [['count' => '10'], ['offset' => $offset]];
        yield 'count array' => [['count' => ['10'], 'offset' => '0'], ['count' => $count]];
        yield 'count too many digits' => [['count' => '000001', 'offset' => '0'], ['count' => $count]];
        yield 'both fields wrong' => [['count' => '0', 'offset' => '10001'], ['count' => $count, 'offset' => $offset]];
        yield 'both fields missing' => [[], ['count' => $count, 'offset' => $offset]];
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, string> $expectedErrors
     */
    #[Test]
    #[DataProvider('rejectedInput')]
    public function invalidFieldsAreEachRejectedWithTheirAllowedRange(array $input, array $expectedErrors): void
    {
        try {
            (void) ImportRequest::fromInput($input);
            self::fail('The input should be rejected.');
        } catch (InvalidImportRequest $exception) {
            self::assertSame($expectedErrors, $exception->fieldErrors());
            self::assertSame(implode(' ', $expectedErrors), $exception->getMessage());
        }
    }
}
