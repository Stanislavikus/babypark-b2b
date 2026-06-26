<script>
    (function () {
        const findHeaderViewButton = () =>
            document.querySelector('[data-bp-align-ref="header-view"]') ??
            [...document.querySelectorAll('.fi-resource-edit-record-page .fi-header a, .fi-resource-edit-record-page .fi-header button')]
                .find((el) => el.textContent.trim().includes('Перегляд'));

        const alignFormActions = () => {
            const ref = findHeaderViewButton();
            const formActions = document.querySelector('.fi-resource-edit-record-page .fi-form-actions .fi-ac-actions');

            if (!ref || !formActions) {
                return;
            }

            const shift = ref.getBoundingClientRect().right - formActions.getBoundingClientRect().right;
            formActions.style.transform = shift !== 0 ? 'translateX(' + shift + 'px)' : '';
        };

        alignFormActions();
        window.addEventListener('resize', alignFormActions);
        document.addEventListener('livewire:navigated', alignFormActions);
        document.addEventListener('livewire:init', () => {
            Livewire.hook('morph.updated', alignFormActions);
        });
    })();
</script>
