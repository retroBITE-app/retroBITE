<?php

namespace App\Enums;

enum DocTemplate: string
{
    case Blank = 'blank';
    case RepairLog = 'repair-log';
    case Calibration = 'calibration';
    case CompatibilityMatrix = 'compatibility-matrix';
    case Walkthrough = 'walkthrough';

    /**
     * Human-readable name for the UI.
     */
    public function label(): string
    {
        return (string) match ($this) {
            self::Blank => __('Blank note'),
            self::RepairLog => __('Repair log'),
            self::Calibration => __('Calibration'),
            self::CompatibilityMatrix => __('Compatibility matrix'),
            self::Walkthrough => __('Walkthrough'),
        };
    }

    /**
     * The one-line explanation under the name in the picker.
     */
    public function description(): string
    {
        return (string) match ($this) {
            self::Blank => __('Just a title and an empty body.'),
            self::RepairLog => __('Symptom, diagnosis, parts, result — one entry per visit.'),
            self::Calibration => __('Pre-checks, procedure steps, reference table, verify block.'),
            self::CompatibilityMatrix => __('A table of models against revisions and known results.'),
            self::Walkthrough => __('Overview, then chapter by chapter, collectibles and tips.'),
        };
    }

    /**
     * The markdown a new document starts life with, headed by its own title.
     *
     * Every stub ends in a `## References` heading because that is where the
     * reference dialog appends — creating it up front means the first append
     * does not have to invent a section.
     */
    public function body(string $title): string
    {
        return '# '.$title."\n".$this->sections();
    }

    /**
     * Everything below the title.
     */
    private function sections(): string
    {
        return match ($this) {
            self::Blank => "\n## References\n",

            self::RepairLog => <<<'MARKDOWN'

                ## Symptom

                What it does, and what it should do instead.

                ## Diagnosis

                What was measured, and what that ruled out.

                ## Parts

                | Part | Spec | Source |
                | ---- | ---- | ------ |
                |      |      |        |

                ## Result

                What fixed it, and what to watch for next time.

                ## References

                MARKDOWN,

            self::Calibration => <<<'MARKDOWN'

                ## Before you start

                - The reference you are measuring against
                - The instrument, and the precision it needs
                - The original value, written down

                > **Warning** — the step that damages the console if done out of order.

                ## Procedure

                1. Expose the part without disconnecting it.
                2. Measure and note the value before changing anything.
                3. Adjust in the smallest increment the part allows, then retest.
                4. Log the final value and reassemble.

                | Board | Value | Range |
                | ----- | ----- | ----- |
                |       |       |       |

                ## Verify

                - What a good result looks like

                ## References

                MARKDOWN,

            self::CompatibilityMatrix => <<<'MARKDOWN'

                What was tested, and what "works" means here.

                | Model | Revision | Result | Notes |
                | ----- | -------- | ------ | ----- |
                |       |          |        |       |

                ## References

                MARKDOWN,

            self::Walkthrough => <<<'MARKDOWN'

                ## Overview

                What the game is, how long it runs, and what to know before starting.

                ## Chapters

                ### Chapter 1

                Where it starts, what to do, and the way through.

                ## Collectibles

                | Item | Where | Notes |
                | ---- | ----- | ----- |
                |      |       |       |

                ## Tips

                ## References

                MARKDOWN,
        };
    }
}
