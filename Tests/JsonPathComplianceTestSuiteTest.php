<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\JsonPath\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\JsonPath\Exception\JsonCrawlerException;
use Symfony\Component\JsonPath\JsonCrawler;

final class JsonPathComplianceTestSuiteTest extends TestCase
{
    private const UNSUPPORTED_TEST_CASES = [
        'basic, multiple selectors, name and index, object data',
        'basic, multiple selectors, index and slice',
        'basic, multiple selectors, index and slice, overlapping',
        'basic, multiple selectors, wildcard and index',
        'basic, multiple selectors, wildcard and name',
        'basic, multiple selectors, wildcard and slice',
        'basic, multiple selectors, multiple wildcards',
        'filter, existence, without segments',
        'filter, existence, present with null',
        'filter, absolute existence, without segments',
        'filter, absolute existence, with segments',
        'filter, equals null, absent from data',
        'filter, absolute, equals self',
        'filter, deep equality, arrays',
        'filter, deep equality, objects',
        'filter, not-equals string, single quotes',
        'filter, not-equals numeric string, single quotes',
        'filter, not-equals string, single quotes, different type',
        'filter, not-equals string, double quotes',
        'filter, not-equals numeric string, double quotes',
        'filter, not-equals string, double quotes, different types',
        'filter, not-equals null, absent from data',
        'filter, less than number',
        'filter, less than null',
        'filter, less than true',
        'filter, less than false',
        'filter, less than or equal to true',
        'filter, greater than number',
        'filter, greater than null',
        'filter, greater than true',
        'filter, greater than false',
        'filter, greater than or equal to string, single quotes',
        'filter, greater than or equal to string, double quotes',
        'filter, greater than or equal to number',
        'filter, greater than or equal to null',
        'filter, greater than or equal to true',
        'filter, greater than or equal to false',
        'filter, exists and not-equals null, absent from data',
        'filter, exists and exists, data false',
        'filter, exists or exists, data false',
        'filter, and',
        'filter, or',
        'filter, not exists, data null',
        'filter, non-singular existence, wildcard',
        'filter, non-singular existence, multiple',
        'filter, non-singular existence, slice',
        'filter, non-singular existence, negated',
        'filter, nested',
        'filter, name segment on primitive, selects nothing',
        'filter, name segment on array, selects nothing',
        'filter, index segment on object, selects nothing',
        'filter, followed by name selector',
        'filter, followed by child segment that selects multiple elements',
        'filter, multiple selectors',
        'filter, multiple selectors, comparison',
        'filter, multiple selectors, overlapping',
        'filter, multiple selectors, filter and index',
        'filter, multiple selectors, filter and wildcard',
        'filter, multiple selectors, filter and slice',
        'filter, multiple selectors, comparison filter, index and slice',
        'filter, equals number, zero and negative zero',
        'filter, equals number, negative zero and zero',
        'filter, equals number, with and without decimal fraction',
        'filter, equals number, exponent',
        'filter, equals number, exponent upper e',
        'filter, equals number, positive exponent',
        'filter, equals number, negative exponent',
        'filter, equals number, exponent 0',
        'filter, equals number, exponent -0',
        'filter, equals number, exponent +0',
        'filter, equals number, exponent leading -0',
        'filter, equals number, exponent +00',
        'filter, equals number, decimal fraction',
        'filter, equals number, decimal fraction, trailing 0',
        'filter, equals number, decimal fraction, exponent',
        'filter, equals number, decimal fraction, positive exponent',
        'filter, equals number, decimal fraction, negative exponent',
        'filter, equals, empty node list and empty node list',
        'filter, equals, empty node list and special nothing',
        'filter, object data',
        'filter, and binds more tightly than or',
        'filter, left to right evaluation',
        'filter, group terms, right',
        'name selector, double quotes, escaped reverse solidus',
        'name selector, single quotes, escaped reverse solidus',
        'slice selector, slice selector with everything omitted, long form',
        'slice selector, start, min exact',
        'slice selector, start, max exact',
        'slice selector, end, min exact',
        'slice selector, end, max exact',
        'basic, descendant segment, multiple selectors',
        'basic, bald descendant segment',
        'filter, relative non-singular query, index, equal',
        'filter, relative non-singular query, index, not equal',
        'filter, relative non-singular query, index, less-or-equal',
        'filter, relative non-singular query, name, equal',
        'filter, relative non-singular query, name, not equal',
        'filter, relative non-singular query, name, less-or-equal',
        'filter, relative non-singular query, combined, equal',
        'filter, relative non-singular query, combined, not equal',
        'filter, relative non-singular query, combined, less-or-equal',
        'filter, relative non-singular query, wildcard, equal',
        'filter, relative non-singular query, wildcard, not equal',
        'filter, relative non-singular query, wildcard, less-or-equal',
        'filter, relative non-singular query, slice, equal',
        'filter, relative non-singular query, slice, not equal',
        'filter, relative non-singular query, slice, less-or-equal',
        'filter, absolute non-singular query, index, equal',
        'filter, absolute non-singular query, index, not equal',
        'filter, absolute non-singular query, index, less-or-equal',
        'filter, absolute non-singular query, name, equal',
        'filter, absolute non-singular query, name, not equal',
        'filter, absolute non-singular query, name, less-or-equal',
        'filter, absolute non-singular query, combined, equal',
        'filter, absolute non-singular query, combined, not equal',
        'filter, absolute non-singular query, combined, less-or-equal',
        'filter, absolute non-singular query, wildcard, equal',
        'filter, absolute non-singular query, wildcard, not equal',
        'filter, absolute non-singular query, wildcard, less-or-equal',
        'filter, absolute non-singular query, slice, equal',
        'filter, absolute non-singular query, slice, not equal',
        'filter, absolute non-singular query, slice, less-or-equal',
        'filter, equals, special nothing',
        'filter, group terms, left',
        'index selector, min exact index - 1',
        'index selector, max exact index + 1',
        'index selector, overflowing index',
        'index selector, leading 0',
        'index selector, -0',
        'index selector, leading -0',
        'slice selector, excessively large from value with negative step',
        'slice selector, step, min exact - 1',
        'slice selector, step, max exact + 1',
        'slice selector, overflowing to value',
        'slice selector, underflowing from value',
        'slice selector, overflowing from value with negative step',
        'slice selector, underflowing to value with negative step',
        'slice selector, overflowing step',
        'slice selector, underflowing step',
        'slice selector, step, leading 0',
        'slice selector, step, -0',
        'slice selector, step, leading -0',
    ];

    /**
     * @dataProvider complianceCaseProvider
     */
    public function testComplianceTestCase(string $selector, array $document, array $expectedResults, bool $invalidSelector)
    {
        $jsonCrawler = new JsonCrawler(json_encode($document));

        if ($invalidSelector) {
            $this->expectException(JsonCrawlerException::class);
        }

        $result = $jsonCrawler->find($selector);

        if (!$invalidSelector) {
            $this->assertContains($result, $expectedResults);
        }
    }

    public static function complianceCaseProvider(): iterable
    {
        $data = json_decode(file_get_contents(__DIR__.'/Fixtures/cts.json'), true, flags: \JSON_THROW_ON_ERROR);

        foreach ($data['tests'] as $test) {
            if (\in_array($test['name'], self::UNSUPPORTED_TEST_CASES, true)) {
                continue;
            }

            yield $test['name'] => [
                $test['selector'],
                $test['document'] ?? [],
                isset($test['result']) ? [$test['result']] : ($test['results'] ?? []),
                $test['invalid_selector'] ?? false,
            ];
        }
    }
}
