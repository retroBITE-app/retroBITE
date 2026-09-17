<?php

namespace App\Support\Markdown;

use Illuminate\Support\Collection;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Inline\Newline;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\Block\ParagraphRenderer;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\HtmlElement;

final class DocParagraphRenderer implements NodeRendererInterface
{
    private readonly ParagraphRenderer $paragraphs;

    private readonly VideoEmbedRenderer $videos;

    public function __construct()
    {
        $this->paragraphs = new ParagraphRenderer;
        $this->videos = new VideoEmbedRenderer;
    }

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): \Stringable|string|null
    {
        if (! $node instanceof Paragraph) {
            throw new \InvalidArgumentException('Expected a '.Paragraph::class);
        }

        $embeds = self::embeds($node);

        if ($embeds === null) {
            return $this->paragraphs->render($node, $childRenderer);
        }

        return $embeds
            ->map(fn (Image|VideoEmbed $embed): string => (string) ($embed instanceof Image
                ? self::figure($embed)
                : $this->videos->render($embed, $childRenderer)))
            ->implode('');
    }

    /**
     * The embeds this paragraph consists of, or null if it holds anything else.
     *
     * A loop rather than a filter chain: bailing on the first non-embed is both
     * the clearer read and the only shape PHPStan can infer an element type from.
     *
     * @return Collection<int, Image|VideoEmbed>|null
     */
    private static function embeds(Node $node): ?Collection
    {
        $embeds = [];

        foreach ($node->children() as $child) {
            // A softbreak is what joins two images written on consecutive lines.
            if ($child instanceof Newline) {
                continue;
            }

            if ($child instanceof Text && trim($child->getLiteral()) === '') {
                continue;
            }

            if (! $child instanceof Image && ! $child instanceof VideoEmbed) {
                return null;
            }

            $embeds[] = $child;
        }

        return $embeds === [] ? null : Collection::make($embeds);
    }

    /**
     * A captioned figure, the image's alt text serving as the caption.
     */
    private static function figure(Image $image): HtmlElement
    {
        $caption = self::caption($image);

        $children = [new HtmlElement('img', [
            'src' => $image->getUrl(),
            'alt' => $caption,
            'loading' => 'lazy',
        ], '', true)];

        if ($caption !== '') {
            $children[] = new HtmlElement('figcaption', [], $caption);
        }

        return new HtmlElement('figure', [], $children);
    }

    /**
     * An image's alt text, which is what the caption shows.
     */
    private static function caption(Image $image): string
    {
        return trim(Collection::make($image->iterator())
            ->filter(fn (Node $node): bool => $node instanceof Text)
            ->map(fn (Text $node): string => $node->getLiteral())
            ->implode(''));
    }
}
