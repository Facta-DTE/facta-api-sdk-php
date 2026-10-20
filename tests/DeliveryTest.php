<?php

declare(strict_types=1);

namespace Facta\Api\Tests;

use Facta\Api\FactaError;
use Facta\Api\Tests\Support\ClientCase;
use Facta\Api\Tests\Support\FakeTransport;

final class DeliveryTest extends ClientCase
{
    private const TOKEN = 'fdt_0123456789abcdef';

    public function testDeliverEmailPostsTheTokenOnTheChannelRoute(): void
    {
        $fake = new FakeTransport([self::ok('{"canal":"correo","estado":"enviado","destino":"a•••@b.co"}')]);
        $result = $this->client($fake, ['signKey' => self::SIGN_KEY])->deliverEmail(self::CG, self::TOKEN);

        $this->assertSame('enviado', $result['estado']);
        $sent = self::request($fake);
        $this->assertSame('POST', $sent['method']);
        $this->assertSame('/v1/dte/' . self::CG . '/entrega/correo', self::path($fake));
        $this->assertSame('{"token":"' . self::TOKEN . '"}', $sent['body']);
        $this->assertArrayHasKey('Idempotency-Key', $sent['headers']);
        $this->assertArrayNotHasKey('X-Facta-Sign-Key', $sent['headers']);
        $this->assertArrayNotHasKey('X-Facta-Request-Fingerprint', $sent['headers']);
    }

    public function testDeliverWhatsAppUsesItsOwnRoute(): void
    {
        $fake = new FakeTransport([self::ok('{"estado":"en_proceso"}', 202)]);
        $result = $this->client($fake)->deliverWhatsApp(self::CG, self::TOKEN);
        $this->assertSame('en_proceso', $result['estado'], 'A 202 is a state, not an error.');
        $this->assertSame('/v1/dte/' . self::CG . '/entrega/whatsapp', self::path($fake));
    }

    public function testBadArgumentsFailBeforeAnyRequest(): void
    {
        $fake = new FakeTransport([]);
        $client = $this->client($fake);
        foreach ([
            static function () use ($client): void {
                $client->deliverEmail('not-a-uuid', self::TOKEN);
            },
            static function () use ($client): void {
                $client->deliverEmail(self::CG, '');
            },
            static function () use ($client): void {
                $client->getDelivery('x');
            },
        ] as $call) {
            try {
                $call();
                $this->fail('InvalidArgumentException expected');
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        $this->assertSame([], $fake->requests);
    }

    public function testTheTokenIsStrippedFromAnErrorTheServerEchoed(): void
    {
        $fake = new FakeTransport([self::error(410, 'entrega_vencida', 'Token ' . self::TOKEN . ' expired', '{"echo":"' . self::TOKEN . '"}')]);
        try {
            $this->client($fake)->deliverEmail(self::CG, self::TOKEN);
            $this->fail('FactaError expected');
        } catch (FactaError $e) {
            $this->assertSame(FactaError::ENTREGA_VENCIDA, $e->getErrorCode());
            $this->assertSame(410, $e->getStatus());
            $this->assertStringNotContainsString(self::TOKEN, $e->getMessage() . json_encode($e->getDetails()));
            $this->assertStringContainsString('[REDACTED]', $e->getMessage());
        }
    }

    public function testGetDeliveryReadsEveryChannel(): void
    {
        $fake = new FakeTransport([self::ok('{"codigoGeneracion":"' . self::CG . '","canales":{"correo":{"estado":"fallido","motivo":"quota_exceeded"}}}')]);
        $status = $this->client($fake)->getDelivery(self::CG);
        $this->assertSame('GET', self::request($fake)['method']);
        $this->assertSame('/v1/dte/' . self::CG . '/entrega', self::path($fake));
        $this->assertSame('quota_exceeded', $status['canales']['correo']['motivo']);
    }

    public function testWaitForDeliveryPollsWithTheSleeperUntilTheChannelIsFinal(): void
    {
        $fake = new FakeTransport([
            self::ok('{"canales":{"correo":{"estado":"pendiente"}}}'),
            self::ok('{"canales":{"correo":{"estado":"en_proceso"}}}'),
            self::ok('{"canales":{"correo":{"estado":"enviado","destino":"a•••@b.co"}}}'),
        ]);
        $waited = $this->client($fake)->waitForDelivery(self::CG, ['channels' => ['correo'], 'intervalMs' => 3000]);

        $this->assertTrue($waited['settled']);
        $this->assertSame('enviado', $waited['canales']['correo']['estado']);
        $this->assertCount(3, $fake->requests);
        $this->assertSame([3000, 3000], $this->sleeps);
    }

    public function testWaitForDeliveryReturnsUnsettledOnTimeoutInsteadOfThrowing(): void
    {
        $fake = new FakeTransport(array_fill(0, 20, self::ok('{"canales":{"correo":{"estado":"en_proceso"}}}')));
        $waited = $this->client($fake)->waitForDelivery(self::CG, ['timeoutMs' => 5000, 'intervalMs' => 2000]);

        $this->assertFalse($waited['settled']);
        $this->assertSame('en_proceso', $waited['canales']['correo']['estado']);
        $this->assertSame([2000, 2000], $this->sleeps, 'Reads at 0 s, 2 s and 4 s; a third pause would pass the budget.');
        $this->assertCount(3, $fake->requests);
    }

    public function testWaitForDeliveryOnlyAwaitsTheChosenChannelsAndTreatsAWaitingSealAsFinal(): void
    {
        $fake = new FakeTransport([self::ok('{"canales":{"correo":{"estado":"enviado"},"whatsapp":{"estado":"en_proceso"}}}')]);
        $waited = $this->client($fake)->waitForDelivery(self::CG, ['channels' => ['correo']]);
        $this->assertTrue($waited['settled']);
        $this->assertSame([], $this->sleeps);

        $fake = new FakeTransport([self::ok('{"canales":{"correo":{"estado":"esperando_sello"}}}')]);
        $this->assertTrue($this->client($fake)->waitForDelivery(self::CG)['settled']);

        $fake = new FakeTransport([self::ok('{"canales":{"whatsapp":{"estado":"enviado"}}}')]);
        $this->assertFalse(
            $this->client($fake)->waitForDelivery(self::CG, ['channels' => ['correo'], 'timeoutMs' => 0])['settled'],
            'A channel the API does not report is not final.'
        );
    }

    public function testWaitForDeliveryChecksItsOptions(): void
    {
        $client = $this->client(new FakeTransport([]));
        foreach ([['timeoutMs' => -1], ['intervalMs' => 0]] as $options) {
            try {
                $client->waitForDelivery(self::CG, $options);
                $this->fail('InvalidArgumentException expected');
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }
}
