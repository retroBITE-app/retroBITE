@props(['title', 'description' => null])

{{-- A setting named on the left and its control on the right. The text grows
     and the control keeps its width, so a long description wraps beside it;
     only below 16rem of text, on a phone, does the control drop under it. A
     control that should always sit underneath takes `w-full`. --}}
<div {{ $attributes->class('flex flex-wrap items-center justify-between gap-4') }}>
    <div class="min-w-0 flex-1 basis-64">
        <p class="text-sm font-medium text-fg-bright">{{ $title }}</p>

        @if ($description !== null)
            <p class="mt-1 text-sm text-fg-muted">{{ $description }}</p>
        @endif
    </div>

    {{ $slot }}
</div>
