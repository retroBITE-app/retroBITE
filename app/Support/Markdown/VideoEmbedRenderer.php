<?php

namespace App\Support\Markdown;

use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\HtmlElement;

final class VideoEmbedRenderer implements NodeRendererInterface
{
    /** Nothing is loaded from youtube.com until the viewer presses play. */
    private const ORIGIN = 'https://www.youtube-nocookie.com/embed/';

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): HtmlElement
    {
        if (! $node instanceof VideoEmbed) {
            throw new \InvalidArgumentException('Expected a '.VideoEmbed::class);
        }

        return new HtmlElement('div', ['class' => 'doc-video'], new HtmlElement('iframe', [
            'src' => self::ORIGIN.$node->id,
            'title' => 'YouTube',
            'loading' => 'lazy',
            'referrerpolicy' => 'strict-origin-when-cross-origin',
            'allow' => 'accelerometer; clipboard-write; encrypted-media; picture-in-picture',
            'allowfullscreen' => '',
        ], '', false));
    }
}
