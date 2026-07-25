<?php

namespace App\Engines\Studio\Compiler;

/**
 * STUDIO888 Phase J — lightweight, validation-free generation spec DTO.
 *
 * Every field is nullable and populated only when it can be inferred
 * DETERMINISTICALLY from the input. No validation, no AI, no side effects.
 */
class GenerationSpec
{
    public ?string $capability = null;
    public ?string $provider = null;
    public ?string $model = null;
    public ?int $width = null;
    public ?int $height = null;
    public ?string $aspect_ratio = null;
    public ?string $quality = null;
    public ?string $style = null;
    public ?int $seed = null;
    public ?string $negative_prompt = null;
    /** @var array<int,mixed> */
    public array $reference_images = [];
    public ?string $editing_mode = null;
    public ?int $video_duration = null;
    public ?int $fps = null;
    /** @var array<string,mixed> */
    public array $metadata = [];

    public function toArray(): array
    {
        return [
            'capability'       => $this->capability,
            'provider'         => $this->provider,
            'model'            => $this->model,
            'width'            => $this->width,
            'height'           => $this->height,
            'aspect_ratio'     => $this->aspect_ratio,
            'quality'          => $this->quality,
            'style'            => $this->style,
            'seed'             => $this->seed,
            'negative_prompt'  => $this->negative_prompt,
            'reference_images' => $this->reference_images,
            'editing_mode'     => $this->editing_mode,
            'video_duration'   => $this->video_duration,
            'fps'              => $this->fps,
            'metadata'         => $this->metadata,
        ];
    }
}
