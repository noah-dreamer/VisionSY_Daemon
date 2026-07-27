/* ---------------- 全局下拉选择框增强 ---------------- */
let enhancedSelectSequence = 0;
let closeActiveEnhancedSelect = null;

export function initEnhancedSelects() {
    document.querySelectorAll('select.form-input:not([data-native-select])').forEach((select) => {
        if (select.dataset.enhancedSelect === 'true') return;

        select.dataset.enhancedSelect = 'true';
        enhancedSelectSequence += 1;

        const selectId = select.id || `visionsy-select-${enhancedSelectSequence}`;
        const isMultiple = select.multiple;
        const placeholder = select.dataset.selectPlaceholder || '请选择';
        const wrapper = document.createElement('div');
        const trigger = document.createElement('button');
        const triggerText = document.createElement('span');
        const chevron = document.createElement('span');
        const menu = document.createElement('div');
        const optionList = document.createElement('div');
        const optionButtons = [];
        let selectionChangedWhileOpen = false;

        const labelText = select.getAttribute('aria-label')
            || select.labels?.[0]?.textContent?.trim()
            || '选择选项';

        select.id = selectId;
        wrapper.className = 'enhanced-select';

        /*
         * form-input 自身包含 w-full，但部分页面额外使用 w-auto、sm:w-44 等布局 class。
         * 将这些布局 class 复制到增强后的外层容器，避免全局增强改变现有宽度。
         */
        const layoutClasses = Array.from(select.classList).filter((className) => (
            /^(?:(?:sm|md|lg|xl|2xl):)?(?:w-|min-w-|max-w-)/.test(className)
            || ['flex-1', 'shrink-0', 'grow'].includes(className)
        ));

        const hasBaseWidth = layoutClasses.some(
            (className) => !className.includes(':') && className.startsWith('w-'),
        );

        if (!hasBaseWidth) {
            wrapper.classList.add('w-full');
        }

        if (layoutClasses.length > 0) {
            wrapper.classList.add(...layoutClasses);
        }

        select.parentNode.insertBefore(wrapper, select);
        wrapper.appendChild(select);

        select.classList.add('enhanced-select-native');
        select.tabIndex = -1;

        trigger.type = 'button';
        trigger.className = 'enhanced-select-trigger';
        trigger.id = `${selectId}-trigger`;
        trigger.setAttribute('aria-label', labelText);
        trigger.setAttribute('aria-haspopup', 'listbox');
        trigger.setAttribute('aria-expanded', 'false');
        trigger.setAttribute('aria-controls', `${selectId}-listbox`);
        trigger.disabled = select.disabled;

        triggerText.className = 'enhanced-select-trigger-text';

        chevron.className = 'enhanced-select-chevron';
        chevron.setAttribute('aria-hidden', 'true');
        chevron.innerHTML = `
            <svg viewBox="0 0 20 20" fill="none">
                <path
                    d="m6 8 4 4 4-4"
                    stroke="currentColor"
                    stroke-width="1.7"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                />
            </svg>
        `;

        trigger.append(triggerText, chevron);
        wrapper.appendChild(trigger);

        menu.className = 'enhanced-select-menu';
        menu.id = `${selectId}-listbox`;
        menu.setAttribute('role', 'listbox');
        menu.setAttribute('aria-labelledby', trigger.id);

        if (isMultiple) {
            menu.setAttribute('aria-multiselectable', 'true');
        }

        optionList.className = 'enhanced-select-options';
        menu.appendChild(optionList);
        wrapper.appendChild(menu);

        const nativeOptions = Array.from(select.options);

        nativeOptions.forEach((option, index) => {
            const optionButton = document.createElement('button');
            const indicator = document.createElement('span');
            const optionLabel = document.createElement('span');

            optionButton.type = 'button';
            optionButton.className = 'enhanced-select-option';
            optionButton.dataset.optionIndex = String(index);
            optionButton.setAttribute('role', 'option');
            optionButton.setAttribute(
                'aria-selected',
                option.selected ? 'true' : 'false',
            );
            optionButton.disabled = option.disabled;

            indicator.className = 'enhanced-select-option-indicator';
            indicator.setAttribute('aria-hidden', 'true');
            indicator.innerHTML = `
                <svg viewBox="0 0 16 16" fill="none">
                    <path
                        d="m3.5 8 2.75 2.75L12.5 4.5"
                        stroke="currentColor"
                        stroke-width="1.8"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    />
                </svg>
            `;

            optionLabel.className = 'min-w-0 flex-1 truncate';
            optionLabel.textContent = option.textContent.trim();

            optionButton.append(indicator, optionLabel);
            optionList.appendChild(optionButton);
            optionButtons.push(optionButton);

            optionButton.addEventListener('click', () => {
                if (option.disabled) return;

                if (isMultiple) {
                    option.selected = !option.selected;
                    selectionChangedWhileOpen = true;
                } else {
                    select.selectedIndex = index;
                }

                select.dispatchEvent(new Event('change', { bubbles: true }));
                updateFromNative();

                if (!isMultiple) {
                    closeMenu();
                    trigger.focus();
                }
            });
        });

        if (isMultiple) {
            const actions = document.createElement('div');
            const clearButton = document.createElement('button');

            actions.className = 'enhanced-select-actions';

            clearButton.type = 'button';
            clearButton.className = 'enhanced-select-clear';
            clearButton.textContent = '清空选择';

            clearButton.addEventListener('click', () => {
                nativeOptions.forEach((option) => {
                    option.selected = false;
                });

                selectionChangedWhileOpen = true;

                select.dispatchEvent(new Event('change', { bubbles: true }));
                updateFromNative();
                closeMenu();
                trigger.focus();
            });

            actions.appendChild(clearButton);
            menu.appendChild(actions);
        }

        function updateFromNative() {
            const selectedOptions = nativeOptions.filter(
                (option) => option.selected,
            );

            if (isMultiple) {
                if (selectedOptions.length === 0) {
                    triggerText.textContent = placeholder;
                    trigger.classList.add('is-placeholder');
                } else if (selectedOptions.length === 1) {
                    triggerText.textContent = selectedOptions[0].textContent.trim();
                    trigger.classList.remove('is-placeholder');
                } else {
                    triggerText.textContent = `已选择 ${selectedOptions.length} 项`;
                    trigger.classList.remove('is-placeholder');
                }
            } else {
                const selectedOption = selectedOptions[0];

                triggerText.textContent = selectedOption?.textContent.trim()
                    || placeholder;

                trigger.classList.toggle(
                    'is-placeholder',
                    !selectedOption || selectedOption.value === '',
                );
            }

            optionButtons.forEach((optionButton, index) => {
                const selected = nativeOptions[index].selected;

                optionButton.classList.toggle('is-selected', selected);
                optionButton.setAttribute(
                    'aria-selected',
                    selected ? 'true' : 'false',
                );
            });

            trigger.disabled = select.disabled;
        }

        function focusMenuOption(direction = 'selected') {
            const enabledButtons = optionButtons.filter(
                (button) => !button.disabled,
            );

            if (enabledButtons.length === 0) return;

            const selectedButton = enabledButtons.find(
                (button) => button.classList.contains('is-selected'),
            );

            const target = direction === 'last'
                ? enabledButtons[enabledButtons.length - 1]
                : (selectedButton || enabledButtons[0]);

            target.focus({ preventScroll: true });
        }

        function openMenu(focusDirection = 'selected') {
            if (trigger.disabled || wrapper.classList.contains('is-open')) {
                return;
            }

            if (closeActiveEnhancedSelect) {
                closeActiveEnhancedSelect();
            }

            closeActiveEnhancedSelect = closeMenu;

            wrapper.classList.add('is-open');
            trigger.setAttribute('aria-expanded', 'true');

            requestAnimationFrame(() => {
                const rect = wrapper.getBoundingClientRect();
                const menuHeight = Math.min(menu.scrollHeight, 320);
                const availableBelow = window.innerHeight - rect.bottom;
                const availableAbove = rect.top;

                wrapper.classList.toggle(
                    'opens-upward',
                    availableBelow < menuHeight + 16
                        && availableAbove > availableBelow,
                );

                focusMenuOption(focusDirection);
            });
        }

        function closeMenu() {
            const wasOpen = wrapper.classList.contains('is-open');

            wrapper.classList.remove('is-open', 'opens-upward');
            trigger.setAttribute('aria-expanded', 'false');

            if (closeActiveEnhancedSelect === closeMenu) {
                closeActiveEnhancedSelect = null;
            }

            if (wasOpen && isMultiple && selectionChangedWhileOpen) {
                selectionChangedWhileOpen = false;

                select.dispatchEvent(
                    new CustomEvent('enhanced-select:commit', {
                        bubbles: true,
                    }),
                );
            }
        }

        trigger.addEventListener('click', () => {
            if (wrapper.classList.contains('is-open')) {
                closeMenu();
            } else {
                openMenu();
            }
        });

        trigger.addEventListener('keydown', (event) => {
            if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(event.key)) {
                event.preventDefault();

                openMenu(
                    event.key === 'ArrowUp' ? 'last' : 'selected',
                );
            }
        });

        menu.addEventListener('keydown', (event) => {
            const enabledButtons = optionButtons.filter(
                (button) => !button.disabled,
            );

            const currentIndex = enabledButtons.indexOf(
                document.activeElement,
            );

            if (event.key === 'Escape') {
                event.preventDefault();
                closeMenu();
                trigger.focus();
                return;
            }

            if (event.key === 'Tab') {
                closeMenu();
                return;
            }

            if (event.key === 'Home' || event.key === 'End') {
                event.preventDefault();

                const target = event.key === 'Home'
                    ? enabledButtons[0]
                    : enabledButtons[enabledButtons.length - 1];

                target?.focus({ preventScroll: true });
                return;
            }

            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();

                const offset = event.key === 'ArrowDown' ? 1 : -1;

                const nextIndex = currentIndex < 0
                    ? 0
                    : (
                        currentIndex
                        + offset
                        + enabledButtons.length
                    ) % enabledButtons.length;

                enabledButtons[nextIndex]?.focus({
                    preventScroll: true,
                });
            }
        });

        document.addEventListener('pointerdown', (event) => {
            if (!wrapper.contains(event.target)) {
                closeMenu();
            }
        });

        select.addEventListener('change', () => {
            trigger.classList.remove('is-invalid');
            updateFromNative();
        });

        select.addEventListener('invalid', () => {
            trigger.classList.add('is-invalid');
            trigger.focus();
        });

        select.form?.addEventListener('reset', () => {
            setTimeout(updateFromNative);
        });

        updateFromNative();
    });
}

