# Blade components

Every piece of markup the app draws more than once is an anonymous Blade
component under `resources/views/components/`. This is the catalogue: what
each one is for, what it takes, and where it is already used. Read it before
writing markup for a screen, so a new page is built from these rather than
from a copy of another page's classes.

- **Anonymous components only.** A file with `@props` and a short `{{-- --}}`
  header saying why it exists. No class components.
- **Flat primitives, namespaced domain pieces.** Generic building blocks sit at
  the top level (`x-badge`, `x-panel`, `x-modal`, `x-chip`…). Pieces that only
  make sense in one part of the app live in a folder: `x-settings.*`,
  `x-sidebar.*`, `x-game-file.*`, `x-decrypt.*`, `x-docs.*`.
- **Attributes pass through.** Nearly every component merges `$attributes`
  (`->class(...)` / `->merge(...)`) onto its root, so `wire:click`,
  `wire:key`, `x-on:*`, `x-show` and extra classes work as on a plain element.
  Where they land elsewhere, the entry says so. A handful take none at all:
  `x-check`, `x-game-card`, `x-transfer-modal`, `x-transfer-tray`,
  `x-docs.console-picker`, `x-settings.layout`.
- **Interactive ones are real controls.** A `<button type="button">`, or an `<a>`
  when given an `href`, with the `focus-ring` utility for keyboard focus.
  `focus-ring` is defined in `resources/css/app.css`:
  `focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep`.
- **Flux first.** Where Flux has the thing (modal, tooltip, field, dropdown),
  the component wraps it rather than rebuilding it.
- **Reuse before you write.** If a component is close to what you need,
  extend it with a prop and update its callers; do not hand-copy its markup
  into the page. Two near-copies drift apart.

## Gotchas

- **`@js()` does not compile inside a component tag's attributes.** Use
  `{{ Js::from(...) }}`: `wire:click="filter({{ Js::from($value) }})"`.
  (Inside a component's own file, on plain elements, `@js()` is fine.)
- **A bare attribute on a component renders as `attr="attr"`.**
  `<x-file-drop x-on:dragover.prevent />` hands Alpine the expression
  `x-on:dragover.prevent`. Give it a value: `x-on:dragover.prevent=""`.
- **Do not mix inline `@php(...)` and block `@php … @endphp` in one file.**
  Blade pairs them up wrongly and the compiled view breaks. Pick one form per
  file.
- **`</x-slot>` immediately followed by text breaks compilation.** Put a newline
  or an element between the closing slot tag and the text after it.
- **`x-page-header` carries `mb-6`.** Inside a `flex flex-col gap-*` column,
  pass `class="mb-0!"` so the gap alone spaces it.
- **Enums carry their badge colours.** `DecryptState::tone()` / `dashed()` /
  `hint()` and `ConversionStatus::tone()` return the `x-badge` tone; pass them
  through rather than writing a status → colour `match` in Blade:
  `<x-badge :tone="$conversion->status->tone()">`.

## Feedback & status

### `x-badge`

A tag: a status, a format, a flag. Use for anything that labels; not for a
toggle (`x-chip`) or a score (`x-rating-badge`).

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `tone` | string | `neutral` | `neutral`, `accent`, `success`, `warn`, `danger`, `muted` |
| `size` | string | `xs` | `xs` the uppercase 10px micro tag; `sm` the taller normal-case `text-xs` one (file-card footers) |
| `dashed` | bool | `false` | Dashed border, for the unknown/unchecked state |
| `fill` | bool | `false` | `/10` tint of the tone behind it (`bg-sunken` for neutral and muted) |
| `icon` | ?string | `null` | Flux icon name, micro `size-3`, before the label |
| `tip` | ?string | `null` | Wraps it in a `flux:tooltip` and adds `cursor-help` |

Slot: the label.

```blade
{{-- resources/views/livewire/conversion/queue.blade.php --}}
<x-badge :tone="$conversion->status->tone()" :fill="$conversion->status === ConversionStatus::Done">
```

### `x-chip`

A toggle pill in a filter row. Use for filters that narrow a list; not for a
one-of-several choice (`x-pill-choice`).

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `active` | bool | `false` | Pressed; sets `aria-pressed` |
| `xActive` | ?string | `null` | Alpine expression for a chip whose state lives in the browser (`x-active="…"`) |
| `count` | ?int | `null` | Mono count after the label |
| `mono` | bool | `false` | Mono label |
| `dashed` | bool | `false` | The row's "add" / "show more" chip: an action, never pressed |
| `icon` | ?string | `null` | Micro icon before the label |

Slot: the label.

```blade
{{-- resources/views/livewire/tools/decrypt.blade.php --}}
<x-chip
    :active="$show === $value"
    :count="$count"
    wire:key="chip-{{ $value ?: 'all' }}"
    wire:click="filter({{ Js::from($value) }})"
>{{ $label }}</x-chip>
```

### `x-notice`

A banner over a list or inside a modal: something to know, something wrong,
something running. Not for a field error (`flux:error`).

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `tone` | string | `neutral` | `neutral`, `accent`, `warn`, `danger` |
| `icon` | ?string | `null` | Leading micro icon |
| `busy` | bool | `false` | Leading spinning `arrow-path` (replaces `icon`) |
| `count` | ?string | `null` | Mono figure at the far end, e.g. `3 / 10`; a line of its own below `sm` |
| `size` | string | `md` | `sm` for a narrow column such as the sidebar |

Slots: default = message; `note` = small print under it (`mt-1 text-xs opacity-80`).

```blade
{{-- resources/views/livewire/games/show.blade.php --}}
<x-notice tone="accent" busy :count="$sending->files_done.' / '.$sending->files_total">
    {{ __('Sending to :name…', ['name' => $sending->destination->name]) }}
    <x-slot:note>{{ __('Nothing has picked this up yet. …') }}</x-slot:note>
</x-notice>
```

### `x-auth-session-status`

A one-off session message (password changed…) as an accent `x-notice`; renders
nothing when empty. Use only on the auth screens.

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `status` | ?string | — | The message, usually `session('status')` |

```blade
{{-- resources/views/auth/login.blade.php --}}
<x-auth-session-status class="text-center" :status="session('status')" />
```

### `x-empty-state`

What stands in for a list with nothing in it. `block` for a page or tab,
`inline` for a line inside something already framed (a panel, a modal).

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `title` | ?string | `null` | The main line |
| `hint` | ?string | `null` | Fainter line under it (block only) |
| `variant` | string | `block` | `block` (dashed box) or `inline` (bare line) |
| `icon` | ?string | `null` | Icon above the title (block only) |

Slot: block = actions under the text (`mt-4`); inline = replaces the title.

```blade
{{-- resources/views/livewire/docs/index.blade.php --}}
<x-empty-state icon="book-open" class="min-w-0 flex-1" :title="__('Nothing matches that.')" :hint="__('Try a different search or filter.')" />

{{-- resources/views/livewire/conversion/picker.blade.php --}}
<x-empty-state variant="inline" :title="__('Pick a file to see what it can become, or several to convert them all at once.')" />
```

### `x-status`

A dot and a word for something up or down. Use for service health; not for a
job's state (`x-badge` with the enum's tone).

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `state` | string | `idle` | `pending` (pulsing), `ok` (accent glow), `error`, `idle` |

