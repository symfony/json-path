<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\JsonPath;

use Symfony\Component\JsonPath\Exception\InvalidArgumentException;
use Symfony\Component\JsonPath\Exception\InvalidJsonPathException;
use Symfony\Component\JsonPath\Exception\InvalidJsonStringInputException;
use Symfony\Component\JsonPath\Exception\JsonCrawlerException;
use Symfony\Component\JsonPath\Tokenizer\JsonPathToken;
use Symfony\Component\JsonPath\Tokenizer\JsonPathTokenizer;
use Symfony\Component\JsonPath\Tokenizer\TokenType;
use Symfony\Component\JsonStreamer\Read\Splitter;

/**
 * Crawls a JSON document using a JSON Path as described in the RFC 9535.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc9535
 *
 * @author Alexandre Daubois <alex.daubois@gmail.com>
 *
 * @experimental
 */
final class JsonCrawler implements JsonCrawlerInterface
{
    private const RFC9535_FUNCTIONS = [
        'length' => true,
        'count' => true,
        'match' => true,
        'search' => true,
        'value' => true,
    ];

    /**
     * @param resource|string $raw
     */
    public function __construct(
        private readonly mixed $raw,
    ) {
        if (!\is_string($raw) && !\is_resource($raw)) {
            throw new InvalidArgumentException(\sprintf('Expected string or resource, got "%s".', get_debug_type($raw)));
        }
    }

    public function find(string|JsonPath $query): array
    {
        return $this->evaluate(\is_string($query) ? new JsonPath($query) : $query);
    }

    private function evaluate(JsonPath $query): array
    {
        try {
            $tokens = JsonPathTokenizer::tokenize($query);
            $json = $this->raw;

            if (\is_resource($this->raw)) {
                if (!class_exists(Splitter::class)) {
                    throw new \LogicException('The JsonStreamer package is required to evaluate a path against a resource. Try running "composer require symfony/json-streamer".');
                }

                $simplified = JsonPathUtils::findSmallestDeserializableStringAndPath(
                    $tokens,
                    $this->raw,
                );

                $tokens = $simplified['tokens'];
                $json = $simplified['json'];
            }

            try {
                $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new InvalidJsonStringInputException($e->getMessage(), $e);
            }

            return $this->evaluateTokensOnDecodedData($tokens, $data);
        } catch (InvalidArgumentException $e) {
            throw $e;
        } catch (InvalidJsonPathException $e) {
            throw new JsonCrawlerException($query, $e->getMessage(), previous: $e);
        }
    }

    private function evaluateTokensOnDecodedData(array $tokens, array $data): array
    {
        $current = [$data];

        foreach ($tokens as $token) {
            $next = [];
            foreach ($current as $value) {
                $result = $this->evaluateToken($token, $value);
                $next = array_merge($next, $result);
            }

            $current = $next;
        }

        return $current;
    }

    private function evaluateToken(JsonPathToken $token, mixed $value): array
    {
        return match ($token->type) {
            TokenType::Name => $this->evaluateName($token->value, $value),
            TokenType::Bracket => $this->evaluateBracket($token->value, $value),
            TokenType::Recursive => $this->evaluateRecursive($value),
        };
    }

    private function evaluateName(string $name, mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        if ('*' === $name) {
            return array_values($value);
        }

        return \array_key_exists($name, $value) ? [$value[$name]] : [];
    }

