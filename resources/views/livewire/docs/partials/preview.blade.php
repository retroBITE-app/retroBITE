{{--
    Rendered markdown, in a lightbox.

    The images are read out of the DOM rather than passed in from PHP: the HTML
    is rendered server-side and printed unescaped, so the nodes themselves are
    the only list guaranteed to match what is on screen.
--}}
<x-lightbox selector="[data-doc-body] img">
    <div data-doc-body>{!! $this->html !!}</div>
</x-lightbox>
