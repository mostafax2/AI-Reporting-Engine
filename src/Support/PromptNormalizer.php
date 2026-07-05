<?php

declare(strict_types=1);

namespace Mostafax\AiReportingEngine\Support;

use Mostafax\AiReportingEngine\Contracts\PromptNormalizerInterface;

/**
 * Normalizes a natural-language prompt into a canonical form so that equivalent
 * questions produce the same cache fingerprint (e.g. "عدد الطلاب" vs "عدد الطُلّاب").
 */
final class PromptNormalizer implements PromptNormalizerInterface
{
    public function normalize(string $prompt): string
    {
        $text = trim($prompt);
        $text = $this->normalizeArabic($text);
        $text = mb_strtolower($text);
        $text = $this->stripTashkeel($text);
        $text = $this->stripPunctuation($text);
        $text = $this->collapseSpaces($text);

        return trim($text);
    }

    public function fingerprint(string $normalizedPrompt): string
    {
        return hash('sha256', $normalizedPrompt);
    }

    private function normalizeArabic(string $text): string
    {
        return strtr($text, [
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا',
            'ى' => 'ي',
            'ة' => 'ه',
            'ؤ' => 'و',
            'ئ' => 'ي',
        ]);
    }

    private function stripTashkeel(string $text): string
    {
        return preg_replace('/[\x{064B}-\x{0652}\x{0640}]/u', '', $text) ?? $text;
    }

    private function stripPunctuation(string $text): string
    {
        return preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text) ?? $text;
    }

    private function collapseSpaces(string $text): string
    {
        return preg_replace('/\s+/u', ' ', $text) ?? $text;
    }
}
