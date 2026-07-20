<?php

declare(strict_types=1);

namespace Opr\Gateway\Tests;

use Opr\Gateway\Candidate;
use Opr\Gateway\Gateway;
use Opr\Gateway\Verification;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class VerificationTest extends TestCase
{
    private function ccda(): \Opr\Gateway\IngestionResult
    {
        return (new Gateway())->ingestFile(__DIR__.'/../fixtures/ccd-sample.xml');
    }

    public function test_signoff_is_blocked_while_candidates_are_pending(): void
    {
        $v = new Verification($this->ccda());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/still pending/');
        $v->signOff('u-1', 'Dr. Okafor');
    }

    public function test_signoff_is_blocked_by_unresolved_mentions_until_acknowledged(): void
    {
        $result = $this->ccda();
        $v = new Verification($result);
        $v->acceptAllDeterministic(); // clears pending, but unresolved > 0 remains

        try {
            $v->signOff('u-1', 'Dr. Okafor');
            $this->fail('expected sign-off to be blocked by unresolved mentions');
        } catch (RuntimeException $e) {
            $this->assertMatchesRegularExpression('/unresolved/', $e->getMessage());
        }

        $v->acknowledgeUnresolved();
        $entries = $v->signOff('u-1', 'Dr. Okafor');
        $this->assertNotEmpty($entries);
    }

    public function test_accepted_entries_become_clinician_verified_with_verifier_provenance(): void
    {
        $result = $this->ccda();
        $v = new Verification($result);
        $v->acceptAllDeterministic();
        $v->acknowledgeUnresolved();

        $entries = $v->signOff('u-1', 'Dr. Okafor');

        foreach ($entries as $entry) {
            $this->assertSame('clinician-verified', $entry['verification_tier']);
            $this->assertSame('u-1', $entry['provenance']['verifier_id']);
            $this->assertSame('Dr. Okafor', $entry['provenance']['verifier_name']);
            $this->assertContains($entry['provenance']['disposition'], ['accepted', 'edited']);
        }
    }

    public function test_rejected_candidates_do_not_commit(): void
    {
        $result = $this->ccda();
        $v = new Verification($result);

        // Reject everything explicitly; acknowledge unresolved; nothing to commit.
        foreach ($result->candidates as $i => $_) {
            $v->reject($i);
        }
        $v->acknowledgeUnresolved();

        $this->assertSame([], $v->signOff('u-1', 'Dr. Okafor'));
    }

    public function test_edits_replace_the_payload(): void
    {
        $result = $this->ccda();
        $v = new Verification($result);
        foreach ($result->candidates as $i => $_) {
            $v->reject($i);
        }
        $v->edit(0, ['resourceType' => 'Condition', 'code' => ['text' => 'Corrected by clinician']]);
        $v->acknowledgeUnresolved();

        $entries = $v->signOff('u-1', 'Dr. Okafor');
        $this->assertCount(1, $entries);
        $this->assertSame('Corrected by clinician', $entries[0]['payload']['code']['text']);
        $this->assertSame('edited', $entries[0]['provenance']['disposition']);
    }
}