Slot: the label (optional).

```blade
{{-- resources/views/partials/network-shares.blade.php --}}
<x-status :state="match ($online) { null => 'pending', true => 'ok', false => 'error' }">
    {{ match ($online) { null => __('Checking…'), true => __('Online'), false => __('Offline') } }}
</x-status>
```

### `x-progress-bar`

A thin track and its fill. Use for any "how far along"; not hand-written
`<div style="width:…">`.

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `percent` | ?float | `null` | 0–100, clamped; sets `role="progressbar"` and `aria-valuenow` |
| `xPercent` | ?string | `null` | Alpine expression for the width when the browser owns the number (`x-percent="…"`) |
| `tone` | string | `accent` | `accent`, `warn`, `danger` |
| `size` | string | `sm` | `xs` (3px), `sm` (`h-1`), `md` (`h-1.5`) |
| `label` | ?string | `null` | `aria-label` — what the bar measures. Pass one wherever `percent` or `xPercent` is set: a progressbar needs a name |

```blade
{{-- resources/views/livewire/games/upload-modal.blade.php --}}
<x-progress-bar x-percent="percent(entry)" :label="__('Upload progress')" x-show="entry.status === 'uploading'" />
```

### `x-quota-bar`

An allowance as an `x-progress-bar` that turns warn from 75% and danger from 90%.
A `max` of 0 means "no limit reported": an empty track, with no progressbar role and no value.

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `used` | int | — | Spent |
| `max` | int | — | Allowance; 0 = unknown |
| `label` | ?string | `null` | Passed on as the bar's `label` |

```blade
{{-- resources/views/livewire/library-storage.blade.php --}}
<x-quota-bar :used="$storage?->used ?? 0" :max="$storage?->total() ?? 0" :label="__('Library storage used')" class="mt-2 mb-3.5" />
```

### `x-fraction`

"So many of so many": `12 / 40`, the whole quieter. The file has no trailing
newline, so text may follow it directly.

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `value` | mixed | — | The count |
| `of` | mixed | — | The whole |
| `unit` | ?string | `null` | Appended after `of` |

```blade
{{-- resources/views/livewire/system-activity.blade.php --}}
<x-fraction :value="$exportsDone" :of="$exportsTotal" />
```

### `x-rating-badge`

The retroBITE score, filled in its `App\Support\RatingBand` colour (inline
style, since the colour is chosen at runtime). Renders nothing for a null rating.

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `rating` | ?int | `null` | 0–100 |
| `size` | string | `sm` | `sm` in a card caption, `md` beside the score's name |

```blade
{{-- resources/views/livewire/games/show.blade.php --}}
<x-rating-badge :rating="$game->rating" size="md" />
```

### `x-info`

An explanation a hover away: an ⓘ button with a tooltip, focusable for the
keyboard. Use instead of a paragraph under a field. Attributes go to the button.

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `text` | string\|array | — | One line, or a list for several |

```blade
{{-- resources/views/livewire/games/show.blade.php --}}
<x-info class="-translate-y-px" :text="[__('Out of 100: …'), ...$this->scoreSources]" />
```

### `x-kbd`

A key, or several, as `<kbd>`s (`font-sans text-xs text-fg-dim`).

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `keys` | string\|array | — | One key, or a list rendered side by side |

```blade
{{-- resources/views/livewire/global-search.blade.php --}}
<span><x-kbd keys="↑↓" /> {{ __('move') }}</span>
```

### `x-chevron`

The disclosure arrow, turned over while its section is open. Attributes go to
the icon.

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `open` | string | `open` | Alpine expression, read from the surrounding scope |

```blade
{{-- resources/views/partials/network-shares.blade.php --}}
<x-chevron open="isOpen('{{ $key }}')" />
```

### `x-live-system`

Runs a Livewire method when the server signals that something moved, instead
of polling. Rendering it starts the listener, removing it stops it.

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `signal` | string\|array | — | The `live.system()` `what`, or several |
| `call` | string | — | Livewire method, `$refresh` included |
| `timeout` | ?int | `null` | Milliseconds: also run once after this, for work that hangs silently |
| `as` | string | `div` | Element to render (`section` for a root that must be one) |

Slot: content, so it can be the component's root.

```blade
{{-- resources/views/livewire/conversion/queue.blade.php --}}
<x-live-system signal="conversion" call="refreshQueue">
    <x-panel as="section" :title="__('Queue')">…</x-panel>
</x-live-system>
```

### `x-live-wait`

A "waiting for…" wrapper for one game: runs the component's check method on a
matching `GameUpdated` signal, on `failed`/`reconnect`, and once at the
deadline. Render it only while the wait is on.

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `game` | int | — | Game id to listen on |
| `on` | string\|array | — | Signal `what`s that end the wait |
| `check` | string | — | Livewire method that decides |
| `since` | ?int | `null` | Unix time the wait began (default now) |
| `timeout` | int | — | Seconds after `since` to check regardless |

Slot: the banner, usually an `x-notice busy`.

```blade
{{-- resources/views/livewire/games/show.blade.php --}}
<x-live-wait :game="$game->id" on="identified" check="checkAnswer" :since="$awaitingSince" :timeout="$this::WAIT_SECONDS">
```

## Layout & structure

### `x-panel`

A bordered, rounded box holding a list, a table or a block of content. With a
title, a `header` slot or `actions`, it gains the header strip and the padding
and rules move to the body.

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `tone` | string | `surface` | `surface` or `sunken` |
| `padded` | bool | `false` | `p-5` on the body |
| `divided` | bool | `false` | `divide-y divide-raised` on the body |
| `as` | string | `div` | `div`, `section`, `ul` (with a header, the `ul` moves to the body) |
| `title` | ?string | `null` | Kicker title in the strip |
| `align` | string | `center` | Strip alignment; `end` for a two-line header |

Slots: default = body; `header` = beside the title in the strip (its attributes,
e.g. `class="gap-1.5!"`, go to the strip); `actions` = far right of the strip.

```blade
{{-- resources/views/livewire/conversion/queue.blade.php --}}
<x-panel as="section" :title="__('Queue')">
    <x-slot:actions>
        <flux:button size="xs" variant="ghost" icon="archive-box-x-mark" wire:click="clearFinished">{{ __('Clear finished') }}</flux:button>
    </x-slot:actions>
    …
</x-panel>
```

### `x-panel.header`

The strip on its own, for a second section part-way down a panel.

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `title` | ?string | `null` | Kicker title |
| `align` | string | `center` | `center` or `end` |

Slots: default = content beside the title; `actions` = far right.

```blade
{{-- resources/views/livewire/tools/decrypt.blade.php --}}
<x-panel.header class="px-3.5! py-3!">
    <x-pick-all … />
    <x-search-field wire:model.live.debounce.300ms="search" class="min-w-0 flex-1" />
</x-panel.header>
```

### `x-page-header`

The top of a page: kicker, display-size `h1`, a meta or description line, and
the page's actions on the right. Carries `mb-6` (see Gotchas).

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `kicker` | ?string | `null` | Mono line above the title |
| `title` | string | — | The `h1` |
| `description` | ?string | `null` | Sentence under the title |

