@php
    $sortHeaders = [
        'name' => 'Категорія',
        'children_count' => 'Підкатегорії',
        'products_count' => 'Товарів',
        'stock_display_threshold' => 'Поріг відображення',
        'is_active' => 'Стан',
    ];
@endphp

<div
    x-load
    x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('tree-view', 'solutionforest/filament-nestable-tree') }}"
    x-data="treeView({
        data: $wire.entangle('treeNodes'),
        treeKey: null,
        maxVisibleDepth: @js($treeConfig->getMaxVisibleDepth()),
        idField: @js($treeConfig->getRecordKeyField()),
        nameField: @js($treeConfig->getLabelField()),
        childrenField: @js($treeConfig->getChildrenField()),
        allowDragDrop: @js($allowDragDrop),
        allowCrossCategory: @js($allowCrossCategory),
        asyncChildren: false,
        highlightSearch: true,
        onNodeMove: (node, details) => {
            $wire.dispatch('tree-node-moved', { node: node, details: details });
        },
        onOrderChanged: (node, details) => {
            $wire.dispatch('tree-order-changed', { node: node, details: details });
        },
    })"
    class="filament-nestable-tree bp-category-tree"
>
    <div class="bp-category-tree-frame">
        <div class="bp-category-tree-controls">
            @if ($isSearchable)
                <div class="bp-category-tree-search">
                    <x-filament-nestable-tree::tree.search-bar />
                </div>
            @endif

            @if (count($toolbarActions) > 0)
                <x-filament::actions
                    class="bp-category-tree-context-actions"
                    :actions="$toolbarActions"
                />
            @endif
        </div>

        <div class="bp-category-tree-table">
            <div class="bp-category-tree-header" role="row">
                @foreach ($sortHeaders as $column => $label)
                    <button
                        type="button"
                        wire:click="sortTree('{{ $column }}')"
                        wire:loading.attr="disabled"
                        wire:target="sortTree('{{ $column }}')"
                        class="bp-category-tree-header-cell {{ $sortColumn === $column ? 'bp-category-tree-header-cell--sorted' : '' }}"
                        aria-sort="{{ $sortColumn === $column ? ($sortDirection === 'asc' ? 'ascending' : 'descending') : 'none' }}"
                    >
                        <span>{{ $label }}</span>
                        <svg
                            class="bp-category-tree-sort-icon {{ $sortColumn === $column ? 'bp-category-tree-sort-icon--active' : '' }} {{ $sortColumn === $column && $sortDirection === 'asc' ? 'bp-category-tree-sort-icon--asc' : '' }}"
                            viewBox="0 0 20 20"
                            fill="currentColor"
                            aria-hidden="true"
                        >
                            <path fill-rule="evenodd" d="M5.22 8.22a.75.75 0 011.06 0L10 11.94l3.72-3.72a.75.75 0 111.06 1.06l-4.25 4.25a.75.75 0 01-1.06 0L5.22 9.28a.75.75 0 010-1.06z" clip-rule="evenodd" />
                        </svg>
                    </button>
                @endforeach

                <div class="bp-category-tree-header-cell bp-category-tree-header-cell--actions">
                    Дії
                </div>
            </div>

            <div class="fi-tree-node-list bp-category-tree-body" style="position: relative;">
                <template x-for="(node, loopIndex) in flattenedVisibleNodes" :key="String(node[idField]) + '-' + loopIndex">
                    <div
                        class="fi-tree-node-row bp-category-tree-row"
                        :class="{
                            'fi-tree-node-row--selected': selectedNode === node[idField],
                            'fi-tree-node-row--dragging': draggedNodeId === String(node[idField]),
                            'fi-tree-node-row--drop-inside': dropTargetId === String(node[idField]) && dropPosition === 'inside',
                            'fi-tree-node-row--has-descendant-match': node._hasDescendantMatch && !node._isExpanded && searchQuery.trim(),
                        }"
                        :draggable="allowDragDrop ? 'true' : 'false'"
                        @dragstart="dragStart($event, node[idField])"
                        @dragover="dragOver($event, node._index, node._parentId, node[idField], node._depth)"
                        @dragleave="dragLeave($event)"
                        @drop="drop($event, node._index, node._parentId, node[idField])"
                        @dragend="dragEnd()"
                        @click="selectNode(node[idField])"
                        role="row"
                    >
                        <div
                            class="bp-category-tree-cell bp-category-tree-name-cell"
                            :style="'padding-left: ' + ((node._depth * 20) + 12) + 'px'"
                        >
                            <button
                                type="button"
                                class="fi-tree-node-toggle"
                                :class="{ 'fi-tree-node-toggle--hidden': !node._hasChildren }"
                                @click.stop="toggleNode(node[idField])"
                                :aria-label="node._isExpanded ? 'Згорнути підкатегорії' : 'Розгорнути підкатегорії'"
                            >
                                <svg
                                    class="fi-tree-toggle-icon"
                                    :class="{ 'fi-tree-toggle-icon--expanded': node._isExpanded }"
                                    viewBox="0 0 20 20"
                                    fill="currentColor"
                                    aria-hidden="true"
                                >
                                    <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" />
                                </svg>
                            </button>

                            @if ($allowDragDrop)
                                <span class="fi-tree-drag-handle" title="Перетягніть, щоб змінити порядок"></span>
                            @endif

                            <span
                                class="fi-tree-node-label"
                                :class="{ 'fi-tree-node-label--match': node._matchesSearch && searchQuery }"
                                x-html="formatNodeText(node)"
                            ></span>
                        </div>

                        <div class="bp-category-tree-cell bp-category-tree-number-cell" x-text="node.children_count"></div>
                        <div class="bp-category-tree-cell bp-category-tree-number-cell" x-text="node.products_count"></div>
                        <div class="bp-category-tree-cell bp-category-tree-number-cell" x-text="node.stock_display_threshold"></div>

                        <div class="bp-category-tree-cell">
                            <span
                                class="bp-category-tree-status"
                                :class="node.is_active ? 'bp-category-tree-status--active' : 'bp-category-tree-status--inactive'"
                                x-text="node.is_active ? 'Активна' : 'Неактивна'"
                            ></span>
                        </div>

                        <div class="bp-category-tree-cell bp-category-tree-actions-cell" @click.stop>
                            @if ($hasNodeActions)
                                <div
                                    class="fi-tree-node-actions"
                                    x-data="{
                                        actions: null,
                                        loading: false,
                                        async fetchActions() {
                                            if (this.actions !== null) return;
                                            this.loading = true;
                                            try {
                                                this.actions = await $wire.call('loadTreeNodeActions', node[idField], null);
                                            } finally {
                                                this.loading = false;
                                            }
                                        },
                                        init() {
                                            $nextTick(async () => {
                                                await this.fetchActions();
                                            })
                                        },
                                    }"
                                >
                                    <svg
                                        x-show="loading"
                                        class="fi-tree-node-action-spinner h-3 w-3 animate-spin text-gray-400 dark:text-gray-500"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        aria-hidden="true"
                                    >
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.961 3 8.118l3-2.827z" />
                                    </svg>

                                    <template x-if="actions !== null && !loading">
                                        <x-filament::actions class="fi-tree-node-actions-list">
                                            <template x-for="(action, idx) in actions" :key="idx">
                                                <div x-html="action" class="fi-tree-node-action-item"></div>
                                            </template>
                                        </x-filament::actions>
                                    </template>
                                </div>
                            @endif
                        </div>
                    </div>
                </template>

                @if ($allowDragDrop)
                    <div
                        class="fi-tree-root-drop-zone bp-category-tree-root-drop-zone"
                        x-show="draggedNodeId !== null || crossTreeDragging"
                        x-cloak
                        @dragover="dragOverRoot($event)"
                        @dragleave="if (!$el.contains($event.relatedTarget)) { rootDropZoneActive = false; dropLine.visible = false }"
                        @drop.prevent="dropAtRoot($event)"
                    ></div>

                    <div
                        class="drop-line"
                        x-show="dropLine.visible && (draggedNodeId !== null || crossTreeDragging)"
                        x-cloak
                        :style="{ top: dropLine.y + 'px', left: dropLine.left + 'px', width: 'calc(100% - ' + dropLine.left + 'px)' }"
                    ></div>
                @endif

                <div
                    class="fi-tree-empty-state"
                    x-show="flattenedVisibleNodes.length === 0"
                    x-cloak
                >
                    Категорій не знайдено.
                </div>
            </div>
        </div>
    </div>

    <x-filament-actions::modals />
</div>