/* ---------------- 自动筛选表单 ---------------- */
export function initAutoSubmitFilters() {
    document.querySelectorAll('form[data-auto-filter]').forEach((form) => {
        let timer = null;
        let submitting = false;
        let composing = false;

        const submitForm = () => {
            if (submitting) return;

            submitting = true;

            form.querySelectorAll('input[name="page"]').forEach((input) => {
                input.remove();
            });

            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
            } else {
                form.submit();
            }
        };

        const scheduleSubmit = (delay) => {
            window.clearTimeout(timer);
            timer = window.setTimeout(submitForm, delay);
        };

        form.querySelectorAll('[data-filter-control]').forEach((control) => {
            if (control.multiple) {
                control.addEventListener(
                    'enhanced-select:commit',
                    () => scheduleSubmit(150),
                );
            } else {
                control.addEventListener(
                    'change',
                    () => scheduleSubmit(250),
                );
            }
        });

        form.querySelectorAll('[data-filter-search]').forEach((input) => {
            input.addEventListener('compositionstart', () => {
                composing = true;
            });

            input.addEventListener('compositionend', () => {
                composing = false;
                scheduleSubmit(500);
            });

            input.addEventListener('input', () => {
                if (!composing) {
                    scheduleSubmit(500);
                }
            });
        });

        form.addEventListener('submit', () => {
            window.clearTimeout(timer);
            submitting = true;
        });
    });
}