Slots: `meta` = mono line under the title; `actions` = right side.

```blade
{{-- resources/views/livewire/tools/decrypt.blade.php --}}
<x-page-header :kicker="__('Tools')" :title="__('Decrypt')" :description="__('Some images are encrypted …')" class="mb-0!">
    <x-slot:actions>
        <livewire:conversion.tool-paths :only="Tools::onPage(Converter::PAGE_DECRYPT)" />
    </x-slot:actions>
</x-page-header>
```

### `x-section-header`

An `h2` over one section of a page, with a figure or link beside it.

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `title` | string | — | The heading |

Slot: `aside` = right side, wraps under on a narrow screen.

```blade
{{-- resources/views/partials/network-shares.blade.php --}}
<x-section-header :title="__('Network shares')">
    <x-slot:aside>…</x-slot:aside>
</x-section-header>
```

### `x-band`

One band of a full-bleed page (the game page under its hero): the page's
gutters, above the art behind it. Renders a `<section>`.

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `pad` | string | `sm` | Top padding: `sm` (`pt-4.5`) or `lg` (`pt-6.5`) |

```blade
{{-- resources/views/livewire/games/show.blade.php --}}
<x-band pad="lg"><x-tabs>…</x-tabs></x-band>
```

### `x-stat`

One figure under its kicker, as a `dt`/`dd` pair for the caller's `<dl>`.

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `label` | string | — | Kicker |
| `size` | string | `md` | `md` mono count; `lg` the sans figure of a stat grid |
| `tone` | string | `default` | `default` or `warn` |
| `mono` | ?bool | `null` | Defaults to true for `md`, false for `lg` |
| `percent` | ?float | `null` | Adds an `xs` `x-progress-bar` under the value |
| `sub` | ?string | `null` | Detail line under the value (also a slot) |
| `truncate` | bool | `false` | One line; pair with `title` for the full text on hover |
| `title` | ?string | `null` | Hover text on the value |

Slot: the value.

```blade
{{-- resources/views/dashboard.blade.php --}}
<x-stat :label="$label" :sub="$sub" size="lg" class="bg-sunken px-4.5 py-4">{{ $value }}</x-stat>
```

### `x-kv-row`

One ruled line of a key/value list in a `<dl class="flex flex-col gap-2.5">`;
value mono and right-aligned.

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `label` | string | — | The key |
| `mono` | bool | `false` | Mono key |

Slot: the value.

```blade
{{-- resources/views/livewire/settings/about.blade.php --}}
<x-kv-row :label="__($key)">{{ $value }}</x-kv-row>
```

### `x-modal`

A `flux:modal` laid out the one way: heading, body (`flex flex-col gap-5`),
footer with Cancel first and the primary action last. Use for every dialog.
`x-data`/`x-init` go to the body (so the footer is in scope); everything else
(`wire:close`, `:dismissible`) to `flux:modal`.

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `name` | string | — | The Flux modal name |
| `width` | string | `lg` | `md`, `lg`, `xl`, `2xl`, `4xl` |
| `heading` | ?string | `null` | Title (also a slot) |
| `subheading` | ?string | `null` | Line under it (also a slot) |
| `mono` | bool | `false` | Mono, truncated subheading (a path) |
| `submit` | ?string | `null` | The body becomes `<form wire:submit="…">` |
| `cancel` | string\|false | `__('Cancel')` | Ghost close button label; `false` for none |
| `event` | ?string | `null` | Window event that opens it |
| `then` | ?string | `null` | JS run after opening, e.g. `$wire.open($event.detail.id)` |

Slots: default = body; `heading`, `subheading`; `footer` = after Cancel;
`footerStart` = left side of the footer.

```blade
{{-- resources/views/livewire/docs/reference-modal.blade.php --}}
<x-modal :name="$this::MODAL" submit="append" :heading="__('Add reference')" :subheading="…" mono>
    <flux:input wire:model="title" :label="__('Title')" />
    <x-slot:footerStart><span class="kicker text-fg-faint">…</span></x-slot:footerStart>
    <x-slot:footer>
        <flux:button type="submit" variant="primary">{{ __('Append') }}</flux:button>
    </x-slot:footer>
</x-modal>
```

### `x-modal.content`

The inside of `x-modal` without the `flux:modal`, for one step of a modal that
swaps its content. Same props and slots as `x-modal` minus `name`, `width`,
`event`, `then`.

```blade
{{-- resources/views/livewire/consoles/index.blade.php --}}
<flux:modal :name="$this::MODAL" wire:close="closeAdd" class="w-full max-w-lg">
    <x-modal.content :heading="__('Add a console')" :subheading="__('Only the ones you add appear in your library.')" :cancel="false">
```

### `x-search-modal`

An `x-modal` that is a search box and the rows it finds. Rows are
`x-search-row`s that do their own work.

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `name` | string | — | Modal name |
| `heading` | string | — | Title |
| `subheading` | ?string | `null` | Line under it |
| `model` | string | `search` | `wire:model.live` target of the input |
| `placeholder` | ?string | `__('Search')` | Input placeholder |
| `hasResults` | bool | `true` | False shows `empty` instead of the slot |
| `empty` | ?string | `null` | Inline empty-state text |
| `width` | string | `md` | As `x-modal` |

Slot: result rows.

```blade
{{-- resources/views/livewire/docs/link-doc-modal.blade.php --}}
<x-search-modal :name="$this::MODAL" :heading="__('Link a doc')" :placeholder="__('Search docs')"
    :has-results="$this->matches->isNotEmpty()" :empty="__('No doc matches that.')">
    @foreach ($this->matches as $doc)
        <x-search-row :label="$doc->title" :detail="$doc->subtitle()" icon="document-text" wire:click="link({{ Js::from($doc->path) }})" />
    @endforeach
</x-search-modal>
```

### `x-preview-list`

What an action is about to do: a summary line, the first rows, "and N more.",
and notes on what is left alone.

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `total` | int | — | How many in all |
| `limit` | int | `10` | How many the caller sliced to |
| `summary` | ?string | `null` | Line above the list |

Slots: default = `<li>` rows (already sliced); `notes` = `<li>` notes under it.

```blade
{{-- resources/views/livewire/games/rename-modal.blade.php --}}
<x-preview-list :total="$count" :summary="trans_choice(':count file will be renamed.|…', $count, ['count' => $count])">
    @foreach (array_slice($renames, 0, 10) as ['from' => $from, 'to' => $to]) <li>…</li> @endforeach
    <x-slot:notes><li>…</li></x-slot:notes>
</x-preview-list>
```

### `x-field`

A `flux:field` with its label, an `x-info` beside it, a description and the
error, so a form states each once. Use for one form control.

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `label` | string | — | `flux:label` |
| `info` | ?string\|array | `null` | `x-info` beside the label |
| `name` | ?string | `null` | Renders `flux:error` for it |
| `description` | ?string | `null` | `flux:description` |

Slot: the control.

```blade
{{-- resources/views/livewire/settings/destinations.blade.php --}}
<x-field :label="__('Username')" :info="__('Leave empty for a guest share.')" name="username">
    <flux:input wire:model="username" :placeholder="__('Optional')" autocomplete="off" />
</x-field>
```

