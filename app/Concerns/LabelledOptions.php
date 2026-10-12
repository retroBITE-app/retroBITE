<?php

declare(strict_types=1);

namespace App\Concerns;

/**
 * For a string-backed enum with a label(): its cases as the value => label
 * map a select or a pill row draws, in declaration order.
 */
trait LabelledOptions
{
    /**
     * Every case by value, labelled, in the order the enum declares them.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
