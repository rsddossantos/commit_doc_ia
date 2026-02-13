<script setup>
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'

const token = ref('')
const loading = ref(false)

function submit() {
    if (!token.value) return

    loading.value = true

    router.post('/login', {
        token: token.value
    }, {
        onFinish: () => loading.value = false
    })
}
</script>

<template>
    <v-app>
        <v-main>
            <v-container
                fluid
                class="fill-height d-flex align-center justify-center login-background"
            >
                <v-card width="420" class="pa-8 elevation-12 rounded-lg text-center">

                    <v-card-title class="text-h5 font-weight-bold mb-6">
                        CommitDoc AI
                    </v-card-title>

                    <v-alert
                        v-if="$page.props.error"
                        type="error"
                        class="mb-4"
                        border="left"
                    >
                        {{ $page.props.error }}
                    </v-alert>

                    <v-text-field
                        v-model="token"
                        label="Entre com seu token Github"
                        type="password"
                    />

                    <v-btn
                        block
                        color="primary"
                        class="mt-6"
                        :loading="loading"
                        @click="submit"
                    >
                        Entrar
                    </v-btn>
                </v-card>
            </v-container>
        </v-main>
    </v-app>
</template>

<style scoped>
.login-background {
    background: linear-gradient(135deg, #667eea, #764ba2);
    min-height: 100vh;
    padding: 0;
}

.v-card {
    background: #ffffffee;
}

.v-card-title {
    color: #333;
    font-family: 'Figtree', sans-serif;
}

.v-text-field input {
    font-size: 14px;
}

.v-btn {
    font-weight: 600;
}
</style>