### `x-field-group`

A kicker over a group that is not one form field: chips, a picker, a preview.
Use instead of a hand-written `<p class="kicker …">`.

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `label` | string | — | The kicker |
| `hint` | ?string | `null` | Small line between kicker and content |

```blade
{{-- resources/views/livewire/conversion/picker.blade.php --}}
<x-field-group :label="__('Output format')">…</x-field-group>
```

### `x-path-preview`

Where something will be written, shown before it is: one mono line in a sunken
box, full text on hover, `—` while empty. Built on `x-field-group`.

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `label` | ?string | `__('Writes to')` | Kicker |
| `value` | ?string | `null` | The path |
| `icon` | ?string | `null` | Micro icon before it |

```blade
{{-- resources/views/livewire/conversion/picker.blade.php --}}
<x-path-preview :value="$this->writesTo" />
```

### `x-backdrop`

Key art behind a hero, a card or the page, with the fade and the scanlines.
Always `aria-hidden`; fills the nearest positioned box.

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `src` | ?string | `null` | Image URL |
| `fade` | ?string | `null` | `hero-y`, `hero-x` (darkening overlays), `art-l`, `art-b` (masks) |
| `position` | string | `50% 28%` | CSS `background-position` |
| `fallback` | bool | `false` | Raised→sunken gradient when there is no `src` |
| `fixed` | bool | `false` | Fill the viewport instead |

Slot: further layers over the art, under the scanlines.

```blade
{{-- resources/views/livewire/games/show.blade.php --}}
<x-backdrop :src="$this->backdrop ? route('media.show', ['path' => $this->backdrop]) : null" fade="hero-y" />
```

### `x-cover-frame`

A game's cover in a frame of fixed shape, or the console's icon while it has
none. Use for every cover or thumbnail.

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `src` | ?string | `null` | Image URL |
| `console` | ?Console | `null` | Fallback icon, and the shape for `lg`/`hero` |
| `size` | string | `md` | `sm`, `md` (list thumbs), `poster`, `lg` (shelf column), `hero` |
| `label` | ?string | `null` | Kicker under the fallback icon ("Unidentified") |
| `alt` | string | `''` | Image alt |
| `lightbox` | ?string | `null` | `data-lightbox` key for `x-lightbox` |
| `icon` | ?string | `null` | Fallback Flux icon when there is no console |
| `as` | string | `div` | `span` inside a button |

Slot: `overlay` = over the art (pick box, logo, actions).

```blade
{{-- resources/views/livewire/games/show.blade.php --}}
<x-cover-frame :src="$this->cover ? $this->coverUrl : null" :lightbox="$this->cover" :console="$console" size="hero" :alt="$game->title" />
```

### `x-region-flag`

A region as its flag, or its code in mono where none depicts it, named on
hover. Nothing without either.

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `icon` | ?string | `null` | Flag image URL |
| `label` | ?string | `null` | Alt and hover title |
| `code` | ?string | `null` | Shown when there is no icon |
| `size` | string | `sm` | `sm` (`h-4`) or `md` (`h-4.5`) |

```blade
{{-- resources/views/livewire/games/show.blade.php --}}
<x-region-flag :icon="$groupIcon" size="md" />
```

### `x-tabs` / `x-tabs.item`

The app's tab strip, scrolling sideways when it overflows (`tabStrip` in
`resources/js/tab-strip.js`). `x-tabs` takes no props.

`x-tabs.item`:

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `active` | bool | `false` | Current tab (`aria-current`) |
| `href` | ?string | `null` | A link (with `wire:navigate`); otherwise a button |
| `icon` | ?string | `null` | Flux icon before the label |
| `image` | ?string | `null` | Image URL in the icon's place |
| `count` | ?int | `null` | Mono count beside the label |

```blade
{{-- resources/views/livewire/games/show.blade.php --}}
<x-tabs>
    <x-tabs.item wire:click="selectTab('{{ $tabKey }}')" :active="$this->activeTab === $tabKey" :icon="$tabIcon" :count="$tabCount">{{ $tabLabel }}</x-tabs.item>
</x-tabs>
```

### `x-step-ring`

The small numbered ring of a step, shared by the onboarding rail and
`x-settings.step`.

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `number` | ?int | `null` | The step number |
| `state` | string | `current` | `current` (amber), `done` (tick), `todo` |

```blade
{{-- resources/views/onboarding.blade.php --}}
<x-step-ring :number="$index + 1" :state="$stateOf($index)" />
```

### `x-icon-note`

One `<li>` aside with a micro icon. The caller's `<ul class="flex flex-col gap-3 text-sm text-fg-soft">` sets gap and colour.

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `icon` | string | — | Flux icon name |

```blade
{{-- resources/views/livewire/settings/destinations.blade.php --}}
<x-icon-note icon="document-duplicate">{{ __('Send to copies. Your library keeps every file.') }}</x-icon-note>
```

### `x-auth-header`

Centred title and subheading on the auth screens.

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `title` | string | — | `flux:heading size="xl"` |
| `description` | string | — | `flux:subheading` |

```blade
{{-- resources/views/auth/confirm-password.blade.php --}}
<x-auth-header class="mt-4" :title="__('Confirm password')" :description="__('This is a secure area …')" />
```

### `x-logo`

The logo, inlined from `resources/svg/` so its colours follow the scheme.
Attributes go onto the `<svg>`.

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `variant` | string | `mark` | `mark`, `logo-stacked`, `logo-wide` |
| `label` | ?string | `null` | Accessible name; null = decorative |

```blade
{{-- resources/views/layouts/app/sidebar.blade.php --}}
<x-logo variant="logo-wide" :label="config('app.name')" class="block h-auto w-[132px]" />
```

### `x-lightbox`

A full-screen viewer over images already on the page. Leave `images` null to
use the DOM nodes matching `selector`, or pass the set and mark triggers with
`data-lightbox="<key>"`. Do not nest two.

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `images` | ?array | `null` | `[{key, src, caption}, …]` |
| `selector` | string | `img` | Which nodes open the viewer |

Slot: the content it wraps.

```blade
{{-- resources/views/livewire/docs/partials/preview.blade.php --}}
<x-lightbox selector="[data-doc-body] img">
```

## Controls

### `x-pill-choice`

A short one-of-several choice as a segmented row. Use for every two-to-five-way
switch; not for filters (`x-chip`) or a stacked choice with descriptions
(`x-option-card`).

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `options` | array | — | value → label |
| `label` | string | — | `aria-label` of the group |
| `model` | ?string | `null` | Livewire property set with `$set` |
| `value` | mixed | `null` | Current value (Livewire modes) |
| `method` | ?string | `null` | Call `method('value')` instead of `$set` |
| `alpine` | ?string | `null` | Alpine variable to set; Livewire is not involved |
| `size` | string | `xs` | `xs` or `sm` |
| `full` | bool | `false` | Equal-width grid across the row |
| `icons` | array | `[]` | value → Flux icon; those options are icon-only, labelled |
| `mono` | bool | `false` | Mono labels |
| `disabled` | bool\|string | `false` | True, or an Alpine expression |

