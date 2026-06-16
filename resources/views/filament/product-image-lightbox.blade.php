{{--
  Lightbox content: shows images[0] only, capped at 600px wide.
  Used in ProductResource table thumbnail action and product view/edit.
--}}
@if($url)
    <div class="flex justify-center p-2">
        <img
            src="{{ $url }}"
            alt="{{ $alt ?? 'Фото товару' }}"
            style="max-width:600px; max-height:80vh; width:100%; height:auto; object-fit:contain; border-radius:8px; background:#f9fafb;"
            onerror="this.style.display='none'; this.insertAdjacentHTML('afterend', '<p class=\'text-center text-gray-400 py-8\'>Не вдалося завантажити зображення</p>')"
        />
    </div>
@else
    <div class="flex items-center justify-center py-12 text-gray-400">
        <svg class="w-12 h-12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
        </svg>
        <span class="ml-2">Зображення відсутнє</span>
    </div>
@endif
