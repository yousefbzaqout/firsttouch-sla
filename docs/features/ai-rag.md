# AI RAG (pgvector)

Tenant knowledge bases with confidence-gated answers. Below threshold → human-only; credits gate inferences.

## Code map

- `app/Filament/Resources/` Knowledge Base resources
- `app/Services/` vector / embedding search
- `database/migrations/*pgvector*` / `knowledge_chunks`
- `app/Jobs/ProcessAiResponseJob.php`