    private function evaluateBracket(string $expr, mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        if (str_contains($expr, ',') && (str_starts_with($trimmed = trim($expr), ',') || str_ends_with($trimmed, ','))) {
            throw new JsonCrawlerException($expr, 'Expression cannot have leading or trailing commas');
        }

        if ('*' === $expr = JsonPathUtils::normalizeWhitespace($expr)) {
            return array_values($value);
        }

        // single negative index
        if (preg_match('/^-\d+$/', $expr)) {
            if (JsonPathUtils::hasLeadingZero($expr) || JsonPathUtils::isIntegerOverflow($expr) || '-0' === $expr) {
                throw new JsonCrawlerException($expr, 'invalid index selector');
            }

            if (!array_is_list($value)) {
                return [];
            }

            $index = \count($value) + (int) $expr;

            return isset($value[$index]) ? [$value[$index]] : [];
        }

        // start and end index
        if (preg_match('/^-?\d+(?:\s*,\s*-?\d+)*$/', $expr)) {
            foreach (explode(',', $expr) as $exprPart) {
                if (JsonPathUtils::hasLeadingZero($exprPart = trim($exprPart)) || JsonPathUtils::isIntegerOverflow($exprPart) || '-0' === $exprPart) {
                    throw new JsonCrawlerException($expr, 'invalid index selector');
                }
            }

            if (!array_is_list($value)) {
                return [];
            }

            $result = [];
            foreach (explode(',', $expr) as $index) {
                $index = (int) trim($index);
                if ($index < 0) {
                    $index = \count($value) + $index;
                }
                if (isset($value[$index])) {
                    $result[] = $value[$index];
                }
            }

            return $result;
        }

        if (preg_match('/^(-?\d*+)\s*+:\s*+(-?\d*+)(?:\s*+:\s*+(-?\d*+))?$/', $expr, $matches)) {
            if (!array_is_list($value)) {
                return [];
            }

            $startStr = trim($matches[1]);
            $endStr = trim($matches[2]);
            $stepStr = trim($matches[3] ?? '1');

            if (
                JsonPathUtils::hasLeadingZero($startStr)
                || JsonPathUtils::hasLeadingZero($endStr)
                || JsonPathUtils::hasLeadingZero($stepStr)
            ) {
                throw new JsonCrawlerException($expr, 'slice selector numbers cannot have leading zeros');
            }

            if ('-0' === $startStr || '-0' === $endStr || '-0' === $stepStr) {
                throw new JsonCrawlerException($expr, 'slice selector cannot contain negative zero');
            }

            if (
                JsonPathUtils::isIntegerOverflow($startStr)
                || JsonPathUtils::isIntegerOverflow($endStr)
                || JsonPathUtils::isIntegerOverflow($stepStr)
            ) {
                throw new JsonCrawlerException($expr, 'slice selector integer overflow');
            }

            $length = \count($value);
            $start = '' !== $startStr ? (int) $startStr : null;
            $end = '' !== $endStr ? (int) $endStr : null;
            $step = '' !== $stepStr ? (int) $stepStr : 1;

            if (0 === $step) {
                return [];
            }

            if (null === $start) {
                $start = $step > 0 ? 0 : $length - 1;
            } else {
                if ($start < 0) {
                    $start = $length + $start;
                }

                if ($step > 0 && $start >= $length) {
                    return [];
                }

                $start = max(0, min($start, $length - 1));
            }

            if (null === $end) {
                $end = $step > 0 ? $length : -1;
            } else {
                if ($end < 0) {
                    $end = $length + $end;
                }
                if ($step > 0) {
                    $end = max(0, min($end, $length));
                } else {
                    $end = max(-1, min($end, $length - 1));
                }
            }

            $result = [];
            for ($i = $start; $step > 0 ? $i < $end : $i > $end; $i += $step) {
                if (isset($value[$i])) {
                    $result[] = $value[$i];
                }
            }

            return $result;
        }

        // filter expressions
        if (preg_match('/^\?(.*)$/', $expr, $matches)) {
            if (preg_match('/^(\w+)\s*\([^()]*\)\s*([<>=!]+.*)?$/', $filterExpr = trim($matches[1]))) {
                $filterExpr = "($filterExpr)";
            }

            if (!str_starts_with($filterExpr, '(')) {
                $filterExpr = "($filterExpr)";
            }

            // remove outer filter parentheses
            $innerExpr = substr(substr($filterExpr, 1), 0, -1);

            return $this->evaluateFilter($innerExpr, $value);
        }

        // comma-separated values, e.g. `['key1', 'key2', 123]` or `[0, 1, 'key']`
        if (str_contains($expr, ',')) {
            $parts = JsonPathUtils::parseCommaSeparatedValues($expr);

            $result = [];

            foreach ($parts as $part) {
                $part = trim($part);

                if ('*' === $part) {
                    $result = array_merge($result, array_values($value));
                } elseif (preg_match('/^(-?\d*+)\s*+:\s*+(-?\d*+)(?:\s*+:\s*+(-?\d++))?$/', $part, $matches)) {
                    // slice notation
                    $sliceResult = $this->evaluateBracket($part, $value);
                    $result = array_merge($result, $sliceResult);
                } elseif (preg_match('/^([\'"])(.*)\1$/', $part, $matches)) {
                    $key = JsonPathUtils::unescapeString($matches[2], $matches[1]);

                    if (array_is_list($value)) {
                        // for arrays, find ALL objects that contain this key
                        foreach ($value as $item) {
                            if (\is_array($item) && \array_key_exists($key, $item)) {
                                $result[] = $item;
                            }
                        }
                    } elseif (\array_key_exists($key, $value)) { // for objects, get the value for this key
                        $result[] = $value[$key];
                    }
                } elseif (preg_match('/^-?\d+$/', $part)) {
                    // numeric index
                    $index = (int) $part;
                    if ($index < 0) {
                        $index = \count($value) + $index;
                    }

                    if (array_is_list($value) && \array_key_exists($index, $value)) {
                        $result[] = $value[$index];
                    } else {
                        // numeric index on a hashmap
                        $keysIndices = array_keys($value);
                        if (isset($keysIndices[$index]) && isset($value[$keysIndices[$index]])) {
                            $result[] = $value[$keysIndices[$index]];
                        }
                    }
                }
            }

            return $result;
        }

        if (preg_match('/^([\'"])(.*)\1$/', $expr, $matches)) {
            $key = JsonPathUtils::unescapeString($matches[2], $matches[1]);

            return \array_key_exists($key, $value) ? [$value[$key]] : [];
        }

        throw new InvalidJsonPathException(\sprintf('Unsupported bracket expression "%s".', $expr));
    }

