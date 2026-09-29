<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a conversion stopped short.
 *
 * Owns the sentence the person reads. The tool's own words go to the
 * conversion's log, and anything thrown goes to the application log: neither
 * is shown as the reason, because both carry paths from inside the container.
 */
enum ConversionFailure: string
{
    case Unsupported = 'unsupported';
    case ToolMissing = 'tool_missing';
    case SourceMissing = 'source_missing';
    case Exists = 'exists';
    case ToolFailed = 'tool_failed';
    case NoOutput = 'no_output';
    case VerifyFailed = 'verify_failed';
    case TimedOut = 'timed_out';
    case Unwritable = 'unwritable';
    case Interrupted = 'interrupted';

    public function label(): string
    {
        return match ($this) {
            self::Unsupported => __('This conversion is not offered for this file on this console.'),
            self::ToolMissing => __('The tool this conversion needs is not installed.'),
            self::SourceMissing => __('A source file is missing from the library.'),
            self::Exists => __('A file with the output\'s name is already there. Nothing was written.'),
            self::ToolFailed => __('The conversion tool reported an error. See the log.'),
            self::NoOutput => __('The conversion tool finished without writing its output.'),
            self::VerifyFailed => __('The output did not verify, so it was not kept.'),
            self::TimedOut => __('The conversion took longer than it is allowed and was stopped.'),
            self::Unwritable => __('The output could not be put into the console\'s folder.'),
            self::Interrupted => __('Interrupted: the worker stopped while this was running.'),
        };
    }
}
