<?php

namespace App\Services\FCH\Common\PreQualification\AUE;

/**
 * Which prompt produced a stored evaluation.
 *
 * Once the instructions live on an OpenAI agent rather than in this codebase,
 * the constant here is no longer evidence of what actually ran: a host can edit
 * the agent, or point the step at a different one, without a deploy. So the
 * stored label is taken from the run itself.
 *
 * The response's own prompt_version comes first, because an agent states the
 * version it is. Then the agent's name on the platform, for an agent whose
 * schema carries no such field. The constant on the using class is the last
 * resort — correct only for a run that used the instructions shipped with it.
 */
trait ResolvesAgentPromptVersion
{
    /**
     * @param array $data   The decoded model output.
     * @param array $result The call envelope, which carries the agent's name
     *                      as prompt_version when an agent ran.
     */
    public static function promptVersionFor(array $data, array $result): string
    {
        foreach ([$data['prompt_version'] ?? null, $result['prompt_version'] ?? null] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return static::PROMPT_VERSION;
    }
}