```blade
{{-- resources/views/livewire/games/index.blade.php --}}
<x-pill-choice method="setView" :value="$this->viewMode" :label="__('View')"
    :options="['cards' => __('Show covers'), 'table' => __('Show a list')]"
    :icons="['cards' => 'squares-2x2', 'table' => 'list-bullet']" />
```

### `x-option-card`

One option of a stacked one-of-several choice: title, description, amber when
picked.

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `label` | string | — | Title |
| `active` | bool | `false` | Picked |
| `disabled` | bool | `false` | Not available |
| `description` | ?string | `null` | Line under the title |
| `radio` | bool | `false` | Leading radio dot, `role="radio"` |
| `mono` | bool | `false` | Mono description (a tool, a format) |

Slots: `badge` = beside the title; `meta` = extra line (colour it on the slot);
`trailing` = right side.

```blade
{{-- resources/views/livewire/conversion/picker.blade.php --}}
<x-option-card radio mono :active="$on" :disabled="! $available" :label="'.'.$converter->to()"
    :description="$converter->description()" wire:click="selectFormat({{ Js::from($converter->key()) }})">
    <x-slot:meta class="text-warn">{{ __('Fits :fits of :count picked; …') }}</x-slot:meta>
</x-option-card>
```

### `x-file-drop`

A dashed zone to click or drop files on, with a visually hidden file input.
Binding attributes (`wire:model*`, `x-on:change`, `x-ref`, `disabled`,
`x-bind:disabled`) go to the input; the rest, drag handlers included, to the zone.

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `label` | string | — | Main line |
| `icon` | string | `arrow-up-tray` | Flux icon |
| `hint` | ?string | `null` | Mono line (accepted extensions) |
| `accept` | ?string | `null` | Input `accept` |
| `multiple` | bool | `false` | Input `multiple` |

Slot: extras under the hint.

```blade
{{-- resources/views/livewire/games/upload-modal.blade.php --}}
<x-file-drop :label="__('Choose or drop game files')" :hint="implode(' · ', $this->extensions)" :accept="$this->accept" multiple
    x-bind:disabled="running" x-on:change="pick($event)" x-on:dragover.prevent="" x-on:drop.prevent="drop($event)" />
```

### `x-glass-button`

The smoked-glass button that sits on key art. Just a button, so it works as a
`flux:dropdown` trigger.

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `icon` | ?string | `null` | Flux icon (`ellipsis-horizontal` when `iconOnly` and none given) |
| `label` | ?string | `null` | Text when the slot is empty; `aria-label` when `iconOnly` |
| `chevron` | bool | `false` | Trailing chevron, for a menu |
| `compact` | bool | `false` | Below `sm`: label to screen readers only, chevron hidden |
| `iconOnly` | bool | `false` | Icon alone in a square |

Slot: the label.

```blade
{{-- resources/views/livewire/games/show.blade.php --}}
<flux:dropdown position="bottom" align="end" class="ml-auto">
    <x-glass-button icon="ellipsis-horizontal" chevron>{{ __('Actions') }}</x-glass-button>
```

### `x-icon-button`

A bare icon button: no border, a hover ground, its label as `aria-label` and
`title`. An `<a>` when given `href`.

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `icon` | string | — | Flux icon |
| `label` | string | — | Accessible name and hover title |
| `tone` | string | `default` | `danger` for remove/forget |
| `size` | string | `md` | `md` (`size-7`) or `sm` (`size-5`, micro icon) |

```blade
{{-- resources/views/livewire/games/upload-modal.blade.php --}}
<x-icon-button icon="x-mark" :label="__('Remove')" size="sm" x-on:click="remove(entry)" />
```

### `x-cta-button`

The one large amber button a sign-in or setup form ends on. Not for anything
else: in-app primary actions are `flux:button variant="primary"`.

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `icon` | ?string | `null` | Micro icon before the label |
| `iconTrailing` | ?string | `null` | Micro icon after it |
| `type` | string | `submit` | Button type |

```blade
{{-- resources/views/auth/login.blade.php --}}
<x-cta-button icon="arrow-right-end-on-rectangle" data-test="login-button">
```

### `x-copy-button`

Copies a value and flashes "Copied". Falls back to `execCommand` on plain
HTTP, where `navigator.clipboard` is unavailable.

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `text` | string | — | What to copy |
| `label` | ?string | `__('Copy')` | Visible label, or `aria-label` with `icon` |
| `variant` | string | `plain` | `plain` button, or `menu` (a `flux:menu.item`) |
| `icon` | bool | `false` | The copy icon alone |

Slot: content before the label (`plain`, `menu`).

```blade
{{-- resources/views/livewire/games/show.blade.php --}}
<x-copy-button variant="menu" :text="$this->libraryPath" :label="__('Copy path')" />
{{-- resources/views/partials/network-shares.blade.php --}}
<x-copy-button :text="$hostIp" icon />
```

### `x-text-link`

An amber word to click inside a line of text. A button, or an `<a>` with
`href`.

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `chevron` | ?string | `null` | Alpine "open" expression: adds `x-chevron` and `aria-expanded` |

```blade
{{-- resources/views/livewire/games/show.blade.php --}}
<x-text-link chevron="open" x-on:click="open = ! open" class="ml-1">
```

### `x-check`

A drawn checkbox (`aria-hidden`), for inside the button that toggles a row: a
nested input would be a second control. Takes no attributes.

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `state` | string | `off` | `on`, `off`, `some` |

```blade
{{-- resources/views/livewire/conversion/picker.blade.php --}}
<x-check :state="$picked ? 'on' : 'off'" />
```

### `x-pick-all`

The select-all box at the head of a pickable list: an `x-check` in a labelled
button.

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `label` | string | — | What a click does |
| `state` | string | `off` | As `x-check` |

```blade
{{-- resources/views/livewire/tools/decrypt.blade.php --}}
<x-pick-all :label="__('Pick every image that is ready')" :state="…" wire:click="toggleAll" :disabled="$this->ready === []" />
```

### `x-search-field`

The glass search input that sits on key art; `/` focuses it, a clear button
appears once typed in. `class` goes to the wrapper, everything else to the input.

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `placeholder` | string | `__('Search titles')` | Also the `aria-label` |

```blade
{{-- resources/views/livewire/games/index.blade.php --}}
<x-search-field wire:model.live.debounce.300ms="query" class="w-64 max-w-full" />
```

### `x-search-row`

One result in a search list: thumbnail or icon, title, a detail on the right.
A link with `href`, a button otherwise.

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `label` | string | — | Title |
| `detail` | ?string | `null` | Right-hand detail |
| `image` | ?string | `null` | Thumbnail URL |
| `icon` | ?string | `null` | Icon when there is no image |
| `href` | ?string | `null` | Makes it a link |

Example: see `x-search-modal`.

### `x-link-card`

An outbound link as a bordered row: icon, label over the address, arrow. Opens
in a new tab.

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `icon` | string | — | Flux icon |
| `label` | string | — | Label |
| `url` | string | — | Absolute URL, shown without its scheme |

```blade
{{-- resources/views/livewire/settings/about.blade.php --}}
<x-link-card :icon="$icon" :label="__($label)" :url="$url" />
```

