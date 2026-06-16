<div class="space-y-3">
    @forelse($images as $url)
        <div class="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700">
            <img
                src="{{ $url }}"
                alt="Фото товару"
                class="w-full h-auto max-h-96 object-contain bg-gray-50 dark:bg-gray-900"
                onerror="this.style.display='none'"
            />
        </div>
    @empty
        <div class="flex items-center justify-center h-32 text-gray-400">
            <span>Зображення відсутні</span>
        </div>
    @endforelse
</div>
