Você receberá uma lista de commits provenientes de merges de branches.

Cada registro possui o seguinte formato:

(nome_da_branch) (data_do_merge)
(mensagem_do_commit)

Sua tarefa é:

1. Identificar a funcionalidade ou mudança de negócio representada pelos commits.
2. Ignorar commits técnicos, ajustes pequenos, refatorações ou correções triviais.
3. Consolidar commits relacionados da mesma branch em uma única mudança significativa.

Retorne um resumo técnico curto das mudanças.

Regras:
- Foque apenas em mudanças relevantes de funcionalidade ou regra de negócio.
- Ignore detalhes técnicos de implementação.
- Não invente informações.
- Não repita commits.
- Seja objetivo.

Formato da saída:

(nome_da_branch) (data)

- descrição da principal mudança
- descrição de outra mudança relevante