### `x-breadcrumbs`

Where a page sits in the library, as a glass chip over its hero. Each step but
the last links back up.

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `items` | array | — | List of `[label, href]`; the last href is ignored |

```blade
{{-- resources/views/livewire/games/show.blade.php --}}
<x-breadcrumbs :items="[[__('Games'), route('games.index')], [$game->title, null]]" />
```

### `x-region-order`

A sortable region order, bound through `x-modelable`: `wire:model` or
`x-model` on the component.

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `labels` | array | `TransferRegions::besides([])` | code → name of every region that can be added |
| `keepOne` | bool | `false` | Never remove the last region |

```blade
{{-- resources/views/livewire/settings/destinations.blade.php --}}
<x-region-order wire:model.live="regions" keep-one />
```

## Domain

### `x-settings.layout`

The pane every settings screen lays itself out in: the tab strip, the heading
row with the screen's action, and a `max-w-5xl` body.

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `heading` | string | `''` | Screen title |
| `subheading` | string | `''` | Line under it |
| `actions` | ?slot | `null` | Right of the heading (usually `x-settings.save`) |
| `wide` | bool | `false` | Drop the width cap |
| `showTabs` | bool | `true` | Off when embedded in onboarding |

```blade
{{-- resources/views/livewire/settings/interface.blade.php --}}
<x-settings.layout :show-tabs="! $onboarding" :heading="__('UI')" :subheading="__('How retroBITE looks, independent of what it fetches')">
```

### `x-settings.card`

One card on a settings screen. Icon + title for the screen's subject, a kicker
for a supporting card, neither for a bare shell.

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `kicker` | ?string | `null` | Kicker header (ignored when `title`/`icon` is set) |
| `title` | ?string | `null` | Title in the icon header |
| `icon` | ?string | `null` | Accent icon before the title |
| `description` | ?string | `null` | Paragraph under the header |

Slots: default = body; `badge` = beside the title (icon header); `actions` =
right of either header.

```blade
{{-- resources/views/livewire/settings/screenscraper.blade.php --}}
<x-settings.card icon="magnifying-glass" :title="__('Account')" :description="__('retroBITE identifies games against ScreenScraper …')">
    <x-slot name="badge">
        <x-settings.linked-badge :linked="$this->linked" />
    </x-slot>
    …
</x-settings.card>
```

### `x-settings.choice-row`

A setting named on the left, its control on the right; the control drops
under only on a phone. Put it inside an `x-settings.card`.

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `title` | string | — | Setting name |
| `description` | ?string | `null` | Line under it |

Slot: the control.

```blade
{{-- resources/views/livewire/settings/interface.blade.php --}}
<x-settings.choice-row :title="__('Color scheme')" :description="__('Recolors the whole interface and the logo. …')">
```

### `x-settings.step`

One numbered step of a settings form: `x-step-ring`, title, hint, the fields
indented under it.

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `number` | int | — | Step number |
| `title` | string | — | Title |
| `hint` | ?string | `null` | What the step is for |

```blade
{{-- resources/views/livewire/settings/destinations.blade.php --}}
<x-settings.step number="1" :title="__('Find the machine')" :hint="__('Search the network for it, or type its name or address.')">
```

### `x-settings.secret-input`

A password or key the server keeps and never sends back: empty once stored,
the placeholder says so. Attributes go to the input.

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `model` | string | — | `wire:model` target |
| `label` | string | — | Input label |
| `stored` | bool | `false` | One is on record |
| `placeholder` | ?string | `null` | Placeholder while none is stored |
| `forget` | ?string | `null` | Livewire method behind the danger "Remove" button |
| `forgetLabel` | ?string | `__('Remove')` | Its label |

```blade
{{-- resources/views/livewire/settings/screenscraper.blade.php --}}
<x-settings.secret-input model="password" :label="__('Password')" :stored="$this->hasPassword"
    :placeholder="__('Your ScreenScraper password')" forget="forgetPassword" :forget-label="__('Remove stored password')" />
```

### `x-settings.linked-badge`

"Linked" (accent, filled) or "Not linked" (muted) beside an account card's title.

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `linked` | bool | `false` | Credentials are on record |

Example: see `x-settings.card`.

### `x-settings.save`

A settings screen's Save, in the heading row outside its form and bound to it
by id. Says "Continue" during onboarding.

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `form` | string | — | The form's id |
| `onboarding` | bool | `false` | Label "Continue" |
| `label` | ?string | `null` | Overrides both |

```blade
{{-- resources/views/livewire/settings/interface.blade.php --}}
<x-settings.save form="interface-settings" :onboarding="$onboarding" />
```

### `x-settings.action`

A button with a line under it on what pressing it does, for actions a label
alone cannot explain.

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `hint` | string | — | The explanation |

Slot: the `flux:button`.

```blade
{{-- resources/views/livewire/settings/retroachievements.blade.php --}}
<x-settings.action class="mt-4" :hint="__('Queues the nightly download for every console listed. …')">
    <flux:button size="sm" variant="filled" type="button" wire:click="syncIndex">{{ __('Refresh indexes now') }}</flux:button>
</x-settings.action>
```

### `x-sidebar.meter`

One reading in the sidebar footer: label and figure on one line, a summary
always under it, the rest a fold away. `open` is the caller's Alpine state,
passed as `x-data`.

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `label` | string | — | Kicker |
| `collapsible` | bool | `true` | Heading is a toggle with a chevron |
| `disabled` | bool | `false` | Nothing to unfold yet |
| `toggleLabel` | ?string | `null` | `aria-label` of the toggle |

Slots: `figure` = right of the label; `summary` = always shown; default = the fold.

```blade
{{-- resources/views/livewire/api-status.blade.php --}}
<x-sidebar.meter :label="__('Scraper')" :toggle-label="__('Show the failed-lookup allowance')" :disabled="$quota === null"
    x-data="{ open: $persist(false).as('sidebar.scraper') }" class="mb-3 border-b border-line pb-3">
    <x-slot:figure><x-fraction :value="…" :of="…" /></x-slot:figure>
    <x-slot:summary><x-quota-bar :used="…" :max="…" class="mt-2" /></x-slot:summary>
    <x-sidebar.meter-row :label="__('Failed')">…</x-sidebar.meter-row>
</x-sidebar.meter>
```

### `x-sidebar.meter-row`

One line inside a meter's fold: label, figure, bar.

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `label` | string | — | Label |
| `percent` | ?float | `null` | Plain `x-progress-bar` under the row |
| `quiet` | bool | `false` | Dimmer figure for an idle row |

Slots: default = the figure; `bar` = replaces the plain bar (e.g. `x-quota-bar`).

Example: see `x-sidebar.meter`.

### `x-sidebar.sub-item`

A row nested under a sidebar item: the consoles under Consoles, the pages
under Tools. Pass `wire:navigate`.

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `href` | string | — | Link |
| `current` | bool | `false` | Current page |
| `icon` | ?string | `null` | Flux icon |
| `image` | ?string | `null` | Image in the icon's place |
| `count` | ?int | `null` | Mono count on the right |

