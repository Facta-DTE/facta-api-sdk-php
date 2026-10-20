<?php

declare(strict_types=1);

namespace Facta\Api\Tests;

use Facta\Api\FactaError;
use Facta\Api\Live\EmailDelivery;
use PHPUnit\Framework\TestCase;

final class LiveEmailDeliveryTest extends TestCase
{
    private const INBOX = 'qa.inbox@example.com';

    /**
     * @param array<string, mixed> $channel
     * @return array{outcome: string, code: string, state?: string, status?: int}
     */
    private static function verdict(array $channel, bool $settled = true): array
    {
        return EmailDelivery::verdict(['channel' => $channel, 'settled' => $settled, 'expectedRecipient' => self::INBOX]);
    }

    public function testSentToTheRightInboxPasses(): void
    {
        $verdict = self::verdict(['estado' => 'enviado', 'destino' => 'q••••••••••@example.com']);
        $this->assertSame('pass', $verdict['outcome']);
        $this->assertSame('Passed (e-mail sent)', EmailDelivery::reportState($verdict));
    }

    public function testAQuotaOrProviderOutageOnlyWarns(): void
    {
        $quota = self::verdict(['estado' => 'fallido', 'motivo' => 'quota_exceeded']);
        $this->assertSame('warn', $quota['outcome']);
        $this->assertSame('Warning (mail quota reached)', EmailDelivery::reportState($quota));
        $this->assertStringContainsString('quota_exceeded', EmailDelivery::warningLines($quota)[0]);
        $this->assertStringStartsWith('::warning title=', EmailDelivery::warningLines($quota)[1]);

        $provider = self::verdict(['estado' => 'fallido', 'motivo' => 'provider_unavailable']);
        $this->assertSame('warn', $provider['outcome']);
        $this->assertSame('Warning (mail provider unavailable)', EmailDelivery::reportState($provider));
    }

    public function testEveryOtherOutcomeFails(): void
    {
        foreach ([
            [['estado' => 'fallido', 'motivo' => 'smtp_rejected'], true, 'smtp_rejected'],
            [['estado' => 'fallido', 'motivo' => 'invalid_address'], true, 'invalid_address'],
            [['estado' => 'sin_consentimiento'], true, 'sin_consentimiento'],
            [['estado' => 'en_proceso'], false, 'delivery_timeout'],
            [['estado' => 'pendiente'], true, 'delivery_timeout'],
            [['estado' => 'enviado', 'destino' => 'x•••@other.example'], true, 'wrong_recipient'],
            [['estado' => 'enviado'], true, 'wrong_recipient'],
            [['estado' => 'Not A Code!'], true, 'delivery_schema_invalid'],
            [['estado' => 'fallido', 'motivo' => ['x']], true, 'delivery_schema_invalid'],
        ] as [$channel, $settled, $code]) {
            $verdict = self::verdict($channel, $settled);
            $this->assertSame('fail', $verdict['outcome'], $code);
            $this->assertSame($code, $verdict['code']);
            $this->assertSame('Failed', EmailDelivery::reportState($verdict));
        }
    }

    public function testAWrongRecipientFailsEvenWhenTheQuotaWasReached(): void
    {
        $verdict = self::verdict(['estado' => 'fallido', 'motivo' => 'quota_exceeded', 'destino' => 'a•••@elsewhere.example']);
        $this->assertSame('wrong_recipient', $verdict['code']);
    }

    public function testNoChannelAtAllFails(): void
    {
        $this->assertSame('delivery_schema_invalid', EmailDelivery::verdict(['channel' => null, 'settled' => true])['code']);
        $this->assertSame('delivery_timeout', EmailDelivery::verdict(['channel' => null, 'settled' => false])['code']);
    }

    public function testAnErrorKeepsOnlyItsCodeAndStatus(): void
    {
        $verdict = EmailDelivery::verdict(['error' => new FactaError('entrega_vencida', 'secret message', 410)]);
        $this->assertSame(['outcome' => 'fail', 'code' => 'entrega_vencida', 'status' => 410], $verdict);
        $this->assertSame('delivery_request_failed', EmailDelivery::verdict(['error' => new \RuntimeException('x')])['code']);
    }

    public function testMaskedRecipientMatching(): void
    {
        $this->assertTrue(EmailDelivery::maskedRecipientMatches('q••••••••••@example.com', 'QA.Inbox@example.com'));
        $this->assertTrue(EmailDelivery::maskedRecipientMatches('q***@example.com', self::INBOX));
        $this->assertFalse(EmailDelivery::maskedRecipientMatches('q•••@example.org', self::INBOX));
        $this->assertFalse(EmailDelivery::maskedRecipientMatches('', self::INBOX));
        $this->assertFalse(EmailDelivery::maskedRecipientMatches('q•••@example.com', ''));
        $this->assertFalse(EmailDelivery::maskedRecipientMatches('q.•••@example.com', 'qa@example.com'));
    }
}
