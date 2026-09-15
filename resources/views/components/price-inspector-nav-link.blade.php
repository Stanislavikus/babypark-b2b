@props(['href', 'label', 'class' => 'font-medium text-primary-600 underline hover:text-primary-500 dark:text-primary-400 dark:hover:text-primary-300'])

<a href="{{ $href }}"
   target="_blank"
   rel="noopener noreferrer"
   {{ $attributes->merge(['class' => $class]) }}>
    {{ $label }}
    <span class="sr-only">{{ __('price_inspector.opens_in_new_tab') }}</span>
</a>
