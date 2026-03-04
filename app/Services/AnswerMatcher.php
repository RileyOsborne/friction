<?php

namespace App\Services;

use App\Models\Answer;
use Illuminate\Support\Collection;

class AnswerMatcher
{
    /**
     * Find a matching answer from a collection using fuzzy matching.
     */
    public function match(string $input, Collection $answers): ?Answer
    {
        $input = trim($input);
        $inputLower = strtolower($input);
        $inputNormalized = $this->normalize($input);

        // 1. Exact match against full text (case-insensitive)
        foreach ($answers as $answer) {
            if (strtolower($answer->text) === $inputLower) {
                return $answer;
            }
        }

        // 2. Exact match against display_text
        foreach ($answers as $answer) {
            if (strtolower($answer->display_text) === $inputLower) {
                return $answer;
            }
        }

        // 3. Normalized match
        foreach ($answers as $answer) {
            if ($this->normalize($answer->text) === $inputNormalized || 
                $this->normalize($answer->display_text) === $inputNormalized) {
                return $answer;
            }
        }

        // 4. Containment match (minimum length 4 to avoid false positives)
        foreach ($answers as $answer) {
            $answerNorm = $this->normalize($answer->text);
            $displayNorm = $this->normalize($answer->display_text);

            foreach ([$answerNorm, $displayNorm] as $targetNorm) {
                if (str_contains($inputNormalized, $targetNorm) || str_contains($targetNorm, $inputNormalized)) {
                    $shorter = strlen($inputNormalized) < strlen($targetNorm) ? $inputNormalized : $targetNorm;
                    if (strlen($shorter) >= 4) {
                        return $answer;
                    }
                }
            }
        }

        // 5. Similarity match using Levenshtein distance
        $bestMatch = null;
        $bestScore = PHP_INT_MAX;

        foreach ($answers as $answer) {
            foreach ([$answer->text, $answer->display_text] as $target) {
                $targetNorm = $this->normalize($target);
                $distance = @levenshtein($inputNormalized, $targetNorm);
                $maxDistance = strlen($targetNorm) > 5 ? 2 : 1;

                if ($distance <= $maxDistance && $distance < $bestScore) {
                    $bestScore = $distance;
                    $bestMatch = $answer;
                }
            }
        }

        return $bestMatch;
    }

    /**
     * Normalize a string for matching (lowercase, remove articles, remove punctuation).
     */
    public function normalize(string $text): string
    {
        $text = strtolower(trim($text));
        
        $articles = [
            'the ', 'a ', 'an ', 'el ', 'la ', 'los ', 'las ', 
            'le ', 'les ', 'der ', 'die ', 'das '
        ];

        foreach ($articles as $article) {
            if (str_starts_with($text, $article)) {
                $text = substr($text, strlen($article));
                break;
            }
        }

        $text = preg_replace('/[^\w\s]/', '', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        
        return trim($text);
    }
}
