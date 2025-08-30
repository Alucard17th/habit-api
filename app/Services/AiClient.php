<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class AiClient
{
    /**
     * Calls chat completion expecting STRICT JSON content.
     * Returns decoded array (empty array on parse failure).
     */
    public function chatJson(string $prompt, array $options = []): array
    {
        $model       = $options['model'] ?? config('services.groq.model');
        $temperature = $options['temperature'] ?? 0.3;

        $resp = \Illuminate\Support\Facades\Http::withToken(config('services.groq.key'))
            ->post(config('services.groq.url'), [
                'model' => $model,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are a careful assistant. Output STRICT JSON only. No preamble, no code fences, no explanations.',
                    ],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => $temperature,
                // 👇 Force JSON object output if supported by provider
                'response_format' => ['type' => 'json_object'],
            ]);

        $resp->throw();

        $respArr = $resp->json();
        $content = data_get($respArr, 'choices.0.message.content', '');

        if (!is_string($content) || trim($content) === '') {
            \Log::warning('AI empty content', ['resp' => $respArr]);
            return [];
        }

        // Try 1: direct decode
        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            \Log::info('AI JSON decode success directly', ['content' => $content]);
            return $decoded;
        }

        // Try 2: extract outermost JSON object substring
        $start = strpos($content, '{');
        $end   = strrpos($content, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $json = substr($content, $start, $end - $start + 1);
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                \Log::info('AI JSON decode success after extract', ['json' => $json, 'content' => $content]);
                return $decoded;
            }
            // Try 3: repair common issues
            $json = $this->repairAlmostJson($json);
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                \Log::info('AI JSON decode success after repair', ['json' => $json, 'content' => $content]);
                return $decoded;
            }
            \Log::warning('AI JSON decode failed after repair', ['json' => $json, 'content' => $content]);
            return [];
        }

        \Log::warning('AI non-JSON content', ['content' => $content, 'resp' => $respArr]);
        return [];
    }

    /**
     * Best-effort fixes for almost-valid JSON from LLMs.
     */
    private function repairAlmostJson(string $json): string
    {
        // Normalize newlines and whitespace
        $s = trim($json);

        // Remove trailing commas before ] or }
        $s = preg_replace('/,(\s*[\]\}])/', '$1', $s);

        // Balance brackets/braces if truncated
        $openCurly  = substr_count($s, '{');
        $closeCurly = substr_count($s, '}');
        $openSquare = substr_count($s, '[');
        $closeSquare= substr_count($s, ']');

        // If it ends inside a string, don’t guess—just return original
        // (we rely on response_format to prevent this generally)
        $quotes = substr_count($s, '"');
        if ($quotes % 2 !== 0) {
            return $s; // odd number of quotes → risky to auto-fix
        }

        // Append missing closers
        if ($closeSquare < $openSquare) {
            $s .= str_repeat(']', $openSquare - $closeSquare);
        }
        if ($closeCurly < $openCurly) {
            $s .= str_repeat('}', $openCurly - $closeCurly);
        }

        return $s;
    }

}
