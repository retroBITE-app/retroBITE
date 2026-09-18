<?php

namespace App\Support\Markdown;

use League\CommonMark\Parser\Inline\InlineParserInterface;
use League\CommonMark\Parser\Inline\InlineParserMatch;
use League\CommonMark\Parser\InlineParserContext;

final class VideoEmbedParser implements InlineParserInterface
{
    public function getMatchDefinition(): InlineParserMatch
    {
        return InlineParserMatch::regex('@video\[([^\]\s]+)\]');
    }

    /**
     * Declining the match leaves the literal text in place, which is the right
     * outcome for a URL we will not embed: the file still reads correctly.
     */
    public function parse(InlineParserContext $inlineContext): bool
    {
        $id = VideoEmbed::idFrom($inlineContext->getSubMatches()[0] ?? '');

        if ($id === null) {
            return false;
        }

        $inlineContext->getCursor()->advanceBy($inlineContext->getFullMatchLength());
        $inlineContext->getContainer()->appendChild(new VideoEmbed($id));

        return true;
    }
}
