<script setup>
import { ref, defineProps, defineEmits } from 'vue'

const props = defineProps({
    modelValue: String,
    placeholder: {
        type: String,
        default: 'Pesquisar'
    }
})

const emits = defineEmits(['update:modelValue'])

function clear() {
    emits('update:modelValue', '')
}
</script>

<template>
    <div class="search-wrapper">
        <v-icon class="search-icon" small color="grey">mdi-magnify</v-icon>
        <input
            type="text"
            :placeholder="placeholder"
            :value="modelValue"
            @input="e => emits('update:modelValue', e.target.value)"
        />
        <span v-if="modelValue" class="clear-icon" @click="clear">✕</span>
    </div>
</template>

<style scoped>
.search-wrapper {
    position: relative;
    width: 100%;
    display: flex;
    align-items: center;
    border: 1px solid #ccc;
    border-radius: 8px;
    padding: 0 10px;
    background-color: #fff;
}

.search-wrapper input {
    border: none;
    outline: none;
    flex: 1;
    font-size: 1em;
    color: #333;
}

.search-wrapper input::placeholder {
    color: #aaa;
}

.search-wrapper .search-icon {
    margin-right: 8px;
    color: #888;
}

.search-wrapper .clear-icon {
    cursor: pointer;
    margin-left: 8px;
    color: #888;
}

.search-wrapper input:focus {
    outline: none;
    border-color: #FFFFFF;
    box-shadow: none !important;
}

</style>
