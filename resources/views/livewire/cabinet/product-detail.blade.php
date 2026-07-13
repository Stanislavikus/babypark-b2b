            {{-- Variants with prices --}}
            @foreach($product->variants->where('is_active', true) as $variant)
                @php
                    $priceDisplay = $variantPriceDisplay[$variant->id] ?? null;
                    if (! $priceDisplay) continue;
                    $attrs = is_array($variant->attributes) ? $variant->attributes : [];
                @endphp

                <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                    @if(count($attrs) > 0)
                        <div class="mb-3 flex flex-wrap gap-2">
                            @foreach($attrs as $attr => $val)
                                <span class="rounded-full bg-gray-100 px-3 py-0.5 text-xs font-medium text-gray-600">
                                    {{ $attr }}: {{ $val }}
                                </span>
                            @endforeach
                        </div>
                    @endif

                    <div class="flex items-end gap-4 flex-wrap">
                        <div>
                            <p class="text-xs text-gray-500 uppercase tracking-wide">Ваша ціна (з ПДВ)</p>
                            <p class="text-3xl font-bold text-primary-700">
                                {{ $variantPriceLabels[$variant->id] ?? '—' }}
                            </p>
                        </div>
                        @if($priceDisplay->recommendedRetailPrice)
                            <div>
                                <p class="text-xs text-gray-500 uppercase tracking-wide">Рекомендована роздрібна</p>
                                <p class="text-lg font-medium text-gray-400 line-through">
                                    {{ number_format($priceDisplay->recommendedRetailPrice, 2, ',', ' ') }} ₴
                                </p>
                            </div>
                        @endif
                    </div>

                    {{-- Net availability (variant-level, accounts for pending reservations) --}}
                    @if(isset($variantNetAvailability[$variant->id]))
                        <p class="mt-3 text-sm text-gray-700">
                            <span class="text-gray-500">Загальна наявність:</span>
                            <span class="font-medium">{{ $variantNetAvailability[$variant->id] }} шт</span>
                        </p>
                    @endif

                    {{-- Stock per location --}}
                    @if(isset($variantStockDisplay[$variant->id]) && count($variantStockDisplay[$variant->id]) > 0)
                        <div class="mt-3 space-y-1.5">
                            @foreach($variantStockDisplay[$variant->id] as $stockRow)
                                <div class="flex items-center justify-between text-sm">
                                    <span class="text-gray-600">{{ $stockRow['location_name'] }}</span>
                                    <span class="{{ $stockRow['status_class'] }}">{{ $stockRow['status_label'] }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endforeach