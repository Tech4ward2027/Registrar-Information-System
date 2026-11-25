<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\LabelReranker;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class LabelRerankerDiagnosticsTest extends TestCase
{
    private const MESSAGE = 'LabelReranker: unusable answer';

    /** @var list<array{key:string,type:string,name:string,patterns:list<string>}> */
    private array $candidates = [
        ['key' => 'd1', 'type' => 'document', 'name' => 'Diploma', 'patterns' => ['Diploma']],
        ['key' => 'd2', 'type' => 'document', 'name' => 'Transcript of Records', 'patterns' => []],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'features.ai_label_suggestions'      => true,
            'services.anthropic.api_key'         => 'test-key',
            'services.anthropic.label_model'     => 'test-model',
            'services.anthropic.label_temperature' => 0,
            'services.anthropic.label_max_tokens'  => 80,
        ]);
    }

    private function fakeReply(array $body): void
    {
        Http::fake(['https://api.anthropic.com/*' => Http::response($body, 200)]);
        Log::spy();
    }

    public function test_prose_answer_logs_its_shape_but_never_its_text(): void
    {
        $this->fakeReply([
            'stop_reason' => 'end_turn',
            'content'     => [['type' => 'text', 'text' => 'This is ambiguous SECRETTEXT']],
            'usage'       => ['output_tokens' => 12],
        ]);

        $reranker = new LabelReranker();

        $this->assertNull($reranker->rerank('Diploma', $this->candidates));
        $this->assertSame(LabelReranker::OUTCOME_MALFORMED, $reranker->lastOutcome());

        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context): bool {
            return $message === self::MESSAGE
                && $context['outcome'] === 'malformed'
                && $context['stop_reason'] === 'end_turn'
                && $context['output_tokens'] === 12
                && $context['block_types'] === ['text']
                && $context['text_length'] === strlen('This is ambiguous SECRETTEXT')
                && $context['starts_with_brace'] === false
                && $context['contains_brace'] === false
                && $context['has_code_fence'] === false
                && !str_contains(json_encode($context), 'SECRETTEXT');
        })->once();
    }

    public function test_json_wrapped_in_prose_is_distinguishable_from_pure_prose(): void
    {
        $this->fakeReply([
            'stop_reason' => 'end_turn',
            'content'     => [['type' => 'text', 'text' => 'Looking at it: {"choice":"d1","reason":"x"}']],
            'usage'       => ['output_tokens' => 20],
        ]);

        $reranker = new LabelReranker();
        $reranker->rerank('Diploma', $this->candidates);

        $this->assertSame(LabelReranker::OUTCOME_MALFORMED, $reranker->lastOutcome());

        Log::shouldHaveReceived('info')->withArgs(
            fn (string $message, array $context): bool => $message === self::MESSAGE
                && $context['starts_with_brace'] === false
                && $context['contains_brace'] === true
        )->once();
    }

    public function test_truncated_answer_logs_stop_reason_and_block_types(): void
    {
        $this->fakeReply([
            'stop_reason' => 'max_tokens',
            'content'     => [
                ['type' => 'thinking', 'thinking' => 'internal'],
                ['type' => 'text', 'text' => '{"choice":"d'],
            ],
            'usage' => ['output_tokens' => 80],
        ]);

        $reranker = new LabelReranker();
        $reranker->rerank('Diploma', $this->candidates);

        $this->assertSame(LabelReranker::OUTCOME_TRUNCATED, $reranker->lastOutcome());

        Log::shouldHaveReceived('info')->withArgs(
            fn (string $message, array $context): bool => $message === self::MESSAGE
                && $context['outcome'] === 'truncated'
                && $context['stop_reason'] === 'max_tokens'
                && $context['output_tokens'] === 80
                && $context['block_types'] === ['thinking', 'text']
                && !str_contains(json_encode($context), 'internal')
        )->once();
    }

    public function test_usable_answers_log_nothing(): void
    {
        $this->fakeReply([
            'stop_reason' => 'end_turn',
            'content'     => [['type' => 'text', 'text' => '{"choice":"d1","reason":"exact match"}']],
            'usage'       => ['output_tokens' => 15],
        ]);

        $reranker = new LabelReranker();

        $this->assertSame('d1', $reranker->rerank('Diploma', $this->candidates)['choice']);
        $this->assertSame(LabelReranker::OUTCOME_CHOICE, $reranker->lastOutcome());

        Log::shouldNotHaveReceived('info');
    }
}