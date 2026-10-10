<?php

namespace App\Support\Markdown;

use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\Node\Block\ListBlock;
use League\CommonMark\Extension\ExtensionInterface;
use League\CommonMark\Node\Node;

/**
 * Numbered steps as docs are written rather than as CommonMark reads them.
 *
 * A bullet list typed flush left straight under a step is that step's detail,
 * but CommonMark closes the numbered list and starts a new one beside it. And
 * a list that resumes at 3 after a code block carries start="3", which the
 * badge's CSS counter would ignore and draw as 1.
 */
final class DocListExtension implements ExtensionInterface
{
    public function register(EnvironmentBuilderInterface $environment): void
    {
        $environment->addEventListener(DocumentParsedEvent::class, $this->shape(...));
    }

    /** Nest and rejoin first, then number what is left, so every badge counts from its own start. */
    private function shape(DocumentParsedEvent $event): void
    {
        $document = $event->getDocument();

        $this->adoptBullets($document);

        foreach ($document->iterator() as $node) {
            if ($this->isOrdered($node)) {
                $this->numberFrom($node);
            }
        }
    }

    /** Walk every container, so a step list inside a blockquote or another list is shaped too. */
    private function adoptBullets(Node $parent): void
    {
        for ($child = $parent->firstChild(); $child !== null; $child = $child->next()) {
            if ($this->isOrdered($child)) {
                $this->absorbFollowing($child);
            }

            $this->adoptBullets($child);
        }
    }

    /**
     * Pull the lists that belong to this one into it: a bullet list on the very
     * next line goes under the last step, and a numbered list that carries on
     * the count after it joins as more steps.
     */
    private function absorbFollowing(ListBlock $list): void
    {
        $end = $list->getEndLine();

        while (($next = $list->next()) instanceof ListBlock) {
            if ($next->getListData()->type !== ListBlock::TYPE_ORDERED) {
                $step = $list->lastChild();

                // A blank line between them makes the bullets a list of their own.
                if ($step === null || $end === null || $next->getStartLine() !== $end + 1) {
                    return;
                }

                $step->appendChild($next);
                $end = $next->getEndLine();

                continue;
            }

            if ($next->getListData()->start !== $this->startOf($list) + iterator_count($list->children())) {
                return;
            }

            foreach ($next->children() as $item) {
                $list->appendChild($item);
            }

            $next->detach();
            $end = $next->getEndLine();
        }
    }

    /** Hand the badge counter the list's own start; the step counter increments before it draws. */
    private function numberFrom(ListBlock $list): void
    {
        $start = $this->startOf($list);

        if ($start !== 1) {
            $list->data->set('attributes/style', 'counter-reset: step '.($start - 1));
        }
    }

    /** @phpstan-assert-if-true ListBlock $node */
    private function isOrdered(Node $node): bool
    {
        return $node instanceof ListBlock && $node->getListData()->type === ListBlock::TYPE_ORDERED;
    }

    /** A list's first number; CommonMark leaves it null on bullet lists only. */
    private function startOf(ListBlock $list): int
    {
        return $list->getListData()->start ?? 1;
    }
}