    private function evaluateFilter(string $expr, mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $item) {
            if ($this->evaluateFilterExpression($expr, $item)) {
                $result[] = $item;
            }
        }

        return $result;
    }

    private function evaluateFilterExpression(string $expr, mixed $context): bool
    {
        $expr = JsonPathUtils::normalizeWhitespace($expr);

        // remove outer parentheses if they wrap the entire expression
        if (str_starts_with($expr, '(') && str_ends_with($expr, ')')) {
            $depth = 0;
            $isWrapped = true;
            $i = -1;
            while (null !== $char = $expr[++$i] ?? null) {
                if ('(' === $char) {
                    ++$depth;
                } elseif (')' === $char && 0 === --$depth && isset($expr[$i + 1])) {
                    $isWrapped = false;
                    break;
                }
            }
            if ($isWrapped) {
                $expr = trim(substr($expr, 1, -1));
            }
        }

        if (str_starts_with($expr, '!')) {
            return !$this->evaluateFilterExpression(trim(substr($expr, 1)), $context);
        }

        if (str_contains($expr, '&&')) {
            $parts = array_map('trim', explode('&&', $expr));
            foreach ($parts as $part) {
                if (!$this->evaluateFilterExpression($part, $context)) {
                    return false;
                }
            }

            return true;
        }

        if (str_contains($expr, '||')) {
            $parts = array_map('trim', explode('||', $expr));
            $result = false;
            foreach ($parts as $part) {
                $result = $result || $this->evaluateFilterExpression($part, $context);
            }

            return $result;
        }

        $operators = ['!=', '==', '>=', '<=', '>', '<'];
        foreach ($operators as $op) {
            if (str_contains($expr, $op)) {
                [$left, $right] = array_map('trim', explode($op, $expr, 2));
                $leftValue = $this->evaluateScalar($left, $context);
                $rightValue = $this->evaluateScalar($right, $context);

                return $this->compare($leftValue, $rightValue, $op);
            }
        }

        if ('@' === $expr) {
            return true;
        }

        if (str_starts_with($expr, '@.')) {
            return (bool) ($this->evaluateTokensOnDecodedData(JsonPathTokenizer::tokenize(new JsonPath('$'.substr($expr, 1))), $context)[0] ?? false);
        }

        // function calls
        if (preg_match('/^(\w++)\s*+\((.*)\)$/', $expr, $matches)) {
            $functionName = trim($matches[1]);
            if (!isset(self::RFC9535_FUNCTIONS[$functionName])) {
                throw new JsonCrawlerException($expr, \sprintf('invalid function "%s"', $functionName));
            }

            $functionResult = $this->evaluateFunction($functionName, $matches[2], $context);

            return is_numeric($functionResult) ? $functionResult > 0 : (bool) $functionResult;
        }

        return false;
    }

    private function evaluateScalar(string $expr, mixed $context): mixed
    {
        $expr = JsonPathUtils::normalizeWhitespace($expr);

        if (JsonPathUtils::isJsonNumber($expr)) {
            return str_contains($expr, '.') || str_contains(strtolower($expr), 'e') ? (float) $expr : (int) $expr;
        }

        // only validate tokens that look like standalone numbers
        if (preg_match('/^[\d+\-.eE]+$/', $expr) && preg_match('/\d/', $expr)) {
            throw new JsonCrawlerException($expr, \sprintf('Invalid number format "%s"', $expr));
        }

        if ('@' === $expr) {
            return $context;
        }

        if ('true' === $expr) {
            return true;
        }

        if ('false' === $expr) {
            return false;
        }

        if ('null' === $expr) {
            return null;
        }

        // string literals
        if (preg_match('/^([\'"])(.*)\1$/', $expr, $matches)) {
            return JsonPathUtils::unescapeString($matches[2], $matches[1]);
        }

        // current node references
        if (str_starts_with($expr, '@')) {
            if (!\is_array($context)) {
                return null;
            }

            return $this->evaluateTokensOnDecodedData(JsonPathTokenizer::tokenize(new JsonPath('$'.substr($expr, 1))), $context)[0] ?? null;
        }

        // function calls
        if (preg_match('/^(\w++)\((.*)\)$/', $expr, $matches)) {
            if (!isset(self::RFC9535_FUNCTIONS[$functionName = trim($matches[1])])) {
                throw new JsonCrawlerException($expr, \sprintf('invalid function "%s"', $functionName));
            }

            return $this->evaluateFunction($functionName, $matches[2], $context);
        }

        return null;
    }

    private function evaluateFunction(string $name, string $args, mixed $context): mixed
    {
        $argList = [];
        $nodelistSizes = [];
        if ($args = trim($args)) {
            $args = JsonPathUtils::parseCommaSeparatedValues($args);
            foreach ($args as $arg) {
                $arg = trim($arg);
                if (str_starts_with($arg, '$')) { // special handling for absolute paths
                    $results = $this->evaluate(new JsonPath($arg));
                    $argList[] = $results[0] ?? null;
                    $nodelistSizes[] = \count($results);
                } elseif (!str_starts_with($arg, '@')) { // special handling for @ to track nodelist size
                    $argList[] = $this->evaluateScalar($arg, $context);
                    $nodelistSizes[] = 1;
                } elseif ('@' === $arg) {
                    $argList[] = $context;
                    $nodelistSizes[] = 1;
                } elseif (!\is_array($context)) {
                    $argList[] = null;
                    $nodelistSizes[] = 0;
                } elseif (str_starts_with($pathPart = substr($arg, 1), '[')) {
                    // handle bracket expressions like @['a','d']
                    $results = $this->evaluateBracket(substr($pathPart, 1, -1), $context);
                    $argList[] = $results;
                    $nodelistSizes[] = \count($results);
                } else {
                    // handle dot notation like @.a
                    $results = $this->evaluateTokensOnDecodedData(JsonPathTokenizer::tokenize(new JsonPath('$'.$pathPart)), $context);
                    $argList[] = $results[0] ?? null;
                    $nodelistSizes[] = \count($results);
                }
            }
        }

        $value = $argList[0] ?? null;
        $nodelistSize = $nodelistSizes[0] ?? 0;

        return match ($name) {
            'length' => match (true) {
                \is_string($value) => mb_strlen($value),
                \is_array($value) => \count($value),
                default => 0,
            },
            'count' => $nodelistSize,
            'match' => match (true) {
                \is_string($value) && \is_string($argList[1] ?? null) => (bool) @preg_match(\sprintf('/^%s$/u', $this->transformJsonPathRegex($argList[1])), $value),
                default => false,
            },
            'search' => match (true) {
                \is_string($value) && \is_string($argList[1] ?? null) => (bool) @preg_match("/{$this->transformJsonPathRegex($argList[1])}/u", $value),
                default => false,
            },
            'value' => 1 < $nodelistSize ? null : (1 === $nodelistSize ? (\is_array($value) ? ($value[0] ?? null) : $value) : $value),
            default => null,
        };
    }

    private function evaluateRecursive(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $result = [$value];
        foreach ($value as $item) {
            if (\is_array($item)) {
                $result = array_merge($result, $this->evaluateRecursive($item));
            }
        }

        return $result;
    }

    private function compare(mixed $left, mixed $right, string $operator): bool
    {
        return match ($operator) {
            '==' => $left === $right,
            '!=' => $left !== $right,
            '>' => $left > $right,
            '>=' => $left >= $right,
            '<' => $left < $right,
            '<=' => $left <= $right,
            default => false,
        };
    }

    /**
     * Transforms JSONPath regex patterns to comply with RFC 9535.
     *
     * The main issue is that '.' should not match \r or \n but should
     * match Unicode line separators U+2028 and U+2029.
     */
    private function transformJsonPathRegex(string $pattern): string
    {
        $result = '';
        $inCharClass = false;
        $escaped = false;
        $i = -1;

        while (null !== $char = $pattern[++$i] ?? null) {
            if ($escaped) {
                $result .= $char;
                $escaped = false;
                continue;
            }

            if ('\\' === $char) {
                $result .= $char;
                $escaped = true;
                continue;
            }

            if ('[' === $char && !$inCharClass) {
                $inCharClass = true;
                $result .= $char;
                continue;
            }

            if (']' === $char && $inCharClass) {
                $inCharClass = false;
                $result .= $char;
                continue;
            }

            if ('.' === $char && !$inCharClass) {
                $result .= '(?:[^\r\n]|\x{2028}|\x{2029})';
            } else {
                $result .= $char;
            }
        }

        return $result;
    }
}
