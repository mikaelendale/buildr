<?php

namespace App\Services\Og;

use App\Models\Channel;
use App\Models\Creator;

/**
 * How a share card looks: a light, dark or solid-color card (`theme`) and
 * the color everything prints in (`color`: the portrait, the logo's dot,
 * the badge and the link). The owner's pick is saved on the creator or
 * channel and is what link previews show; anyone can render another look
 * to copy or download.
 */
final readonly class CardStyle
{
    public const THEMES = ['light', 'dark', 'color'];

    /** The five profile tones plus a black-and-white print. */
    public const COLORS = ['mint', 'sky', 'violet', 'amber', 'rose', 'ink'];

    public function __construct(
        public string $theme,
        public string $color,
    ) {}

    /**
     * The saved look, or a light card in the tone seeded from the handle.
     */
    public static function for(Creator|Channel $model): self
    {
        return new self(
            in_array($model->card_theme, self::THEMES, true) ? $model->card_theme : 'light',
            in_array($model->card_color, self::COLORS, true) ? $model->card_color : self::seeded($model),
        );
    }

    /**
     * The color a profile gets when its owner hasn't picked one.
     */
    public static function seeded(Creator|Channel $model): string
    {
        return OgImageRenderer::tone($model instanceof Creator ? $model->slug : $model->username);
    }

    public static function tryFrom(mixed $theme, mixed $color): ?self
    {
        return in_array($theme, self::THEMES, true) && in_array($color, self::COLORS, true)
            ? new self($theme, $color)
            : null;
    }

    public function is(self $other): bool
    {
        return $this->theme === $other->theme && $this->color === $other->color;
    }

    public function key(): string
    {
        return "{$this->theme}-{$this->color}";
    }

    /**
     * @return array{theme: string, color: string}
     */
    public function toArray(): array
    {
        return ['theme' => $this->theme, 'color' => $this->color];
    }
}
