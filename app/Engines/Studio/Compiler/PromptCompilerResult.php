<?php

namespace App\Engines\Studio\Compiler;

/**
 * STUDIO888 Phase J — immutable result of a shadow prompt compilation.
 * Pure data; carries no behaviour and never touches execution.
 */
class PromptCompilerResult
{
    public function __construct(
        public readonly string $originalPrompt,
        public readonly string $compiledPrompt,
        public readonly GenerationSpec $spec,
        /** @var array<string,mixed> language/length/intent metadata */
        public readonly array $metadata,
        public readonly float $confidence,
        /** @var array<int,string> */
        public readonly array $warnings,
        public readonly string $compilerVersion,
        /** @var array<string,mixed> shadow-comparison result */
        public readonly array $comparison,
    ) {}

    public function toArray(): array
    {
        return [
            'original_prompt'  => $this->originalPrompt,
            'compiled_prompt'  => $this->compiledPrompt,
            'generation_spec'  => $this->spec->toArray(),
            'metadata'         => $this->metadata,
            'confidence'       => $this->confidence,
            'warnings'         => $this->warnings,
            'compiler_version' => $this->compilerVersion,
            'comparison'       => $this->comparison,
        ];
    }
}
