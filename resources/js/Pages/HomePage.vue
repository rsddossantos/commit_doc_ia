<script setup>
import { ref, reactive, computed, watch, nextTick } from 'vue'
import { router } from '@inertiajs/vue3'
import axios from 'axios'
import SearchInput from "@/Components/SearchInput.vue"

const selectedRepo = ref(null)
const search = ref('')
const isProcessing = ref(false)
const isGenerating = ref(false)
const isGeneratingChangelog = ref(false)
const errorMessage = ref('')
const documentation = ref('')
const changelog = ref('')
const commitData = ref(null)

const props = defineProps({
    repos: {
        type: Array,
        default: () => []
    },
    user: {
        type: Object,
        default: () => ({})
    }
})

const repos = reactive(props.repos || [])

const primaryOwner = computed(() => {
    const counts = new Map()
    for (const repo of repos) {
        const owner = repo.owner
        if (!owner) continue
        counts.set(owner, (counts.get(owner) || 0) + 1)
    }
    if (counts.size === 0) return '-'
    return [...counts.entries()].sort((a, b) => b[1] - a[1])[0][0]
})

watch(errorMessage, async (val) => {
    if (!val) return

    await nextTick()

    window.scrollTo({
        top: document.body.scrollHeight,
        behavior: 'smooth'
    })
})

const filteredRepos = computed(() => {
    if (!search.value) return repos
    return repos.filter(repo =>
        repo.name.toLowerCase().includes(search.value.toLowerCase())
    )
})

const currentPage = ref(1)
const perPage = 12

const totalPages = computed(() =>
    Math.ceil(filteredRepos.value.length / perPage)
)

const pagedRepos = computed(() => {
    const start = (currentPage.value - 1) * perPage
    return filteredRepos.value.slice(start, start + perPage)
})

watch(search, () => currentPage.value = 1)

function toggleRepo(repo) {
    if (selectedRepo.value?.name === repo.name) {
        selectedRepo.value = null
    } else {
        selectedRepo.value = repo
    }
    resetState()
}

function resetState() {
    errorMessage.value = ''
    documentation.value = ''
    changelog.value = ''
    commitData.value = null
}

function logout() {
    router.post('/logout')
}

function nextPage() {
    if (currentPage.value < totalPages.value) currentPage.value++
}

function prevPage() {
    if (currentPage.value > 1) currentPage.value--
}

async function processRepo() {
    if (!selectedRepo.value) return

    resetState()
    isProcessing.value = true

    try {
        const response = await axios.post('/process-main', {
            owner: selectedRepo.value.owner,
            repo: selectedRepo.value.name,
            default_branch: selectedRepo.value.default_branch
        })

        commitData.value = response.data

    } catch (e) {
        const message = e.response?.data?.message || 'Erro não mapeado'
        const details = e.response?.data?.details

        errorMessage.value = details
            ? `${message}: ${details}`
            : message
    } finally {
        isProcessing.value = false
    }
}

async function generateDocumentation() {
    if (!commitData.value?.commits?.length) return

    errorMessage.value = ''
    documentation.value = ''
    changelog.value = ''
    isGenerating.value = true

    try {
        const response = await axios.post('/generate-documentation', {
            branch: commitData.value.branch,
            commits: commitData.value.commits
        })

        documentation.value = response.data.documentation

    } catch (e) {
        const message = e.response?.data?.message || 'Erro não mapeado'
        const details = e.response?.data?.details

        errorMessage.value = details
            ? `${message}\n${details}`
            : message
    } finally {
        isGenerating.value = false
    }
}

async function generateChangelog() {
    if (!commitData.value?.commits?.length) return

    errorMessage.value = ''
    documentation.value = ''
    changelog.value = ''
    isGeneratingChangelog.value = true

    try {
        const response = await axios.post('/generate-changelog', {
            branch: commitData.value.branch,
            commits: commitData.value.commits
        })

        changelog.value = response.data.changelog

    } catch (e) {
        const message = e.response?.data?.message || 'Erro não mapeado'
        const details = e.response?.data?.details

        errorMessage.value = details
            ? `${message}\n${details}`
            : message
    } finally {
        isGeneratingChangelog.value = false
    }
}
</script>

