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
                        v-if="loading"
                        type="info"
                        class="mb-4"
                        border="left"
                    >
                        Coletando todos os repos e branches, isso pode demorar...
                    </v-alert>
                    <v-alert
                        v-else-if="$page.props.error"
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
                        class="login-token-field"
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

.v-text-field input {
    font-size: 14px;
    border-radius: 0 !important;
}

:deep(.login-token-field .v-field__overlay),
:deep(.login-token-field .v-field),
:deep(.login-token-field .v-field__outline),
:deep(.login-token-field .v-field__outline__start),
:deep(.login-token-field .v-field__outline__end),
:deep(.login-token-field .v-field__outline__notch) {
    border-radius: 0 !important;
}
</style>
