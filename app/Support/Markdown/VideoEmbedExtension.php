<?php

namespace App\Support\Markdown;

use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Extension\ExtensionInterface;

final class VideoEmbedExtension implements ExtensionInterface
{
    public function register(EnvironmentBuilderInterface $environment): void
    {
        $environment->addInlineParser(new VideoEmbedParser);
        $environment->addRenderer(VideoEmbed::class, new VideoEmbedRenderer);
    }
}