```blade
{{-- resources/views/layouts/app/sidebar.blade.php --}}
<x-sidebar.sub-item :href="route($route)" :current="$isCurrent" :icon="$icon" wire:navigate>{{ __($label) }}</x-sidebar.sub-item>
```

### `x-game-file.name`

A file card's title: the name, the extension dimmed.

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `filename` | string | — | File name |

```blade
{{-- resources/views/livewire/games/show.blade.php --}}
<x-game-file.name :filename="$filename" class="text-base font-semibold text-fg-bright" />
```

### `x-game-file.folder`

The subfolder a file sits in, mono, broken anywhere. Nothing for the console root.

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `path` | ?string | `null` | Folder |

```blade
{{-- resources/views/livewire/games/show.blade.php --}}
<x-game-file.folder :path="$folder" class="mt-0.5" />
```

### `x-game-file.lineage`

Where a file came from or went: "Converted from X + 2 tracks · 3 days ago".

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `verb` | string | — | "Converted from", "Converted to"… |
| `name` | string | — | The other file(s) |
| `more` | int | `0` | Extra tracks |
| `when` | ?string | `null` | Relative time |

Slot: trailing extras (the Show/Hide `x-text-link`).

```blade
{{-- resources/views/livewire/games/show.blade.php --}}
<x-game-file.lineage :verb="__('Converted to')" :name="implode(', ', $derived)" class="mt-1.5" />
```

### `x-game-file.field`

One `dt`/`dd` line of a file card's data block; attributes go to the `dd`.

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `label` | string | — | Kicker |
| `copy` | ?string | `null` | Adds an icon `x-copy-button` for it |

Slot: the value.

```blade
{{-- resources/views/livewire/games/show.blade.php --}}
<x-game-file.field :label="__('MD5')" :copy="$md5" class="text-fg-soft">{{ $md5 }}</x-game-file.field>
```

### `x-game-file.badge`

One footer badge on a file card: `x-badge size="sm"` on the sunken ground,
named on hover.

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `tip` | string | — | Tooltip naming what it is |
| `tone` | ?string | `null` | Overrides the derived tone |
| `dashed` | bool | `false` | Dashed border |
| `warn` | bool | `false` | Warn tone |
| `archived` | bool | `false` | Quieter, for an archived row |

```blade
{{-- resources/views/components/game-file/badges.blade.php --}}
<x-game-file.badge :archived="$archived" :tip="__('Format')">{{ $row['format'] }}</x-game-file.badge>
```

### `x-game-file.badges`

A file card's footer row of badges (license ID, region, scrapes, flags, role,
disc, size, format, added, decrypt state), live or archived.

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `row` | array | — | A `fileRows` entry from the game page |
| `archived` | bool | `false` | Archived styling, no decrypt state |

```blade
{{-- resources/views/livewire/games/show.blade.php --}}
<x-game-file.badges :row="$this->fileRows[$index]" class="border-t border-raised pt-3 text-fg-muted" />
```

### `x-game-file.archived-card`

A file no longer in the library, kept as the record of which dump it was
(`docs/adr/0009`), with its Forget button.

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `row` | array | — | Archived row (`depth`, `filename`, `reason`, `folder`, `md5`, `when`, `id`…) |
| `branch` | bool | `true` | Leading `└` |

```blade
{{-- resources/views/livewire/games/show.blade.php --}}
<x-game-file.archived-card :row="$row" :branch="$row['depth'] > 0" wire:key="history-{{ $row['id'] }}" />
```

### `x-decrypt.state`

A PS3 image's `DecryptState` as an `x-badge`: colour from `tone()`/`dashed()`,
tooltip from `hint()`, lock from `x-decrypt.icon`.

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `state` | DecryptState | — | The state |

```blade
{{-- resources/views/livewire/tools/decrypt.blade.php --}}
<x-decrypt.state :state="$state" />
```

### `x-decrypt.icon`

The lock beside a `DecryptState`: open, turning, shut, or none while unchecked.

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `state` | DecryptState | — | The state |

```blade
{{-- resources/views/components/game-file/badges.blade.php --}}
<x-decrypt.icon :state="$decrypt" />
```

### `x-docs.console-picker`

The console a document is filed under, as chips; sets the parent's `console`
with `$set`. "Show all" is Alpine's alone.

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `selected` | string | `''` | Current console key |
| `keyPrefix` | string | `console` | `wire:key` prefix, for two on one page |

```blade
{{-- resources/views/livewire/docs/import-modal.blade.php --}}
<x-docs.console-picker :selected="$console" key-prefix="import-console" />
```

### `x-docs.rail-toggle`

Shows or hides the documents list. Inside an `x-data="docsRail"` scope. No props.

```blade
{{-- resources/views/livewire/games/show.blade.php --}}
<x-docs.rail-toggle class="size-8" />
```

### `x-console-tabs`

One `x-tabs.item` per console, each calling the page's `selectConsole()`
(`App\Concerns\PicksConsoleTab`).

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `consoles` | Collection | — | key → Console |
| `selected` | string | — | Current key |

```blade
{{-- resources/views/livewire/tools/conversion.blade.php --}}
<x-console-tabs :consoles="$this->consoles" :selected="$consoleKey" />
```

### `x-game-card`

