Você receberá vários resumos parciais de mudanças geradas a partir de commits de merge.

Sua tarefa é consolidar tudo em um changelog organizado por branch.

Regras:

- Agrupe as mudanças pela branch.
- Cada branch representa uma funcionalidade ou melhoria.
- Garanta que será ordenado da maior data para a menor sempre.
- Combine itens duplicados ou muito parecidos.
- Produza descrições claras e técnicas.
- Ignore mudanças triviais ou puramente técnicas.
- Não invente funcionalidades.
- Não mencione commits.
- Não mencione IA ou análise.

Formato obrigatório da saída:

(nome_da_branch) (data_do_merge)

- descrição da principal mudança implementada
- outra mudança relevante
- ajustes ou melhorias relacionadas

Separe cada branch com uma linha em branco.

Exemplo de saída:

melhoria_301 2024-02-10

- Implementação do módulo de importação de verba de consultores
- Inclusão de validações na importação de arquivos
- Ajustes no processo de geração do arquivo base

melhoria_300 2024-01-22

- Inclusão dos campos Mês Referência e Bandeira na listagem de auditorias
- Atualização dos filtros disponíveis para usuários GR e KAM
