{{--
    A region order to sort: the library's under Settings → Destinations, one
    console's in its edit modal, one transfer's in Send to. The list is the
    bound value — wire:model or x-model on the component, through
    x-modelable — and the sorting is all in the browser, so the surrounding
    page only hears the order that results.

    `labels` names every region that can be added; `keepOne` stops the last
    region being removed, for an order with no empty state to fall back on.
--}}
@props(['labels' => App\Support\TransferRegions::besides([]), 'keepOne' => false])

<div
    x-data="{
        order: [],
        labels: @js($labels),
        keepOne: @js((bool) $keepOne),
        get others() {
            return Object.keys(this.labels).filter((code) => !this.order.includes(code));
        },
        label(code) {
            return this.labels[code] ?? code;
        },
        move(index, by) {
            const to = index + by;
            if (to < 0 || to >= this.order.length) return;
            const order = [...this.order];
            [order[index], order[to]] = [order[to], order[index]];
            this.order = order;
        },
        remove(index) {
            if (this.keepOne && this.order.length < 2) return;
            this.order = this.order.filter((_, at) => at !== index);
        },
        add(code) {
            if (code && !this.order.includes(code)) this.order = [...this.order, code];
        },
    }"
    x-modelable="order"
    {{ $attributes }}
>
    <ol class="flex flex-col gap-1.5">
        <template x-for="(code, index) in order" :key="code">
            <li class="flex items-center gap-2 rounded-lg border border-line-input bg-sunken px-3 py-1.5">
                <span class="w-5 font-mono text-xs text-fg-faint" x-text="index + 1"></span>
                <span class="min-w-0 flex-1 truncate text-sm text-fg-bright" x-text="label(code)"></span>
                <span class="font-mono text-xs text-fg-faint" x-text="code"></span>
                <flux:button size="xs" variant="ghost" icon="chevron-up" type="button"
                             x-on:click="move(index, -1)" x-bind:disabled="index === 0"
                             :aria-label="__('Move up')" />
                <flux:button size="xs" variant="ghost" icon="chevron-down" type="button"
                             x-on:click="move(index, 1)" x-bind:disabled="index === order.length - 1"
                             :aria-label="__('Move down')" />
                <flux:button size="xs" variant="ghost" icon="x-mark" type="button"
                             x-on:click="remove(index)" x-bind:disabled="keepOne && order.length === 1"
                             x-bind:aria-label="@js(__('Remove')) + ' ' + label(code)" />
            </li>
        </template>
    </ol>

    <template x-if="others.length > 0">
        <div class="mt-3">
            <flux:select x-on:change="add($event.target.value); $event.target.value = ''" size="sm">
                <option value="">{{ __('Add a region…') }}</option>
                <template x-for="code in others" :key="code">
                    <option x-bind:value="code" x-text="label(code)"></option>
                </template>
            </flux:select>
        </div>
    </template>
</div>
