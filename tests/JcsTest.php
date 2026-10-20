<?php

declare(strict_types=1);

namespace Facta\Api\Tests;

use Facta\Api\Json\Fingerprint;
use Facta\Api\Json\Jcs;
use Facta\Api\Json\Json;
use PHPUnit\Framework\TestCase;

final class JcsTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function fixture(string $name)
    {
        $text = file_get_contents(__DIR__ . '/fixtures/' . $name);
        self::assertIsString($text);
        $decoded = json_decode($text, true);
        self::assertIsArray($decoded);
        return $decoded;
    }

    /** @return array<string, array{string, string, string}> */
    public function sharedVectors(): array
    {
        $rows = [];
        foreach (self::fixture('fingerprint-v1.vectors.json')['fingerprintV1'] as $row) {
            $rows[$row['name']] = [$row['input'], $row['jcs'], $row['sha256']];
        }
        return $rows;
    }

    /**
     * @dataProvider sharedVectors
     */
    public function testSharedConformanceVectors(string $input, string $jcs, string $sha256): void
    {
        $this->assertSame($jcs, Jcs::canonicalize($input));
        $this->assertSame($sha256, Fingerprint::v1($input));
    }

    public function testSharedEnvelopeDigestVectors(): void
    {
        $rows = self::fixture('fingerprint-v1.vectors.json')['envelopeDigest'];
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $envelope = $row['envelope'];
            $ordered = [
                'id' => $envelope['id'], 'kind' => $envelope['kind'], 'iv' => $envelope['iv'],
                'ciphertext' => $envelope['ciphertext'], 'formatVersion' => $envelope['formatVersion'],
                'revision' => $envelope['revision'],
            ];
            $serialized = json_encode([$ordered], JSON_UNESCAPED_SLASHES);
            $this->assertSame($row['serialized'], $serialized, $row['name']);
            $this->assertSame($row['digest'], hash('sha256', (string) $serialized), $row['name']);
        }
    }

    public function testEveryDoubleSpelledLikeEcmaScript(): void
    {
        $numbers = self::fixture('jcs-numbers.vectors.json');
        $this->assertGreaterThan(2000, count($numbers));
        foreach ($numbers as $text) {
            $this->assertSame($text, Jcs::canonicalize($text), 'number ' . $text);
        }
    }

    /**
     * @dataProvider hardNumbers
     */
    public function testHardNumbers(string $input, string $expected): void
    {
        $this->assertSame($expected, Jcs::canonicalize($input));
    }

    /** @return array<string, array{string, string}> */
    public function hardNumbers(): array
    {
        return [
            '10.0' => ['10.0', '10'],
            '1E1' => ['1E1', '10'],
            '1e-7' => ['0.0000001', '1e-7'],
            '1e-6 stays positional' => ['0.000001', '0.000001'],
            '1e21' => ['1e21', '1e+21'],
            '1e20 positional' => ['1e20', '100000000000000000000'],
            '-0' => ['-0', '0'],
            '-0.0' => ['-0.0', '0'],
            '1234.56789012' => ['1234.56789012', '1234.56789012'],
            '0.1' => ['0.1', '0.1'],
            '0.1+0.2' => ['0.30000000000000004', '0.30000000000000004'],
            'denormal min' => ['5e-324', '5e-324'],
            'max double' => ['1.7976931348623157e308', '1.7976931348623157e+308'],
            '2^53' => ['9007199254740992', '9007199254740992'],
            '2^53+1 rounds like JS' => ['9007199254740993', '9007199254740992'],
            'int64 max rounds like JS' => ['9223372036854775807', '9223372036854776000'],
            'beyond int64' => ['12345678901234567890', '12345678901234567000'],
            'negative big' => ['-9007199254740993', '-9007199254740992'],
            'money 8 decimals' => ['0.00000001', '1e-8'],
            'trailing zeros' => ['1.50', '1.5'],
        ];
    }

    public function testNumberFormattingIgnoresSerializePrecision(): void
    {
        $previous = ini_set('serialize_precision', '17');
        $previousPrecision = ini_set('precision', '3');
        try {
            $this->assertSame('0.1', Jcs::canonicalize('0.1'));
            $this->assertSame('1234.56789012', Jcs::canonicalize('1234.56789012'));
        } finally {
            ini_set('serialize_precision', (string) $previous);
            ini_set('precision', (string) $previousPrecision);
        }
    }

    public function testKeysSortByUtf16CodeUnitsNotUtf8Bytes(): void
    {
        // U+1F600 is D83D DE00 in UTF-16 and sorts BEFORE U+FFFF; in UTF-8 it sorts after.
        $this->assertSame("{\"\u{1F600}\":1,\"\u{FFFF}\":2}", Jcs::canonicalize("{\"\u{FFFF}\":2,\"\u{1F600}\":1}"));
        $this->assertSame('{"1":1,"B":2,"a":3,"é":4}', Jcs::canonicalize('{"é":4,"a":3,"B":2,"1":1}'));
        $this->assertSame('{"":0,"a":1,"aa":2}', Jcs::canonicalize('{"aa":2,"a":1,"":0}'));
    }

    public function testStringEscaping(): void
    {
        $this->assertSame("\"\\\"\\\\/\\b\\f\\n\\r\\t\\u0001\\u001f\x7f\"", Jcs::canonicalize('"\\"\\\\\\/\\b\\f\\n\\r\\t\\u0001\\u001f\\u007f"'));
        $this->assertSame("\"\u{2028}\u{2029}ñ\"", Jcs::canonicalize("\"\u{2028}\u{2029}ñ\""));
    }

    public function testEmptyObjectAndEmptyArrayStayDistinct(): void
    {
        $this->assertSame('{"a":{},"b":[]}', Jcs::canonicalize('{ "b" : [ ] , "a" : { } }'));
    }

    /**
     * @dataProvider invalidInputs
     */
    public function testInvalidInputIsRefused(string $text): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Jcs::canonicalize($text);
    }

    /** @return array<string, array{string}> */
    public function invalidInputs(): array
    {
        return [
            'empty' => [''],
            'truncated' => ['{"a":'],
            'infinity' => ['1e999'],
            'nan-like' => ['NaN'],
            'bad utf-8' => ["\"\xff\""],
            'lone surrogate' => ['"\\ud800"'],
        ];
    }

    public function testFingerprintIsLowercaseHexSha256(): void
    {
        $fingerprint = Fingerprint::v1('{"b":1,"a":10.0}');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $fingerprint);
        $this->assertSame(hash('sha256', '{"a":10,"b":1}'), $fingerprint);
        $this->assertSame($fingerprint, Fingerprint::v1("{\n \"a\": 10,\n \"b\": 1\n}"));
    }

    public function testJsonEncodeIsStableAndIndependentOfSerializePrecision(): void
    {
        $previous = ini_set('serialize_precision', '17');
        try {
            $this->assertSame('{"a":0.1,"u":"ñ/x","e":{},"l":[]}', Json::encode(['a' => 0.1, 'u' => 'ñ/x', 'e' => new \stdClass(), 'l' => []]));
            $this->assertSame('17', ini_get('serialize_precision'), 'the ini setting is restored');
        } finally {
            ini_set('serialize_precision', (string) $previous);
        }
    }

    public function testJsonEncodeRefusesUnencodableValues(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Json::encode(["bad" => "\xff"]);
    }

    public function testJsonDecodeKeepsObjectsAsStdClass(): void
    {
        $value = Json::decode('{"a":{},"b":[]}');
        $this->assertInstanceOf(\stdClass::class, $value->a);
        $this->assertSame([], $value->b);
    }
}
