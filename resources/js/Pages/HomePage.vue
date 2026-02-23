<script setup>
import { ref, reactive, computed, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import axios from 'axios'
import SearchInput from "@/Components/SearchInput.vue";

const selectedBranch = ref(null)
const branchSearch = reactive({})
const activePanel = ref(null)
const search = ref('')
const isProcessing = ref(false)
const isGeneratingDoc = ref(false)
const errorMessage = ref('')
const docErrorMessage = ref('')
const totals = ref(null)
const commits = ref([])
const documentation = ref('')

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
const repos = reactive(props.repos || []);
const displayName = computed(() => props.user?.name || props.user?.login || 'Usuário')
const primaryOwner = computed(() => {
    const counts = new Map()
    for (const repo of repos) {
        const owner = repo.owner
        if (!owner) continue
        counts.set(owner, (counts.get(owner) || 0) + 1)
    }
    if (counts.size === 0) return '-'

    const userLogin = props.user?.login
    let primary = null
    let max = -1
    for (const [owner, count] of counts.entries()) {
        if (owner === userLogin) continue
        if (count > max) {
            max = count
            primary = owner
        }
    }
    if (primary) return primary

    for (const [owner, count] of counts.entries()) {
        if (count > max) {
            max = count
            primary = owner
        }
    }
    return primary || '-'
})

const filteredRepos = computed(() => {
    if (!search.value) return repos
    return repos.filter(repo =>
        repo.name.toLowerCase().includes(search.value.toLowerCase())
    )
})

const currentPage = ref(1)
const perPage = 12

const totalPages = computed(() => Math.ceil(filteredRepos.value.length / perPage))
const pagedRepos = computed(() => {
    const start = (currentPage.value - 1) * perPage
    return filteredRepos.value.slice(start, start + perPage)
})

watch(search, () => {
    currentPage.value = 1
})

function selectBranch(owner, repoName, branchName, isPrimary) {
    selectedBranch.value = { owner, repo: repoName, branch: branchName, isPrimary: !!isPrimary }
    errorMessage.value = ''
    docErrorMessage.value = ''
    totals.value = null
    commits.value = []
    documentation.value = ''
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

async function processBranch() {
    if (!selectedBranch.value || isProcessing.value) return
    activePanel.value = null
    isProcessing.value = true
    errorMessage.value = ''
    docErrorMessage.value = ''
    totals.value = null
    commits.value = []
    documentation.value = ''

    try {
        const { data } = await axios.post('/commits', {
            owner: selectedBranch.value.owner,
            repo: selectedBranch.value.repo,
            branch: selectedBranch.value.branch,
        })
        totals.value = {
            total: data.total ?? 0,
        }
        commits.value = Array.isArray(data.commits) ? data.commits : []
        console.log('Commits carregados', data)
    } catch (error) {
        errorMessage.value = error?.response?.data?.message || 'Erro ao processar commits.'
        console.error(error)
    } finally {
        isProcessing.value = false
    }
}

async function generateDocumentation() {
    if (!selectedBranch.value || isGeneratingDoc.value || commits.value.length === 0) return

    isGeneratingDoc.value = true
    docErrorMessage.value = ''
    documentation.value = ''

    try {
        const { data } = await axios.post('/documentation', {
            owner: selectedBranch.value.owner,
            repo: selectedBranch.value.repo,
            branch: selectedBranch.value.branch,
            commits: commits.value,
        })
        documentation.value = data.documentation || ''
    } catch (error) {
        docErrorMessage.value = error?.response?.data?.message || 'Erro ao gerar documentação.'
        console.error(error)
    } finally {
        isGeneratingDoc.value = false
    }
}

function clearSearch() {
    search.value = ''
}

function getFilteredBranches(repo) {
    const term = branchSearch[repo.name]?.toLowerCase()
    return repo.branches.filter(branch => !term || branch.name.toLowerCase().includes(term))
}
</script>

<template>
    <v-app>
        <v-toolbar color="primary" dark elevation="2" height="64">
            <v-toolbar-title class="font-weight-bold white--text">CommitDoc AI</v-toolbar-title>
            <v-spacer></v-spacer>
            <v-menu>
                <template #activator="{ props: menuProps }">
                    <v-btn v-bind="menuProps" variant="text" class="text-none user-menu-btn">
                        <v-icon start>mdi-account</v-icon>
                        {{ displayName }}
                        <v-icon end>mdi-menu-down</v-icon>
                    </v-btn>
                    <v-btn v-bind="menuProps" icon class="user-menu-btn--compact">
                        <v-icon>mdi-account</v-icon>
                    </v-btn>
                </template>
                <v-list>
                    <v-list-item disabled>
                        <v-list-item-title class="font-weight-bold">
                            Organização: {{ primaryOwner }}
                        </v-list-item-title>
                    </v-list-item>
                    <v-divider />
                    <v-list-item disabled>
                        <v-list-item-title>Login: {{ props.user?.login || '-' }}</v-list-item-title>
                    </v-list-item>
                    <v-list-item disabled>
                        <v-list-item-title>Nome: {{ props.user?.name || '-' }}</v-list-item-title>
                    </v-list-item>
                </v-list>
            </v-menu>
            <v-btn icon @click="logout">
                <v-icon>mdi-logout</v-icon>
            </v-btn>
        </v-toolbar>

        <v-main>
            <v-container fluid class="custom-container">
                <SearchInput
                    v-model="search"
                    placeholder="Pesquisar repositórios"
                    class="mt-12"
                />

                <v-row dense class="mt-6">
                    <v-col cols="12" md="4" v-for="(repo, index) in pagedRepos" :key="repo.name">
                        <v-expansion-panels v-model="activePanel">
                            <v-expansion-panel :value="index + (currentPage - 1) * perPage">
                                <v-expansion-panel-title
                                    class="font-weight-bold"
                                    :class="{ 'active-hover': selectedBranch?.repo === repo.name }"
                                >
                                    {{ repo.name }}
                                </v-expansion-panel-title>
                                <v-expansion-panel-text>
                                    <SearchInput
                                        v-model="branchSearch[repo.name]"
                                        placeholder="Pesquisar branches"
                                        wrapperClass="mb-4"
                                        class="mb-2"
                                    />
                                    <v-list dense class="branch-panel">
                                        <v-list-item
                                            v-for="branch in getFilteredBranches(repo)"
                                            :key="branch.name"
                                            @click="selectBranch(repo.owner, repo.name, branch.name, branch.is_primary)"
                                            :class="{ 'selected-branch': selectedBranch?.repo === repo.name && selectedBranch?.branch === branch.name }"
                                        >
                                            <v-list-item-title>
                                                <span :class="{ 'branch-primary-name': branch.is_primary }">
                                                    {{ branch.name }}
                                                </span>
                                            </v-list-item-title>
                                        </v-list-item>
                                    </v-list>
                                </v-expansion-panel-text>
                            </v-expansion-panel>
                        </v-expansion-panels>
                    </v-col>
                </v-row>

                <v-row class="mt-6 justify-center" align="center" style="gap: 2px;">
                    <v-btn outlined @click="prevPage" :disabled="currentPage === 1">Anterior</v-btn>
                    <v-btn outlined @click="nextPage" :disabled="currentPage === totalPages">Próxima</v-btn>
                </v-row>

                <v-row class="mt-12" v-if="!totals">
                    <v-col cols="12">
                        <v-btn
                            :disabled="!selectedBranch || isProcessing"
                            :loading="isProcessing"
                            color="primary"
                            large
                            block
                            @click="processBranch"
                        >
                            PROCESSAR
                        </v-btn>
                    </v-col>
                </v-row>

                <v-row class="mt-12" v-if="errorMessage || totals">
                    <v-col cols="12">
                        <v-alert v-if="errorMessage" type="error" variant="tonal">
                            {{ errorMessage }}
                        </v-alert>
                        <v-alert v-else type="success" variant="tonal">
                            {{ totals.total }} commits carregados.
                        </v-alert>
                    </v-col>
                </v-row>

                <v-row class="mt-8" v-if="totals">
                    <v-col cols="12">
                        <v-btn
                            color="primary"
                            large
                            block
                            :loading="isGeneratingDoc"
                            :disabled="commits.length === 0 || isGeneratingDoc"
                            @click="generateDocumentation"
                        >
                            GERAR DOCUMENTACAO
                        </v-btn>
                    </v-col>
                </v-row>

                <v-row class="mt-6" v-if="docErrorMessage || documentation">
                    <v-col cols="12">
                        <v-alert v-if="docErrorMessage" type="error" variant="tonal">
                            {{ docErrorMessage }}
                        </v-alert>
                        <v-card v-else class="documentation-card" elevation="2">
                            <v-card-title class="text-h6">Documentação Sintetizada via IA Cohere</v-card-title>
                            <v-card-text class="documentation-text">
                                {{ documentation }}
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
    padding: 15px 200px;
}

@media (max-width: 960px) {
    .custom-container {
        padding: 15px 15px;
    }
}

.selected-branch {
    background-color: rgba(0, 123, 255, 0.2) !important;
}

.v-list-item {
    cursor: pointer;
    transition: background 0.2s;
}

.v-list-item:hover {
    background-color: rgba(0, 123, 255, 0.1);
}

.v-expansion-panel-title:hover,
.active-hover {
    background-color: rgba(0, 123, 255, 0.1);
    transition: background 0.2s;
}

.branch-panel {
    max-height: 400px;
    overflow-y: auto;
}

.branch-primary-name {
    font-weight: 700;
}

.documentation-text {
    white-space: pre-wrap;
}

@media (max-width: 580px) {
    .user-menu-btn {
        display: none;
    }

    .user-menu-btn--compact {
        display: inline-flex;
    }
}

@media (min-width: 581px) {
    .user-menu-btn--compact {
        display: none;
    }
}

</style>


