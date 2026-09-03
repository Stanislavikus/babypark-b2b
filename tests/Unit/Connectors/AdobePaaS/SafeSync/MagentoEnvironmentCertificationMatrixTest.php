<?php

namespace Tests\Unit\Connectors\AdobePaaS\SafeSync;

use App\Support\Connectors\AdobePaaS\SafeSync\MagentoEnvironmentCertificationAssessment;
use App\Support\Connectors\AdobePaaS\SafeSync\MagentoEnvironmentCertificationMatrix;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class MagentoEnvironmentCertificationMatrixTest extends TestCase
{
    #[Test]
    public function it_marks_249_and_php85_as_the_primary_target(): void
    {
        $assessment = (new MagentoEnvironmentCertificationMatrix)->evaluate('2.4.9', '8.5.1');

        $this->assertSame(MagentoEnvironmentCertificationAssessment::PRIMARY, $assessment->category);
        $this->assertTrue($assessment->isCertified());
    }

    #[Test]
    public function it_marks_249_and_php84_as_upgrade_compatibility_only(): void
    {
        $assessment = (new MagentoEnvironmentCertificationMatrix)->evaluate('2.4.9', '8.4.7');

        $this->assertSame(MagentoEnvironmentCertificationAssessment::UPGRADE_COMPATIBILITY, $assessment->category);
        $this->assertTrue($assessment->isCertified());
    }

    #[Test]
    public function it_marks_248p5_and_php84_as_the_previous_certified_target(): void
    {
        $assessment = (new MagentoEnvironmentCertificationMatrix)->evaluate('2.4.8-p5', '8.4.0');

        $this->assertSame(MagentoEnvironmentCertificationAssessment::PREVIOUS_CERTIFIED, $assessment->category);
        $this->assertTrue($assessment->isCertified());
    }

    #[Test]
    public function it_does_not_certify_248p5_with_php85(): void
    {
        $assessment = (new MagentoEnvironmentCertificationMatrix)->evaluate('2.4.8-p5', '8.5.0');

        $this->assertSame(MagentoEnvironmentCertificationAssessment::NOT_CERTIFIED, $assessment->category);
        $this->assertFalse($assessment->isCertified());
    }

    #[Test]
    public function it_does_not_certify_php83_for_v1(): void
    {
        $assessment = (new MagentoEnvironmentCertificationMatrix)->evaluate('2.4.9', '8.3.19');

        $this->assertSame(MagentoEnvironmentCertificationAssessment::NOT_CERTIFIED, $assessment->category);
        $this->assertFalse($assessment->isCertified());
    }

    #[Test]
    public function it_requires_exact_versions_before_assessing_certification(): void
    {
        $assessment = (new MagentoEnvironmentCertificationMatrix)->evaluate(null, '8.5.0');

        $this->assertSame(MagentoEnvironmentCertificationAssessment::EXACT_VERSION_PENDING, $assessment->category);
        $this->assertNull($assessment->isCertified());
    }

    #[Test]
    public function it_does_not_treat_arbitrary_249_patch_strings_as_certified(): void
    {
        $assessment = (new MagentoEnvironmentCertificationMatrix)->evaluate('2.4.9-p1', '8.5.0');

        $this->assertSame(MagentoEnvironmentCertificationAssessment::NOT_CERTIFIED, $assessment->category);
        $this->assertFalse($assessment->isCertified());
    }
}
