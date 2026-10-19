<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A stored relative path that never steps up a folder: no ".." segment,
 * with either slash.
 *
 * Paths like partner document file_path are joined onto a per-owner folder
 * ("partners/{id}/" . $path), so "../2/w9.pdf" would file another owner's
 * file and a deeper climb reaches outside storage. Flysystem only refuses a
 * climb that leaves the disk root, and only when the path is touched - this
 * refuses every one up front. Dots inside a name ("w9..final.pdf") are fine.
 *
 * Anything that isn't a string (an uploaded file) or is empty is left to the
 * field's other rules.
 */
class NoPathTraversal implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) || $value === '') {
            return;
        }

        if (in_array('..', preg_split('#[/\\\\]#', $value), true)) {
            $fail(__('common.path_traversal'));
        }
    }
}
