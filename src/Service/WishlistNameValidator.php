<?php

declare(strict_types=1);

namespace Summit\Wishlist\Service;

use Validate;

/**
 * Wishlist name rules, applied the same way to create, rename, the picker's inline create
 * and the guest-claim collision rename.
 * - Unicode-aware trim and collapse of inner whitespace.
 * - Required (blank or whitespace-only is rejected).
 * - New and renamed names: at most MAX_LENGTH characters. Stored legacy names (up to the
 *   128-character column) are never truncated or rewritten.
 * - Unique per owner/shop, ignoring case and surrounding whitespace. Checked under the owner
 *   lock by the caller, so concurrent creates can't both pass.
 */
class WishlistNameValidator
{
    public const MAX_LENGTH = 64;
    public const MAX_LISTS = 20;
    private const COLUMN_LENGTH = 128;

    public function normalize(string $name): string
    {
        $name = (string) preg_replace('/[\s\p{Z}]+/u', ' ', $name);

        return (string) preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $name);
    }

    /**
     * Comparison key: normalized and case-folded.
     */
    public function key(string $name): string
    {
        return mb_strtolower($this->normalize($name), 'UTF-8');
    }

    /**
     * @param array[] $existingLists the owner's lists (each with id_wishlist and name)
     *
     * @return string|null error code (name_required, name_too_long, name_invalid, name_taken) or null when valid
     */
    public function validate(string $normalized, array $existingLists, ?int $exceptId = null): ?string
    {
        if ($normalized === '') {
            return 'name_required';
        }
        if (mb_strlen($normalized, 'UTF-8') > self::MAX_LENGTH) {
            return 'name_too_long';
        }
        if (!Validate::isGenericName($normalized)) {
            return 'name_invalid';
        }
        $key = $this->key($normalized);
        foreach ($existingLists as $list) {
            if ($exceptId !== null && (int) $list['id_wishlist'] === $exceptId) {
                continue;
            }
            if ($this->key((string) $list['name']) === $key) {
                return 'name_taken';
            }
        }

        return null;
    }

    /**
     * A name that doesn't clash with $takenNames: "Birthday" → "Birthday (2)", "Birthday (3)"...
     * Used when a claimed guest list meets an account list of the same name (names are not
     * identity, so lists are never merged by name). The base is shortened only if the suffix
     * would overflow the column; the stored original is otherwise kept verbatim.
     *
     * @param string[] $takenNames
     */
    public function uniqueName(string $name, array $takenNames): string
    {
        $taken = array_flip(array_map([$this, 'key'], $takenNames));
        if (!isset($taken[$this->key($name)])) {
            return $name;
        }
        for ($i = 2; ; ++$i) {
            $suffix = ' (' . $i . ')';
            $base = mb_substr($name, 0, self::COLUMN_LENGTH - mb_strlen($suffix, 'UTF-8'), 'UTF-8');
            $candidate = $base . $suffix;
            if (!isset($taken[$this->key($candidate)])) {
                return $candidate;
            }
        }
    }
}
