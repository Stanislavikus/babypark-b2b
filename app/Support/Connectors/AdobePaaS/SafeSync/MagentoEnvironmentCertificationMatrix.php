<?php

namespace App\Support\Connectors\AdobePaaS\SafeSync;

final class MagentoEnvironmentCertificationMatrix
{
    public function evaluate(?string $magentoVersion, ?string $phpVersion): MagentoEnvironmentCertificationAssessment
    {
        $magentoVersion = $this->normalize($magentoVersion);
        $phpVersion = $this->normalize($phpVersion);

        if ($magentoVersion === null || $phpVersion === null) {
            return new MagentoEnvironmentCertificationAssessment(
                MagentoEnvironmentCertificationAssessment::EXACT_VERSION_PENDING,
                $magentoVersion,
                $phpVersion,
            );
        }

        return match (true) {
            $magentoVersion === '2.4.9' && $this->isPhp85($phpVersion) => new MagentoEnvironmentCertificationAssessment(
                MagentoEnvironmentCertificationAssessment::PRIMARY,
                $magentoVersion,
                $phpVersion,
            ),
            $magentoVersion === '2.4.9' && $this->isPhp84($phpVersion) => new MagentoEnvironmentCertificationAssessment(
                MagentoEnvironmentCertificationAssessment::UPGRADE_COMPATIBILITY,
                $magentoVersion,
                $phpVersion,
            ),
            $magentoVersion === '2.4.8-p5' && $this->isPhp84($phpVersion) => new MagentoEnvironmentCertificationAssessment(
                MagentoEnvironmentCertificationAssessment::PREVIOUS_CERTIFIED,
                $magentoVersion,
                $phpVersion,
            ),
            default => new MagentoEnvironmentCertificationAssessment(
                MagentoEnvironmentCertificationAssessment::NOT_CERTIFIED,
                $magentoVersion,
                $phpVersion,
            ),
        };
    }

    private function normalize(?string $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === '' ? null : $value;
    }

    private function isPhp84(string $version): bool
    {
        return preg_match('/^8\.4(?:\.|$)/', $version) === 1;
    }

    private function isPhp85(string $version): bool
    {
        return preg_match('/^8\.5(?:\.|$)/', $version) === 1;
    }
}
