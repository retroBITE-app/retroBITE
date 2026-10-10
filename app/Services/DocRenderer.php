<?php

namespace App\Services;

use App\Exceptions\DocPathException;
use App\Resources\DocResource;
use App\Support\DocPath;
use App\Support\Markdown\DocImageExtension;
use App\Support\Markdown\DocLinkExtension;
use App\Support\Markdown\DocListExtension;
use App\Support\Markdown\VideoEmbedExtension;
use Closure;
use Illuminate\Support\Str;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;

class DocRenderer
{
    /**
     * Raw HTML is stripped and unsafe link schemes refused, because the output
     * is printed unescaped. These two settings are what make that safe.
     */
    private const OPTIONS = [
        'html_input' => 'strip',
        'allow_unsafe_links' => false,
        'max_nesting_level' => 50,
    ];

    public function __construct(private readonly DocPath $paths) {}

    /**
     * The environment is built per document rather than once, because the
     * attachment links it rewrites are relative to the document being rendered.
     */
    public function render(DocResource $doc): string
    {
        $environment = new Environment(self::OPTIONS);

        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new GithubFlavoredMarkdownExtension);
        $environment->addExtension(new VideoEmbedExtension);
        $environment->addExtension(new DocLinkExtension);
        $environment->addExtension(new DocListExtension);
        $environment->addExtension(new DocImageExtension($this->mediaResolver($doc)));

        return (new MarkdownConverter($environment))->convert($doc->body)->getContent();
    }

    /**
     * Turns `media/name.jpg` into the authenticated route that serves it, and
     * leaves every other URL — including absolute ones — alone.
     *
     * @return Closure(string): string
     */
    private function mediaResolver(DocResource $doc): Closure
    {
        $prefix = DocPath::MEDIA_DIR.'/';

        return function (string $url) use ($doc, $prefix): string {
            if (! Str::startsWith($url, $prefix)) {
                return $url;
            }

            try {
                $path = $this->paths->mediaFor($doc->path, (string) Str::after($url, $prefix));
            } catch (DocPathException) {
                // A link naming something we would never serve renders as a
                // broken image rather than reaching the media route at all.
                return '';
            }

            return route('docs.media', ['path' => $path]);
        };
    }
}
