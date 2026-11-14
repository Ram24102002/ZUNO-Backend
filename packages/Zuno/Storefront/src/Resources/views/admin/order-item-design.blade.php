{{-- Rendered under each order line on Bagisto's admin order page; only custom tees have a design --}}
@php($design = $item->additional['zuno_design'] ?? null)

@if ($design)
    <div class="px-4 pb-4">
        <p class="mb-2 text-sm font-semibold text-gray-800 dark:text-white">Custom design</p>

        <div class="flex flex-wrap gap-3">
            @if (! empty($design['preview']))
                <a href="{{ route('zuno.admin.designs.show', [$item->id, 'preview']) }}" target="_blank" class="block text-center text-xs text-gray-600 dark:text-gray-300">
                    <img src="{{ route('zuno.admin.designs.show', [$item->id, 'preview']) }}" alt="Tee preview" class="h-32 w-28 rounded border object-contain bg-gray-50 dark:border-gray-800">
                    Mockup
                </a>
            @endif

            @foreach ($design['artwork'] ?? [] as $i => $path)
                @php($file = 'artwork-'.($i + 1))
                <div class="text-center text-xs text-gray-600 dark:text-gray-300">
                    <a href="{{ route('zuno.admin.designs.show', [$item->id, $file]) }}" target="_blank">
                        <img src="{{ route('zuno.admin.designs.show', [$item->id, $file]) }}" alt="Artwork {{ $i + 1 }}" class="h-32 w-28 rounded border object-contain bg-gray-50 dark:border-gray-800">
                    </a>
                    <a href="{{ route('zuno.admin.designs.show', [$item->id, $file]) }}?download=1" class="text-blue-600 hover:underline">
                        Download artwork {{ $i + 1 }}
                    </a>
                </div>
            @endforeach
        </div>
    </div>
@endif
