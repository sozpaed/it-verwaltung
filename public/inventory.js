'use strict';

const category = document.getElementById('device-category');
const type = document.getElementById('device-type');
if (category && type) {
    const updateType = () => {
        type.value = category.selectedOptions[0]?.dataset.typeId || '';
    };
    category.addEventListener('change', updateType);
    updateType();
}

const number = document.getElementById('inventory-number');
const modes = document.querySelectorAll('input[name="number_mode"]');
if (number && modes.length) {
    const updateNumber = () => {
        const automatic = document.querySelector('input[name="number_mode"]:checked')?.value === 'automatic';
        number.disabled = automatic;
        number.required = !automatic;
    };
    modes.forEach(mode => mode.addEventListener('change', updateNumber));
    updateNumber();
}
