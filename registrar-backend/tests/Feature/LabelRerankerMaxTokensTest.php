<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\LabelReranker;
use Tests\TestCase;

class LabelRerankerMaxTokensTest extends TestCase
{
    /** @var list<array{key:string,type:string,name:string,patterns:list<string>}> */
    private array $candidates = [
        ['key' => 'd1', 'type' => 'document', 'name' => 'Diploma', 'patterns' => ['Diploma']],
        ['key' => 'd2', 'type' => 'document', 'name' => 'Transcript of Records', 'patterns' => []],
    ];

    private function maxTokensFor(mixed $configured): int
    {
        config(['services.anthropic.label_max_tokens' => $configured]);

        $payload = (new LabelReranker())->buildPayload('Diploma', $this->candidates);

        $this->assertIsArray($payload);

        return $payload['max_tokens'];
    }

    public function test_default_budget_is_unchanged_at_80(): void
    {
        $this->assertSame(80, $this->maxTokensFor(80));
    }

    public function test_a_numeric_string_from_the_environment_is_used_as_an_integer(): void
    {
        $this->assertSame(400, $this->maxTokensFor('400'));
    }

    public function test_empty_or_non_numeric_values_fall_back_to_80(): void
    {
        $this->assertSame(80, $this->maxTokensFor(''));
        $this->assertSame(80, $this->maxTokensFor(null));
        $this->assertSame(80, $this->maxTokensFor('lots'));
    }

    public function test_values_are_clamped_to_a_safe_range(): void
    {
        $this->assertSame(1024, $this->maxTokensFor(999999));
        $this->assertSame(1, $this->maxTokensFor(0));
        $this->assertSame(1, $this->maxTokensFor(-5));
    }
}