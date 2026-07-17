<?php

namespace App\Support\CanonicalRegistry;

class CanonicalGovernanceReader
{
    public function __construct(
        private readonly string $registryDocumentPath = '',
        private readonly string $gapsDocumentPath = '',
        private readonly CanonicalRegistryReader $registryReader = new CanonicalRegistryReader,
    ) {
        $this->registryDocumentPath = $registryDocumentPath !== ''
            ? $registryDocumentPath
            : config('canonical_registry.registry_document_path');
        $this->gapsDocumentPath = $gapsDocumentPath !== ''
            ? $gapsDocumentPath
            : base_path('docs/IMPLEMENTATION_GAPS.md');
    }

    /**
     * @return list<array{id: string, type: string, title: string}>
     */
    public function listDecisions(): array
    {
        $items = [];

        foreach ($this->parseHeadings($this->registryDocumentPath, '/^### (DEC-\d+) — (.+)$/m') as $id => $title) {
            $items[] = ['id' => $id, 'type' => 'DEC', 'title' => $title];
        }

        foreach ($this->parseHeadings($this->gapsDocumentPath, '/^## (GAP-\d+) — (.+)$/m') as $id => $title) {
            $items[] = ['id' => $id, 'type' => 'GAP', 'title' => $title];
        }

        usort($items, fn (array $a, array $b): int => [$a['type'], $a['id']] <=> [$b['type'], $b['id']]);

        return $items;
    }

    /**
     * @return array{id: string, type: string, title: string, body: string}|null
     */
    public function getDecision(string $id): ?array
    {
        $type = str_starts_with($id, 'GAP-') ? 'GAP' : 'DEC';
        $path = $type === 'GAP' ? $this->gapsDocumentPath : $this->registryDocumentPath;
        $pattern = $type === 'GAP'
            ? '/^## ('.preg_quote($id, '/').') — (.+)$/m'
            : '/^### ('.preg_quote($id, '/').') — (.+)$/m';

        $content = file_get_contents($path);
        if ($content === false) {
            return null;
        }

        if (! preg_match($pattern, $content, $headingMatch, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $title = $headingMatch[2][0];
        $start = $headingMatch[0][1] + strlen($headingMatch[0][0]);
        $nextHeadingPattern = $type === 'GAP' ? '/^## GAP-\d+/m' : '/^### DEC-\d+/m';

        $remainder = substr($content, $start);
        $end = strlen($remainder);
        if (preg_match($nextHeadingPattern, $remainder, $nextMatch, PREG_OFFSET_CAPTURE, 1)) {
            $end = $nextMatch[0][1];
        }

        return [
            'id' => $id,
            'type' => $type,
            'title' => $title,
            'body' => trim(substr($remainder, 0, $end)),
        ];
    }

    /**
     * @return list<array<string, string>>
     */
    public function sourcesForSubject(string $evidenceSubjectKey): array
    {
        return collect($this->registryReader->sources())
            ->filter(fn (array $row): bool => $row['evidence_subject_key'] === $evidenceSubjectKey)
            ->values()
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function parseHeadings(string $path, string $pattern): array
    {
        if (! is_file($path)) {
            return [];
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return [];
        }

        preg_match_all($pattern, $content, $matches, PREG_SET_ORDER);

        $headings = [];
        foreach ($matches as $match) {
            $headings[$match[1]] = $match[2];
        }

        return $headings;
    }
}
