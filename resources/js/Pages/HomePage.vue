<script setup>
import { ref, reactive, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import SearchInput from "@/Components/SearchInput.vue";

const darkMode = ref(false)
const selectedBranch = ref(null)
const branchSearch = reactive({})
const activePanel = ref(null)
const search = ref('')

const props = defineProps({
    repos: {
        type: Array,
        default: () => []
    }
})
const repos = reactive(props.repos || []);

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

function toggleTheme() {
    darkMode.value = !darkMode.value
    document.body.classList.toggle('v-theme--dark', darkMode.value)
}

function selectBranch(repoName, branchName) {
    selectedBranch.value = { repo: repoName, branch: branchName }
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

function processBranch() {
    if (!selectedBranch.value) return
    console.log('Processando', selectedBranch.value)
}

function clearSearch() {
    search.value = ''
}
</script>

<template>
    <v-app :class="{ 'v-theme--dark': darkMode }">
        <v-toolbar color="primary" dark elevation="2" height="64">
            <v-toolbar-title class="font-weight-bold white--text">CommitDoc AI</v-toolbar-title>
            <v-spacer></v-spacer>
            <v-btn icon @click="toggleTheme">
                <v-icon>mdi-weather-sunny</v-icon>
            </v-btn>
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
                                    />
                                    <v-list dense>
                                        <v-list-item
                                            v-for="branch in repo.branches.filter(b => !branchSearch[repo.name] || b.toLowerCase().includes(branchSearch[repo.name].toLowerCase()))"
                                            :key="branch"
                                            @click="selectBranch(repo.name, branch)"
                                            :class="{ 'selected-branch': selectedBranch?.repo === repo.name && selectedBranch?.branch === branch }"
                                        >
                                            <v-list-item-title>{{ branch }}</v-list-item-title>
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

                <v-row class="mt-12">
                    <v-col cols="12">
                        <v-btn
                            :disabled="!selectedBranch"
                            color="primary"
                            large
                            block
                            @click="processBranch"
                        >
                            PROCESSAR
                        </v-btn>
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

</style>
