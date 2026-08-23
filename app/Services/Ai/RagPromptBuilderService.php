<?php

declare(strict_types=1);

namespace App\Services\Ai;

use Illuminate\Support\Collection;
use stdClass;

class RagPromptBuilderService
{
    /**
     * @param  Collection<int, stdClass&object{id: string, content: string, priority: string, knowledge_base_id: string, similarity: float}>  $chunks
     */
    public function build(Collection $chunks): string
    {
        return $this->buildQualificationPrompt($chunks);
    }

    /**
     * @param  Collection<int, stdClass&object{id: string, content: string, priority: string, knowledge_base_id: string, similarity: float}>  $chunks
     */
    public function buildQualificationPrompt(Collection $chunks): string
    {
        $context = $chunks->map(fn (stdClass $chunk): string => $chunk->content)->implode("\n\n---\n\n");

        return <<<PROMPT
            You are an internal sales AI copilot for FirstTouch SLA.
            You never message the customer directly. You only analyze leads and prepare help for human sales reps.

            Use the company knowledge context below (tenant-scoped catalog/services/FAQs) to decide fit and draft replies.

            ## Company knowledge context:
            {$context}

            ## Output rules:
            - Respond with a single JSON object only (no markdown fences, no prose outside JSON).
            - Schema:
              {
                "qualification_score": <number 0.0 to 1.0>,
                "qualification_summary": "<short intent/fit summary for the sales rep>",
                "is_qualified": <true|false>,
                "suggested_reply": "<primary first-touch sales reply>",
                "routing_tags": ["<tag1>", "<tag2>", "<optional tag3>"],
                "quick_replies": [
                  {"label": "<short button label max 40 chars>", "text": "<full message the rep can send>"},
                  {"label": "<short button label>", "text": "<full alternative message>"},
                  {"label": "<optional third label>", "text": "<optional third full message>"}
                ]
              }
            - routing_tags MUST contain 1 to 3 short tags summarizing the lead's needs for agent matching
              (examples: language like "English"/"Arabic", budget like "VIP"/"Budget", product like "Technical"/"Ads").
            - Prefer concise, reusable tags a sales manager would assign as agent skills (Title Case words).
            - When is_qualified is true, quick_replies MUST contain 2 or 3 tailored options based on the lead industry, language, and inquiry (e.g. Book Call, Send Pricing, Ask Qualification Question).
            - Each quick_replies.label must be concise for Telegram buttons (e.g. "Reply 1: Book Call").
            - Each quick_replies.text must be a complete, ready-to-send customer message in the lead's likely language.
            - When is_qualified is false, return quick_replies as an empty array [].
            - qualification_score reflects lead seriousness and fit with the company services in context.
            - Do not invent prices, policies, or services that are not in the context.
            PROMPT;
    }
}
