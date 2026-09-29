<?php

declare(strict_types=1);

namespace App\Conversion\Converters;

use App\Conversion\Converter;
use App\Conversion\Setting;

/**
 * What every maxcso conversion shares.
 *
 * maxcso draws its progress only when stderr is a terminal, and a queue
 * worker's is not, so it reports nothing here: the runner reads how far into
 * the source it has got instead.
 */
abstract class MaxcsoConverter extends Converter
{
    public function tool(): string
    {
        return 'maxcso';
    }

    /** @return list<Setting> */
    public function settings(): array
    {
        return [$this->threads()];
    }

    /** maxcso's --threads. As many as it likes unless told. */
    protected function threads(): Setting
    {
        return new Setting(
            key: 'threads',
            label: __('Threads (--threads)'),
            description: __('How many threads maxcso works on. Fewer leaves room for everything else on the machine.'),
            default: 'auto',
            choices: ['auto' => __('Automatic (maxcso default)'), '1' => '1', '2' => '2', '4' => '4', '8' => '8'],
        );
    }

    /**
     * The --threads flag for the setting as queued, or none.
     *
     * @param  array<string, mixed>  $options
     * @return list<string>
     */
    protected function threadArguments(array $options): array
    {
        $threads = $this->setting($options, 'threads');

        return $threads === 'auto' ? [] : ['--threads='.$threads];
    }
}
