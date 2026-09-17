<?php

namespace App\Support;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use League\CommonMark\Extension\FrontMatter\Data\SymfonyYamlFrontMatterParser;
use League\CommonMark\Extension\FrontMatter\Exception\InvalidFrontMatterException;
use League\CommonMark\Extension\FrontMatter\FrontMatterParser;
use Symfony\Component\Yaml\Yaml;

final class DocFrontMatter
{
    /** Key order in the written block. Stable, so a save produces no spurious diff. */
    private const KEY_ORDER = ['title', 'console', 'category', 'tags', 'created', 'updated'];

    /** Nesting depth before Yaml::dump() switches to inline flow, making `tags` a one-liner. */
    private const INLINE_DEPTH = 1;

    /**
     * Split a file into its metadata and its body.
     *
     * @return array{data: array<string, mixed>, body: string}
     */
    public static function parse(string $contents): array
    {
        try {
            $parsed = (new FrontMatterParser(new SymfonyYamlFrontMatterParser))->parse($contents);
        } catch (InvalidFrontMatterException) {
            // A hand-edit broke the YAML. Show the document rather than lose it.
            return ['data' => [], 'body' => $contents];
        }

        $data = $parsed->getFrontMatter();

        return [
            'data' => is_array($data) ? $data : [],
            'body' => $parsed->getContent(),
        ];
    }

    /**
     * Render metadata and body back into one file.
     *
     * @param  array<string, mixed>  $data
     */
    public static function dump(array $data, string $body): string
    {
        $ordered = Collection::make(self::KEY_ORDER)
            ->filter(fn (string $key): bool => Arr::has($data, $key))
            ->mapWithKeys(fn (string $key): array => [$key => Arr::get($data, $key)])
            ->all();

        // Anything the UI does not own but a hand-edit added is kept, after ours.
        $yaml = Yaml::dump($ordered + array_diff_key($data, $ordered), self::INLINE_DEPTH);

        return "---\n".$yaml."---\n\n".ltrim($body)."\n";
    }
}