One game on the library shelf: cover in the console's shape, title, region,
size, score and achievements. Takes no attributes.

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `game` | Game | — | The game (with the list's joined columns) |
| `showConsole` | bool | `false` | Console logo over the cover (all-consoles list) |

Slot: `actions` = over the cover, shown on hover/focus.

```blade
{{-- resources/views/livewire/games/index.blade.php --}}
<x-game-card :game="$game" :show-console="$this->lockedTo === null">
```

### `x-achievement-progress`

A game's achievements as a bar and its counts, the same on a card and a list
row. The caller sets the width.

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `unlocked` | int | — | Unlocked |
| `possible` | int | — | Total |
| `hardcore` | bool | `false` | Hover title says "Hardcore achievements" |

```blade
{{-- resources/views/livewire/games/index.blade.php --}}
<x-achievement-progress :unlocked="$rowUnlocked" :possible="$rowPossible" :hardcore="$hardcorePrimary" class="w-32 shrink-0" />
```

### `x-transfer-modal`

Send one game, or a whole console, to a USB drive or a saved share
(`docs/adr/0003`, `0004`). Driven by `resources/js/transfer.js`.

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `game` | ?Game | `null` | One game, from its page |
| `console` | ?Console | `null` | A whole console, from its shelf |
| `count` | int | `0` | Identified games on the console |
| `destinations` | Collection | — | Saved `Destination`s |

```blade
{{-- resources/views/livewire/games/show.blade.php --}}
<x-transfer-modal :game="$game" :destinations="$this->destinations" />
```

### `x-transfer-tray`

The USB transfer queue in the corner of every page, from `$store.usb`. Rendered
once, in the app layout. No props.

```blade
{{-- resources/views/layouts/app/sidebar.blade.php --}}
<x-transfer-tray />
```

## Canonical looks

Where the same intent used to be styled several ways, the component settled on
one. Match these when something has no component yet.

| Thing | Canonical |
|---|---|
| Focus ring | `@utility focus-ring` = `focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep`. A `<label>` around a hidden input uses the `has-focus-visible:` form of the same three (`x-file-drop`). |
| Badge (tag) | `x-badge`. `xs`: `inline-flex shrink-0 items-center gap-1 rounded-md border px-1.5 py-0.5 font-mono text-[10px] tracking-kicker uppercase`. `sm`: `h-5.5 gap-1.5 px-2 text-xs normal-case tracking-normal`, trimmed to cap height. Tones: neutral `border-line-strong text-fg-muted`, accent `border-accent-tint/55 text-accent`, success `border-success/50 text-success`, warn `border-warn/50 text-warn`, danger `border-danger/50 text-danger`, muted `border-line-strong text-fg-faint`; `dashed` adds `border-dashed`; `fill` adds the tone's `/10` tint (`bg-sunken` for neutral and muted) |
| Chip (toggle pill) | `x-chip`. `inline-flex cursor-pointer items-center gap-1 rounded-lg border px-2.5 py-1 text-xs transition-colors focus-ring`; on `border-accent-tint/55 bg-accent-tint/10 text-accent`; off `border-line-input text-fg-dim hover:bg-hover hover:text-fg`; `dashed` "add" chip `border-dashed border-line-bright text-fg-dim` |
| Segmented control | `x-pill-choice`. Group `gap-0.5 rounded-lg border border-line-input p-0.5` (`flex flex-wrap`, or an equal-column grid with `full`); button `rounded-md`, `xs` `px-2.75 py-1 text-xs`, `sm` `px-3 py-1.5 text-sm`; active `bg-accent-tint/15 text-accent`; inactive `text-fg-dim hover:text-fg` |
| Option card | `x-option-card`. `flex w-full items-center gap-3 rounded-lg border px-3.5 py-2.5 text-left transition-colors focus-ring`; on `border-accent-tint/55 bg-accent-tint/10`, title `text-accent`; off `border-line-input hover:bg-hover`, title `text-fg-soft`; disabled `cursor-not-allowed border-line opacity-50`; description `mt-0.5 text-xs text-fg-faint` |
| Progress bar | `x-progress-bar`. Track `overflow-hidden rounded-sm bg-raised`; fill `h-full rounded-sm bg-accent-deep transition-[width] duration-300`, `bg-warn` / `bg-danger` by tone; size `xs` `h-[3px]`, `sm` `h-1` (default), `md` `h-1.5` |
| Empty state | `x-empty-state`. Block `rounded-xl border border-dashed border-line-input px-6 py-10 text-center`, optional icon `mx-auto mb-3 size-6 text-fg-faint`, title `text-sm text-fg-soft`, hint `mt-1 text-sm text-fg-faint`, actions `mt-4`; inline `px-4 py-8 text-center text-sm text-fg-faint` (no border) |
| Panel | `x-panel`. `overflow-hidden rounded-xl border border-line`, `bg-surface` (default) or `bg-sunken`; `padded` `p-5`; header strip `flex flex-wrap items-center gap-3 border-b border-raised px-4.5 py-3.5` (`items-end` with `align="end"`), title `kicker text-fg-faint`, actions `ml-auto`; `divided` `divide-y divide-raised` on the body |
| Settings card | `x-settings.card`. `rounded-xl border border-line bg-surface p-5`; kicker header `kicker text-fg-faint`, `mb-1` before a description, `mb-3` without; description `mb-4 text-sm text-fg-soft`; icon header `mb-4 flex items-center gap-2.5`, icon `size-[17px] text-accent`, title `flex-1 text-sm text-fg-bright` |
| Modal | `x-modal`. `flux:modal` → body `flex flex-col gap-5`; heading `flux:heading size="lg"`; subheading `flux:text class="mt-1"` (mono: `mt-1 truncate font-mono text-xs text-fg-faint`); footer `flex items-center justify-end gap-2` (`justify-between` with footer-start); Cancel/Close ghost button first, primary last |
| Field label (kicker over a control) | `x-field-group`. `kicker mb-2 text-fg-faint`; hint `mb-2 text-xs text-fg-faint`. Never `text-fg-dim` for kickers |
| Stat | `x-stat`. Label `kicker text-fg-faint`; value `mt-1 font-mono text-lg tabular-nums text-fg-bright`; `size="lg"` `mt-1.5 text-[19px] font-medium tracking-display` (sans); `tone="warn"` `text-warn`; sub line `mt-1 text-sm text-fg-dim`; bar `mt-2.5`, `xs` |
| Notice | `x-notice`. `flex items-start gap-2.5 rounded-lg border px-4 py-3 text-sm`; `size="sm"` `gap-1.5 rounded-md px-2 py-1.5 text-xs`; neutral `border-line-input bg-sunken text-fg-soft`, accent `border-accent-tint/55 bg-accent-tint/10 text-accent`, warn `border-warn/40 bg-warn/10 text-warn`, danger `border-danger/40 bg-danger/10 text-danger`; `busy` leading `flux:icon.arrow-path` `animate-spin`; `count` `ml-auto font-mono text-xs` |
| Chevron | `x-chevron`. `flux:icon.chevron-down` `size-3.5 shrink-0 transition-transform duration-200`, `-rotate-180` while open |
| Clickable card hover | `hover:border-accent-tint/55` (`x-game-card`). `x-link-card` is the one exception: `hover:border-accent-tint/50 hover:bg-accent-tint/6` |
| Icon button | `x-icon-button`. `grid shrink-0 cursor-pointer place-items-center rounded-md text-fg-faint transition-colors hover:bg-hover focus-ring`, `md` `size-7` + icon `size-4`, `sm` `size-5` + micro `size-3.5`; `hover:text-fg-soft`, or `hover:text-danger` for danger; always an `aria-label` |
| Glass button | `x-glass-button`. `cursor-pointer rounded-lg border border-line-input bg-scrim/60 text-fg-soft backdrop-blur-sm transition-colors hover:border-line-bright hover:text-fg-bright focus-ring`; with a label `flex items-center gap-1.75 px-3 py-1.75 text-sm`, icon `size-3.5`, chevron `size-[11px] text-fg-dim`; icon-only `grid size-8 place-items-center p-0`, icon `size-4` |
| Fraction | `x-fraction`. `{{ a }} <span class="text-fg-faint">/ {{ b }}</span>`, spaces around `/`, `tabular-nums` |
| Keycap | `x-kbd`. `<kbd class="font-sans text-xs text-fg-dim">`, no border |
| Loading spinner meaning "waiting" | `flux:icon.loading`, except the `x-notice` `busy` banner and the sidebar Activity meter's figure, which use `arrow-path animate-spin` |
| Section header | `x-section-header`. `mb-3.5 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-2`, title `h2 text-lg font-medium text-fg-bright` |
| Page header | `x-page-header`. `mb-6 flex flex-wrap items-end justify-between gap-4`; kicker `kicker mb-1.5 text-fg-faint`; title `h1 text-display font-medium tracking-display text-fg-bright`; meta `mt-1.5 font-mono text-xs text-fg-faint`; description `mt-1.5 text-sm text-fg-muted`; actions `flex flex-wrap items-center gap-2` |

Anything not listed: follow the most common existing variant, then make it a
component.
