<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Every reason a ROM upload is turned away, from the first request to the last.
 *
 * Owns the message the person reads, so the service, the chunk route and the
 * modal all say the same thing about the same refusal and none of them has to
 * pass a path or an exception's text back to the browser.
 */
enum UploadRejection: string
{
    case Unconfigured = 'unconfigured';
    case BadName = 'bad_name';
    case BadFolder = 'bad_folder';
    case WrongType = 'wrong_type';
    case Excluded = 'excluded';
    case Destination = 'destination';
    case EmptyFile = 'empty_file';
    case Exists = 'exists';
    case NoSpace = 'no_space';
    case Unknown = 'unknown';
    case OutOfOrder = 'out_of_order';
    case ChunkTooLarge = 'chunk_too_large';
    case Overflow = 'overflow';
    case Incomplete = 'incomplete';
    case Unwritable = 'unwritable';

    /**
     * The fixed sentence shown for this refusal.
     */
    public function label(): string
    {
        return match ($this) {
            self::Unconfigured => __('This console is not in the library yet, so it has no folder to upload into.'),
            self::BadName => __('That file name cannot be used. Rename it without slashes or a leading dot and try again.'),
            self::BadFolder => __('Each game goes in a folder of its own here. Give it a folder name without slashes or a leading dot and try again.'),
            self::WrongType => __('That file type is not one this console plays.'),
            self::Excluded => __('That file is one the library ignores, so it would never appear on the shelf.'),
            self::Destination => __('That folder is not one this console\'s layout reads games from.'),
            self::EmptyFile => __('That file is empty.'),
            self::Exists => __('A file with that name is already in that folder.'),
            self::NoSpace => __('There is not enough free space in the library for that file.'),
            self::Unknown => __('That upload has expired or was never started. Start it again.'),
            self::OutOfOrder => __('The upload fell out of step and is resuming.'),
            self::ChunkTooLarge => __('That piece of the upload was larger than the server accepts.'),
            self::Overflow => __('More arrived than the file was said to hold.'),
            self::Incomplete => __('The upload did not finish. Start it again.'),
            self::Unwritable => __('The file could not be written into the library.'),
        };
    }
}
