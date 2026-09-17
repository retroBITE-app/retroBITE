<?php

namespace App\Support\Markdown;

use Closure;
use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\ExtensionInterface;
use League\CommonMark\Node\Block\Paragraph;

final class DocImageExtension implements ExtensionInterface
{
    /** @param  Closure(string): string  $resolveUrl */
    public function __construct(private readonly Closure $resolveUrl) {}

    public function register(EnvironmentBuilderInterface $environment): void
    {
        $environment->addEventListener(DocumentParsedEvent::class, $this->rewriteImageUrls(...));
        $environment->addRenderer(Paragraph::class, new DocParagraphRenderer, 10);
    }

    /**
     * Point every attachment link at the route that serves it.
     */
    private function rewriteImageUrls(DocumentParsedEvent $event): void
    {
        foreach ($event->getDocument()->iterator() as $node) {
            if ($node instanceof Image) {
                $node->setUrl(($this->resolveUrl)($node->getUrl()));
            }
        }
    }
}
