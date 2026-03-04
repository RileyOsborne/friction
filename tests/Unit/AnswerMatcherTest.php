<?php

namespace Tests\Unit;

use App\Models\Answer;
use App\Services\AnswerMatcher;
use Illuminate\Support\Collection;
use Tests\TestCase;

class AnswerMatcherTest extends TestCase
{
    private AnswerMatcher $matcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->matcher = new AnswerMatcher();
    }

    public function test_normalization_removes_articles_and_punctuation()
    {
        $this->assertEquals('apple', $this->matcher->normalize('The Apple'));
        $this->assertEquals('apple', $this->matcher->normalize('An Apple!'));
        $this->assertEquals('big apple', $this->matcher->normalize('  the  Big   Apple...  '));
    }

    public function test_exact_match()
    {
        $answers = collect([
            new Answer(['text' => 'Apple', 'display_text' => 'Apple', 'points' => 10, 'position' => 1]),
            new Answer(['text' => 'Banana', 'display_text' => 'Banana', 'points' => 8, 'position' => 2]),
        ]);

        $match = $this->matcher->match('Apple', $answers);
        $this->assertEquals('Apple', $match->text);
    }

    public function test_fuzzy_match_levenshtein()
    {
        $answers = collect([
            new Answer(['text' => 'Apple', 'display_text' => 'Apple', 'points' => 10, 'position' => 1]),
        ]);

        // "Aple" should match "Apple"
        $match = $this->matcher->match('Aple', $answers);
        $this->assertEquals('Apple', $match->text);
    }

    public function test_fuzzy_match_normalization()
    {
        $answers = collect([
            new Answer(['text' => 'The Big Apple', 'display_text' => 'The Big Apple', 'points' => 10, 'position' => 1]),
        ]);

        $match = $this->matcher->match('big apple', $answers);
        $this->assertEquals('The Big Apple', $match->text);
    }

    public function test_no_match_returns_null()
    {
        $answers = collect([
            new Answer(['text' => 'Apple', 'display_text' => 'Apple', 'points' => 10, 'position' => 1]),
        ]);

        $match = $this->matcher->match('Orange', $answers);
        $this->assertNull($match);
    }
}