<template>
    <v-app>
        <v-toolbar color="primary">
            <v-toolbar-title class="font-weight-bold white--text">
                CommitDoc AI
            </v-toolbar-title>

            <v-spacer />

            <v-menu location="bottom end">
                <template #activator="{ props }">
                    <v-btn v-bind="props" icon>
                        <v-icon>mdi-account</v-icon>
                    </v-btn>
                </template>

                <v-list>
                    <v-list-item disabled>
                        <v-list-item-title>
                            Owner: {{ primaryOwner }}
                        </v-list-item-title>
                    </v-list-item>

                    <v-divider />

                    <v-list-item disabled>
                        <v-list-item-title>
                            Login: {{ props.user?.login }}
                        </v-list-item-title>
                    </v-list-item>

                    <v-list-item disabled>
                        <v-list-item-title>
                            User: {{ props.user?.name || '-' }}
                        </v-list-item-title>
                    </v-list-item>

                    <v-divider />

                    <v-list-item @click="logout">
                        <v-list-item-title>
                            <v-icon size="small" class="mr-2">mdi-logout</v-icon>
                            Sair
                        </v-list-item-title>
                    </v-list-item>
                </v-list>
            </v-menu>
        </v-toolbar>

        <v-main>
            <v-container fluid class="custom-container">

                <SearchInput
                    v-model="search"
                    placeholder="Pesquisar repositórios"
                    class="mt-12"
                />

                <v-row dense class="mt-6">
                    <v-col cols="12" md="4"
                           v-for="repo in pagedRepos"
                           :key="repo.name"
                    >
                        <v-card
                            class="repo-card"
                            :class="{ 'selected-repo': selectedRepo?.name === repo.name }"
                            @click="toggleRepo(repo)"
                        >
                            <v-card-title>
                                {{ repo.name }}
                            </v-card-title>
                        </v-card>
                    </v-col>
                </v-row>

                <v-row class="mt-6" align="center">
                    <v-col cols="6" class="d-flex justify-start">
                        <v-btn
                            outlined
                            @click="prevPage"
                            :disabled="currentPage === 1"
                        >
                            Anterior
                        </v-btn>
                    </v-col>

                    <v-col cols="6" class="d-flex justify-end">
                        <v-btn
                            outlined
                            @click="nextPage"
                            :disabled="currentPage === totalPages"
                        >
                            Próxima
                        </v-btn>
                    </v-col>
                </v-row>

                <v-row class="mt-12" v-if="selectedRepo && !commitData">
                    <v-col cols="12" class="d-flex justify-center">
                        <v-btn
                            color="primary"
                            size="large"
                            block
                            :loading="isProcessing"
                            @click="processRepo"
                        >
                            PROCESSAR
                        </v-btn>
                    </v-col>
                </v-row>

                <v-row class="mt-6" v-if="commitData">
                    <v-col cols="12">
                        <v-alert type="info" variant="tonal">
                            <div>
                                Repositório: <strong>{{ commitData.repo }}</strong>
                            </div>
                            <div>
                                Branch: <strong>{{ commitData.branch }}</strong>
                            </div>
                            <div>
                                {{ commitData.total_commits }} commits encontrados.
                            </div>
                        </v-alert>
                    </v-col>
                </v-row>

                <v-row class="mt-6" v-if="commitData">
                    <v-col cols="12" md="6">
                        <v-btn
                            color="primary"
                            block
                            :loading="isGenerating"
                            :disabled="isGeneratingChangelog"
                            @click="generateDocumentation"
                        >
                            GERAR DOCUMENTAÇÃO
                        </v-btn>
                    </v-col>

                    <v-col cols="12" md="6">
                        <v-btn
                            color="primary"
                            block
                            :loading="isGeneratingChangelog"
                            :disabled="isGenerating"
                            @click="generateChangelog"
                        >
                            GERAR CHANGELOG
                        </v-btn>
                    </v-col>
                </v-row>

                <v-progress-linear
                    v-if="isGenerating || isGeneratingChangelog"
                    indeterminate
                    color="primary"
                    height="10"
                    class="mt-8 mb-6"
                />

                <v-row v-if="errorMessage" class="mt-6">
                    <v-col cols="12">
                        <v-alert type="error" variant="tonal" style="white-space: pre-wrap;">
                            {{ errorMessage }}
                        </v-alert>
                    </v-col>
                </v-row>

                <v-row class="mt-6" v-if="documentation">
                    <v-col cols="12">
                        <v-card>
                            <v-card-title>
                                Documentação Sintetizada via IA Cohere
                            </v-card-title>
                            <v-card-text style="white-space: pre-line;">
                                {{ documentation }}
                            </v-card-text>
                        </v-card>
                    </v-col>
                </v-row>

                <v-row class="mt-6" v-if="changelog">
                    <v-col cols="12">
                        <v-card>
                            <v-card-title>
                                Changelog via IA Cohere
                            </v-card-title>
                            <v-card-text style="white-space: pre-line;">
                                {{ changelog }}
                            </v-card-text>
                        </v-card>
                    </v-col>
                </v-row>

            </v-container>
        </v-main>
    </v-app>
</template>

<style scoped>
.custom-container {
    padding: 0 200px 70px 200px;
}

.selected-repo {
    background-color: rgba(0,123,255,0.2) !important;
}

.repo-card {
    cursor: pointer;
    transition: 0.2s;
}

@media (max-width: 960px) {
    .custom-container {
        padding: 15px;
    }
}
</style>
