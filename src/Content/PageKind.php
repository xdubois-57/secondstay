<?php

declare(strict_types=1);

namespace SecondStay\Content;

/**
 * Nature d'une page : elle détermine le gabarit et le comportement.
 */
enum PageKind: string
{
    case Home = 'home';
    case Page = 'page';
    case Gallery = 'gallery';
    case Legal = 'legal';
    case Contact = 'contact';

    public static function fromString(string $value): self
    {
        return self::tryFrom(strtolower(trim($value))) ?? self::Page;
    }

    public function isLegal(): bool
    {
        return $this === self::Legal;
    }

    public function template(): string
    {
        return match ($this) {
            self::Home => 'public/home.html.twig',
            self::Gallery => 'public/gallery.html.twig',
            self::Contact => 'public/contact.html.twig',
            default => 'public/page.html.twig',
        };
    }

    public function labelKey(): string
    {
        return 'content.kind.' . $this->value;
    }
}
