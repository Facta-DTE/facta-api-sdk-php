<?php

declare(strict_types=1);

namespace Facta\Api\Tests;

use Facta\Api\FactaError;
use Facta\Api\Live\LiveError;
use Facta\Api\Live\Report;
use PHPUnit\Framework\TestCase;

final class LiveReportTest extends TestCase
{
    public function testTheReportIsTitledForPhpAndKeepsTheTableFormat(): void
    {
        $text = Report::render(Report::createResults());
        $lines = explode("\n", $text);
        $this->assertSame('## Staging live validation (PHP SDK)', $lines[0]);
        $this->assertSame('| Check | Result |', $lines[2]);
        $this->assertSame('| --- | --- |', $lines[3]);
        $this->assertStringContainsString('| Staging API and test environment | Not checked |', $text);
        $this->assertStringContainsString('| Invoice generated and sealed | Not checked |', $text);
        $this->assertStringContainsString('Invoice documents and issuer/customer identifiers are not uploaded or published.', $text);
        $this->assertStringContainsString('This test uses environment `00`; it does not issue a production invoice.', $text);
        $this->assertStringNotContainsString('Failure category', $text);
    }

    public function testStepsThisVersionCannotRunAreListedAsSuch(): void
    {
        $text = Report::render(Report::createResults());
        foreach (['Encrypted catalog snapshot opened locally', 'Encrypted destination snapshot opened locally', 'Encrypted local archive', 'Completed operation journal', 'Encrypted journal restart and recovery'] as $label) {
            $this->assertStringContainsString('| ' . $label . ' | Not in this SDK version |', $text);
        }
    }

    public function testOnlyAllowlistedStatesAreEverPublished(): void
    {
        $text = Report::render(['status' => 'Passed', 'emission' => 'jws eyJhbGciOi... DTE-01-M001P001-000000000000001', 'query' => 'Passed (e-mail sent)']);
        $this->assertStringContainsString('| Staging API and test environment | Passed |', $text);
        $this->assertStringContainsString('| Invoice generated and sealed | Not checked |', $text);
        $this->assertStringNotContainsString('eyJ', $text);
        $this->assertStringNotContainsString('DTE-01', $text);
    }

    public function testEveryRowCarriesAnAllowlistedState(): void
    {
        foreach (Report::createResults() as $state) {
            $this->assertContains($state, Report::STATES);
        }
        foreach (['Passed (sealed test FE, $0.01)', 'Passed (test fixture sealed)', 'Warning (mail quota reached)'] as $state) {
            $this->assertContains($state, Report::STATES);
        }
    }

    public function testAFailureCategoryIsAllowlisted(): void
    {
        $this->assertStringContainsString('Failure category: `mh_rejected`.', Report::render([], 'mh_rejected'));
        $this->assertStringContainsString('Failure category: `validation_failed`.', Report::render([], 'secret-thing-123'));
    }

    public function testSafeFailureCodeFoldsUnknownCausesAndNeverEchoesMessages(): void
    {
        $this->assertSame('mh_rejected', Report::safeFailureCode(new FactaError('mh_rejected', 'NIT 0614-... says no', 422)));
        $this->assertSame('validation_failed', Report::safeFailureCode(new FactaError('brand_new_code', 'x', 400)));
        $this->assertSame('assertion_failed', Report::safeFailureCode(new LiveError('assertion_failed', 'x')));
        $this->assertSame('validation_failed', Report::safeFailureCode(new \RuntimeException('DTE-01 data')));
        $this->assertSame('validation_failed', Report::safeFailureCode(new LiveError('free text', 'x')));
    }

    public function testEveryLabeledCheckHasAnId(): void
    {
        $this->assertCount(count(Report::LABELS), array_unique(array_keys(Report::LABELS)));
        foreach (Report::NOT_AVAILABLE as $id) {
            $this->assertArrayHasKey($id, Report::LABELS);
        }
    }
}
