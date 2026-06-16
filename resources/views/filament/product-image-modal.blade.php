{{--
  Legacy modal view kept for backward compatibility.
  Now shows only images[0] at max 600px wide (image only, no extra data).
--}}
@php $url = is_array($images) && count($images) > 0 ? $images[0] : null; @endphp

@if($url)
    <div class="flex justify-center p-2">
        <img
            src="{{ $url }}"
            alt="Фото товару"
            style="max-width:600px; max-height:80vh; width:100%; height:auto; object-fit:contain; border-radius:8px; background:#f9fafb;"
            onerror="this.style.display='none'"
        />
    </div>
@else
    <div class="flex items-center justify-center py-12 text-gray-400">
        Зображення відсутнє
    </div>
@endif
